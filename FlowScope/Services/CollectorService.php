<?php

namespace Plugin\Flowscope\Services;

use App\Models\ServerMachine;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Plugin\Flowscope\Models\CollectorCursor;
use Plugin\Flowscope\Models\MachineDaily;

final class CollectorService
{
    public const MAX_INTERVAL = 31622400;
    public const STALE_SECONDS = 180;

    public function __construct(private QuotaService $bytes) {}

    public function accept(array $report, ?string $ipv4 = null): array
    {
        return DB::transaction(function () use ($report, $ipv4): array {
            // Lock the existing native identity (read only). This also serializes
            // first binding and Admin reset when no plugin cursor exists yet.
            $machine = ServerMachine::query()->whereKey($report['machine_id'])->lockForUpdate()->first();
            if (!$machine || !$machine->is_active || !hash_equals((string) $machine->token, $report['token'])) {
                abort(403, 'Invalid machine credentials.');
            }
            $cursor = CollectorCursor::query()->where('machine_id', $machine->id)->lockForUpdate()->first();
            $canonical = $report;
            unset($canonical['token']);
            ksort($canonical);
            $digest = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($cursor) {
                if (!hash_equals($cursor->collector_id, $report['collector_id'])) {
                    $this->conflict('A different collector is bound to this machine; an Admin must explicitly reset it.');
                }
                $comparison = $this->bytes->compare($report['sequence'], $cursor->sequence);
                if ($comparison <= 0) {
                    if ($comparison === 0 && !hash_equals($cursor->last_digest, $digest)) {
                        $this->conflict('The latest sequence was already accepted with different content.');
                    }
                    app(AddressService::class)->accept((int) $machine->id, $ipv4, 'collector');
                    return ['accepted' => true, 'sequence' => $report['sequence'], 'duplicate' => true];
                }
                $expected = $this->bytes->add($cursor->sequence, '1');
                if ($report['sequence'] !== $expected) {
                    $this->conflict('Collector sequence gap.', ['expected_sequence' => $expected]);
                }
                if ($report['started_at'] !== $cursor->last_sample_at) {
                    $this->conflict('Collector interval must start at the last accepted interval end.', ['expected_started_at' => $cursor->last_sample_at]);
                }
            } elseif ($report['sequence'] !== '1') {
                $this->conflict('An unbound collector must begin at sequence 1.', ['expected_sequence' => '1']);
            }

            $timezone = (string) config('app.timezone', date_default_timezone_get());
            foreach ($this->allocate($report['started_at'], $report['ended_at'], $report['rx_bytes'], $report['tx_bytes'], $timezone) as $bucket) {
                $daily = MachineDaily::query()->where('machine_id', $machine->id)->where('record_at', $bucket['record_at'])->lockForUpdate()->first();
                $daily ??= new MachineDaily(['machine_id' => $machine->id, 'record_at' => $bucket['record_at'], 'rx_bytes' => '0', 'tx_bytes' => '0', 'timezone' => $timezone]);
                $daily->rx_bytes = $this->bytes->add($daily->rx_bytes, $bucket['rx_bytes']);
                $daily->tx_bytes = $this->bytes->add($daily->tx_bytes, $bucket['tx_bytes']);
                $daily->save();
            }
            $cursor ??= new CollectorCursor(['machine_id' => $machine->id, 'first_sample_at' => $report['started_at'], 'resets' => 0, 'boot_changes' => 0]);
            $cursor->fill([
                'collector_id' => $report['collector_id'], 'sequence' => $report['sequence'], 'last_digest' => $digest,
                'last_sample_at' => $report['ended_at'], 'last_report_at' => time(), 'boot_id' => $report['boot_id'],
                'interfaces' => $report['interfaces'], 'resets' => $cursor->resets + $report['resets'],
                'boot_changes' => $cursor->boot_changes + ($report['boot_changed'] ? 1 : 0),
            ])->save();
            app(AddressService::class)->accept((int) $machine->id, $ipv4, 'collector');
            return ['accepted' => true, 'sequence' => $report['sequence'], 'duplicate' => false];
        }, 3);
    }

    /** Exact byte conservation; only placement across a boundary is prorated. */
    public function allocate(int $start, int $end, string $rx, string $tx, string $timezone): array
    {
        $zone = new DateTimeZone($timezone);
        $duration = $end - $start;
        $allocatedRx = '0';
        $allocatedTx = '0';
        $buckets = [];
        for ($position = $start; $position < $end; $position = $until) {
            $day = (new DateTimeImmutable('@'.$position))->setTimezone($zone)->setTime(0, 0);
            $until = min($end, $day->modify('+1 day')->getTimestamp());
            $last = $until === $end;
            $partRx = $last ? $this->bytes->add($rx, '-'.$allocatedRx) : $this->bytes->divide($this->bytes->multiply($rx, (string) ($until - $position)), $duration);
            $partTx = $last ? $this->bytes->add($tx, '-'.$allocatedTx) : $this->bytes->divide($this->bytes->multiply($tx, (string) ($until - $position)), $duration);
            $allocatedRx = $this->bytes->add($allocatedRx, $partRx);
            $allocatedTx = $this->bytes->add($allocatedTx, $partTx);
            $buckets[] = ['record_at' => $day->getTimestamp(), 'rx_bytes' => $partRx, 'tx_bytes' => $partTx];
        }
        return $buckets;
    }

    public static function metadata(?CollectorCursor $cursor, ?int $now = null): array
    {
        return [
            'id' => $cursor?->collector_id, 'sequence' => $cursor?->sequence,
            'status' => !$cursor ? 'waiting' : ((($now ?? time()) - $cursor->last_sample_at > self::STALE_SECONDS) ? 'stale' : 'active'),
            'last_report_at' => $cursor?->last_report_at, 'first_sample_at' => $cursor?->first_sample_at,
            'last_sample_at' => $cursor?->last_sample_at, 'interfaces' => $cursor?->interfaces ?? [],
            'resets' => $cursor?->resets ?? 0, 'boot_changes' => $cursor?->boot_changes ?? 0,
        ];
    }

    private function conflict(string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message] + $extra, 409));
    }
}
