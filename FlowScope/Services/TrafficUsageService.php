<?php

namespace Plugin\Flowscope\Services;

use App\Models\ServerMachine;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Plugin\Flowscope\Models\CollectorCursor;
use Plugin\Flowscope\Models\MachineDaily;
use Plugin\Flowscope\Models\MachineSetting;

final class TrafficUsageService
{
    public function __construct(private TrafficCycleService $cycles, private QuotaService $bytes) {}

    public function dashboard(?DateTimeInterface $at = null): array
    {
        $timezone = (string) config('app.timezone', date_default_timezone_get());
        $zone = new DateTimeZone($timezone);
        $now = $at ? DateTimeImmutable::createFromInterface($at)->setTimezone($zone) : new DateTimeImmutable('now', $zone);
        $settings = MachineSetting::query()->where('enabled', true)->get();
        $ids = $settings->pluck('machine_id')->all();
        $machines = ServerMachine::query()->whereIn('id', $ids)->get(['id', 'name', 'is_active'])->keyBy('id');
        $cursors = CollectorCursor::query()->whereIn('machine_id', $ids)->get()->keyBy('machine_id');
        $entries = [];
        foreach ($settings as $setting) {
            $machine = $machines->get($setting->machine_id);
            if (!$machine) { continue; } // Never remap deleted identities by name or child nodes.
            $cycle = $this->cycles->calculate($setting->cycle_start_day, $now, $timezone);
            $collector = CollectorService::metadata($cursors->get($machine->id), $now->getTimestamp());
            $entries[$machine->id] = [
                'machine_id' => (int) $machine->id,
                'name' => $setting->display_name !== null && trim($setting->display_name) !== '' ? $setting->display_name : $machine->name,
                'is_active' => (bool) $machine->is_active,
                'traffic_mode' => $setting->traffic_mode, 'quota_bytes' => $this->bytes->normalizeBytes($setting->quota_bytes),
                'cycle_start_day' => $setting->cycle_start_day,
                'cycle_start' => $cycle['start']->format(DATE_ATOM), 'cycle_end' => $cycle['end']->format(DATE_ATOM),
                'cycle_end_exclusive' => $cycle['end_exclusive']->format(DATE_ATOM),
                'traffic_source' => 'nic', 'allocation' => 'interval_prorated', 'data_quality' => 'sampled',
                'collector_status' => $collector['status'], 'last_report_at' => $collector['last_report_at'],
                'first_sample_at' => $collector['first_sample_at'], 'last_sample_at' => $collector['last_sample_at'],
                'collector' => $collector, 'inbound_bytes' => '0', 'outbound_bytes' => '0',
                '_start' => $cycle['start']->getTimestamp(), '_end' => $cycle['end_exclusive']->getTimestamp(),
            ];
        }
        if ($entries !== []) {
            $start = min(array_column($entries, '_start'));
            $end = min(max(array_column($entries, '_end')), $now->setTime(0, 0)->modify('+1 day')->getTimestamp());
            // Do not SUM in SQL: daily/lifetime usage may exceed signed BIGINT.
            $rows = MachineDaily::query()->whereIn('machine_id', array_keys($entries))
                ->where('record_at', '>=', $start)->where('record_at', '<', $end)
                ->get(['machine_id', 'record_at', 'rx_bytes', 'tx_bytes']);
            foreach ($rows as $row) {
                if (!isset($entries[$row->machine_id]) || $row->record_at < $entries[$row->machine_id]['_start'] || $row->record_at >= $entries[$row->machine_id]['_end']) { continue; }
                $entries[$row->machine_id]['inbound_bytes'] = $this->bytes->add($entries[$row->machine_id]['inbound_bytes'], $row->rx_bytes);
                $entries[$row->machine_id]['outbound_bytes'] = $this->bytes->add($entries[$row->machine_id]['outbound_bytes'], $row->tx_bytes);
            }
        }
        foreach ($entries as &$entry) {
            $entry['used_bytes'] = match ($entry['traffic_mode']) {
                'inbound' => $entry['inbound_bytes'], 'outbound' => $entry['outbound_bytes'],
                'both' => $this->bytes->add($entry['inbound_bytes'], $entry['outbound_bytes']),
            };
            $entry['percentage'] = round((float) $entry['used_bytes'] / (float) $entry['quota_bytes'] * 100, 2);
            $entry['progress_percentage'] = min(100, max(0, $entry['percentage']));
            $entry['over_quota'] = $this->bytes->compare($entry['used_bytes'], $entry['quota_bytes']) > 0;
            unset($entry['_start'], $entry['_end']);
        }
        unset($entry);
        $entries = array_values($entries);
        usort($entries, function (array $a, array $b): int {
            $ratio = $this->bytes->compare($this->bytes->multiply($b['used_bytes'], $a['quota_bytes']), $this->bytes->multiply($a['used_bytes'], $b['quota_bytes']));
            return $ratio ?: strcmp($a['name'], $b['name']) ?: $a['machine_id'] <=> $b['machine_id'];
        });
        return ['servers' => $entries, 'timezone' => $timezone, 'generated_at' => $now->format(DATE_ATOM),
            'traffic_source' => 'nic', 'allocation' => 'interval_prorated'];
    }
}
