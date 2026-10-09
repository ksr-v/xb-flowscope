<?php
declare(strict_types=1);

namespace Plugin\Flowscope\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Server;
use App\Models\ServerMachine;

/** Both authenticated producers enter here; callers hold the native machine/node lock. */
final class AddressService
{
    public const NODE_TTL = 86400;
    public const COLLECTOR_TTL = 7200;

    public static function valid(?string $ip): bool
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        // PHP's flags do not exclude all shared/documentation/benchmark ranges.
        $n = (int) sprintf('%u', ip2long($ip));
        foreach (['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
            [$base, $bits] = explode('/', $cidr);
            $mask = (0xffffffff << (32 - (int) $bits)) & 0xffffffff;
            if (($n & $mask) === ((int) sprintf('%u', ip2long($base)) & $mask)) { return false; }
        }
        return true;
    }

    public function accept(?int $id, ?string $ipv4, string $source, int $age = 0, ?int $now = null, ?int $nodeId = null): void
    {
        if (!self::valid($ipv4)) { return; } // Optional collector metadata never breaks accounting.
        $now ??= time();
        $row = $id !== null ? (array) DB::table('flowscope_machine_addresses')->where('machine_id', $id)->first()
            : (Cache::get('flowscope:node-address:'.$nodeId) ?? []);
        $observed = $now - $age; // Server clock; no client wall-clock timestamps.
        $field = $source.'_updated_at';
        if ($observed < (int) ($row[$field] ?? 0)) { return; }
        $row = array_replace($row, ['machine_id' => $id, $source.'_ipv4' => $ipv4, $field => $observed]);
        $selected = $this->select($row, $now);
        if ($id === null) {
            // Minimal expiring fallback for legacy nodes without a machine relationship.
            Cache::put('flowscope:node-address:'.$nodeId, array_replace($row, $selected), max(1, self::NODE_TTL - $age));
            return;
        }
        DB::table('flowscope_machine_addresses')->updateOrInsert(['machine_id' => $id], [
            $source.'_ipv4' => $ipv4, $field => $observed,
            'ipv4' => $selected['ipv4'], 'updated_at' => $selected['updated_at'],
        ]);
    }

    public function select(array $row, ?int $now = null): array
    {
        $now ??= time();
        foreach (['node' => self::NODE_TTL, 'collector' => self::COLLECTOR_TTL] as $source => $ttl) {
            if (self::valid($row[$source.'_ipv4'] ?? null) && $now - (int) ($row[$source.'_updated_at'] ?? 0) <= $ttl) {
                return ['ipv4' => $row[$source.'_ipv4'], 'updated_at' => (int) $row[$source.'_updated_at'], 'source' => $source, 'stale' => false];
            }
        }
        // Keep the most recently detected valid result when both producers expire.
        $source = (int) ($row['node_updated_at'] ?? 0) >= (int) ($row['collector_updated_at'] ?? 0) ? 'node' : 'collector';
        return ['ipv4' => $row[$source.'_ipv4'] ?? $row['ipv4'],
            'updated_at' => (int) ($row[$source.'_updated_at'] ?? $row['updated_at']), 'source' => $source, 'stale' => true];
    }

    public function listing(): array
    {
        $machines = DB::table('flowscope_machine_addresses')->join('v2_server_machine', 'v2_server_machine.id', '=', 'flowscope_machine_addresses.machine_id')
            ->get(['flowscope_machine_addresses.*'])->mapWithKeys(fn ($row) => [(int) $row->machine_id => (array) $row])->all();
        // Legacy node credentials are panel-wide. Cache results at node scope and
        // resolve current DB relationships at read time; never trust a claimed machine.
        $nodes = Server::query()->where('enabled', true)->get(['id', 'machine_id']);
        $nativeMachines = ServerMachine::query()->pluck('id')->flip()->all();
        $cached = Cache::many($nodes->map(fn ($node) => 'flowscope:node-address:'.$node->id)->all());
        $unassigned = [];
        foreach ($nodes as $node) {
            $row = $cached['flowscope:node-address:'.$node->id] ?? null;
            if (!$row) { continue; }
            if (!$node->machine_id || !isset($nativeMachines[$node->machine_id])) {
                $unassigned[] = ['node_id' => (int) $node->id] + $this->select($row);
            } else {
                $id = (int) $node->machine_id;
                if ((int) ($row['node_updated_at'] ?? 0) > (int) ($machines[$id]['node_updated_at'] ?? 0)) {
                    $machines[$id] = array_replace($machines[$id] ?? ['machine_id' => $id], [
                        'node_ipv4' => $row['node_ipv4'], 'node_updated_at' => $row['node_updated_at'],
                    ]);
                }
            }
        }
        return ['machines' => array_values(array_map(fn ($row) => ['machine_id' => (int) $row['machine_id']] + $this->select($row), $machines)), 'nodes' => $unassigned];
    }
}
