<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\Flowscope\Models\ServerSetting;
use Plugin\Flowscope\Models\MachineSetting;
use Plugin\Flowscope\Models\MachineDaily;
use Plugin\Flowscope\Models\CollectorCursor;
use Plugin\Flowscope\Services\CollectorService;
use Plugin\Flowscope\Services\QuotaService;
use Plugin\Flowscope\Services\MachineIdentityService;
use Plugin\Flowscope\Services\TrafficCycleService;
use Plugin\Flowscope\Services\TrafficUsageService;

$assertions = 0;
function backendCheck(bool $condition, string $description): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$description);
    }
}
function backendSame(mixed $expected, mixed $actual, string $description): void
{
    backendCheck($expected === $actual, $description.'; expected '.var_export($expected, true).', received '.var_export($actual, true));
}
function backendReject(callable $action, string $description): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        backendCheck(true, $description);
        return;
    }
    backendCheck(false, $description);
}

$cycles = new TrafficCycleService();
$quotas = new QuotaService();
$identities = new MachineIdentityService();
$usage = new TrafficUsageService($cycles, $quotas);
$collector = new CollectorService($quotas);

// The configured day is never mutated when a particular month lacks that day.
$cycleCases = [
    [1, '2026-10-07T12:00:00+08:00', '2026-10-01', '2026-11-01'],
    [15, '2026-10-07T12:00:00+08:00', '2026-09-15', '2026-10-15'],
    [15, '2026-10-15T00:00:00+08:00', '2026-10-15', '2026-11-15'],
    [28, '2026-02-28T00:00:00+08:00', '2026-02-28', '2026-03-28'],
    [29, '2026-02-27T23:59:59+08:00', '2026-01-29', '2026-02-28'],
    [29, '2026-02-28T00:00:00+08:00', '2026-02-28', '2026-03-29'],
    [29, '2028-02-29T00:00:00+08:00', '2028-02-29', '2028-03-29'],
    [30, '2026-02-28T00:00:00+08:00', '2026-02-28', '2026-03-30'],
    [30, '2028-02-28T23:59:59+08:00', '2028-01-30', '2028-02-29'],
    [31, '2026-02-28T00:00:00+08:00', '2026-02-28', '2026-03-31'],
    [31, '2028-02-29T00:00:00+08:00', '2028-02-29', '2028-03-31'],
    [31, '2026-04-30T00:00:00+08:00', '2026-04-30', '2026-05-31'],
    [31, '2026-10-30T23:59:59+08:00', '2026-09-30', '2026-10-31'],
    [31, '2026-10-31T00:00:00+08:00', '2026-10-31', '2026-11-30'],
    [1, '2026-01-01T00:00:00+08:00', '2026-01-01', '2026-02-01'],
];
foreach ($cycleCases as [$day, $now, $start, $end]) {
    $cycle = $cycles->calculate($day, new DateTimeImmutable($now));
    backendSame($start.' 00:00:00', $cycle['start']->format('Y-m-d H:i:s'), 'Cycle start, day '.$day.' at '.$now);
    backendSame($end.' 00:00:00', $cycle['end_exclusive']->format('Y-m-d H:i:s'), 'Cycle next start, day '.$day.' at '.$now);
    backendSame($cycle['end_exclusive']->getTimestamp() - 1, $cycle['end']->getTimestamp(), 'Display end is inclusive');
}
backendReject(fn () => $cycles->calculate(0), 'Cycle day 0 rejected');
backendReject(fn () => $cycles->calculate(32), 'Cycle day 32 rejected');
$beforeLA = $cycles->calculate(1, new DateTimeImmutable('2026-10-01T06:59:59Z'), 'America/Los_Angeles');
$afterLA = $cycles->calculate(1, new DateTimeImmutable('2026-10-01T07:00:00Z'), 'America/Los_Angeles');
backendSame('2026-09-01T00:00:00-07:00', $beforeLA['start']->format(DATE_ATOM), 'UTC input uses application-local calendar before midnight');
backendSame('2026-10-01T00:00:00-07:00', $afterLA['start']->format(DATE_ATOM), 'Local midnight opens next cycle');
$dst = $cycles->calculate(8, new DateTimeImmutable('2026-03-09T12:00:00Z'), 'America/Los_Angeles');
backendSame('2026-03-08T00:00:00-08:00', $dst['start']->format(DATE_ATOM), 'DST start offset is local midnight offset');
backendSame('2026-04-08T00:00:00-07:00', $dst['end_exclusive']->format(DATE_ATOM), 'Next boundary gets its own DST offset');

backendSame('1073741824', $quotas->fromValue('1', 'GB'), 'GB uses binary unit');
backendSame('1099511627776', $quotas->fromValue('1', 'TiB'), 'TiB uses binary unit');
backendSame('1610612736', $quotas->fromValue('1.5', 'GiB'), 'Decimal quota conversion is exact');
backendSame('107374182', $quotas->fromValue('0.1', 'GB'), 'Fractional bytes truncate without float arithmetic');
backendSame('9007199254740993', $quotas->normalizeBytes('9007199254740993'), 'Bytes above JS safe integer are preserved');
backendSame(QuotaService::MAX_BYTES, $quotas->normalizeBytes(QuotaService::MAX_BYTES), 'Signed BIGINT maximum supported');
backendSame('18446744073709551614', $quotas->add(QuotaService::MAX_BYTES, QuotaService::MAX_BYTES), 'Usage addition can exceed BIGINT without float conversion');
backendSame('-4', $quotas->add('5', '-9'), 'Signed accounting correction');
backendSame('4', $quotas->add('-5', '9'), 'Signed accounting correction crossing zero');
backendSame('0', $quotas->add('-5', '5'), 'Signed accounting cancellation');
backendSame('121932631112635269', $quotas->multiply('123456789', '987654321'), 'Ratio comparison multiplication is exact');
foreach (['0', '-1', '1.5', '9223372036854775808', '99999999999999999999999'] as $invalid) {
    backendReject(fn () => $quotas->normalizeBytes($invalid), 'Invalid quota rejected: '.$invalid);
}
backendReject(fn () => $quotas->normalizeBytes(9007199254740993.0), 'Already-imprecise float quota rejected');
backendReject(fn () => $quotas->fromValue('1e3', 'GB'), 'Exponent quota input rejected');
backendReject(fn () => $quotas->fromValue('1', 'MB'), 'Unsupported unit rejected');
backendReject(fn () => $quotas->fromValue('0.000000000001', 'GB'), 'Sub-byte zero quota rejected');


backendSame('3074457345618258602', $quotas->divide(QuotaService::MAX_BYTES, 3), 'Exact floor division beyond JS precision');
$legacyMigration = require dirname(__DIR__).'/FlowScope/database/migrations/2026_10_07_000001_create_flowscope_server_settings_table.php';
$machineMigration = require dirname(__DIR__).'/FlowScope/database/migrations/2026_10_07_000002_create_flowscope_machine_tables.php';
$legacyMigration->up();
$machineMigration->up();
foreach (['flowscope_machine_settings', 'flowscope_collector_cursors', 'flowscope_machine_daily'] as $table) {
    backendCheck(Schema::hasTable($table), 'Machine migration creates owned table '.$table);
}
foreach (range(1, 7) as $id) {
    DB::table('v2_server_machine')->insert(['id' => $id, 'name' => $id < 3 ? 'Same name' : 'Machine '.$id, 'token' => str_repeat((string) $id, 32), 'is_active' => $id !== 7]);
}
DB::table('v2_server')->insert([
    ['id' => 1, 'name' => 'Node A', 'type' => 'vless', 'machine_id' => 1],
    ['id' => 2, 'name' => 'Node B', 'type' => 'trojan', 'machine_id' => 1],
]);
ServerSetting::query()->create(['server_type' => 'vless', 'server_id' => 1, 'enabled' => true, 'quota_bytes' => '100', 'traffic_mode' => 'inbound', 'cycle_start_day' => 1]);
ServerSetting::query()->create(['server_type' => 'trojan', 'server_id' => 2, 'enabled' => true, 'quota_bytes' => '200', 'traffic_mode' => 'outbound', 'cycle_start_day' => 15]);
backendSame(0, MachineSetting::query()->count(), 'Conflicting legacy node settings are not silently mapped or merged');
backendSame(1, $identities->find('1')?->id, 'Machine identity uses exact primary ID');
backendSame(null, $identities->find('1 OR 1=1'), 'SQL-like identity rejected');
backendSame(null, $identities->find('9223372036854775808'), 'Overflowing identity rejected');
DB::table('v2_plugins')->insert(['code' => 'flowscope', 'name' => 'FlowScope', 'version' => \Plugin\Flowscope\Plugin::VERSION, 'is_enabled' => true]);
require dirname(__DIR__).'/FlowScope/routes/api.php';
function backendJson(Symfony\Component\HttpFoundation\Response $response): array {
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}
$valid = ['enabled' => true, 'display_name' => ' Name ', 'quota_bytes' => '9007199254740993', 'traffic_mode' => 'inbound', 'cycle_start_day' => 31];
$show = backendJson(flowScopeRequest('GET', '/api/v2/flowscope/machine/1'))['data'];
backendSame(['machine_id' => 1, 'name' => 'Same name', 'is_active' => true], $show['server'], 'Show returns machine identity without credentials');
backendSame(false, $show['settings']['enabled'], 'Machine defaults disabled without adopting legacy settings');
backendSame('waiting', $show['collector']['status'], 'Unbound collector status waiting');
backendSame(null, $show['collector']['id'], 'No collector identity fabricated');
backendSame(QuotaService::DEFAULT_BYTES, $show['settings']['quota_bytes'], 'Default quota string');
backendSame(200, flowScopeRequest('POST', '/api/v2/flowscope/machine/1', $valid)->getStatusCode(), 'Admin saves machine settings');
$stored = backendJson(flowScopeRequest('GET', '/api/v2/flowscope/machine/1'))['data']['settings'];
backendSame('Name', $stored['display_name'], 'Display name trimmed');
backendSame('9007199254740993', $stored['quota_bytes'], 'Large quota retains exact bytes');
backendSame(31, $stored['cycle_start_day'], 'Configured cycle day unchanged');
backendSame(200, flowScopeRequest('POST', '/api/v2/flowscope/machine/1', $valid)->getStatusCode(), 'Machine save idempotent');
backendSame(1, MachineSetting::query()->count(), 'Machine setting identity unique');
$decimal = $valid;
unset($decimal['quota_bytes']);
$decimal += ['quota_value' => '1.5', 'quota_unit' => 'GiB'];
backendSame('1610612736', backendJson(flowScopeRequest('POST', '/api/v2/flowscope/machine/1', $decimal))['data']['settings']['quota_bytes'], 'Decimal quota conversion exact');
foreach ([
    ['quota_bytes' => '0'], ['quota_bytes' => '-1'], ['quota_bytes' => '9223372036854775808'],
    ['quota_bytes' => 9007199254740993.0], ['traffic_mode' => 'sideways'], ['cycle_start_day' => 0],
    ['cycle_start_day' => 32], ['display_name' => str_repeat('x', 121)], ['display_name' => '<script>bad</script>'], ['enabled' => 'yes'],
] as $invalid) {
    backendSame(422, flowScopeRequest('POST', '/api/v2/flowscope/machine/1', array_replace($valid, $invalid))->getStatusCode(), 'Invalid setting rejected '.json_encode($invalid));
}
backendSame(403, flowScopeRequest('POST', '/api/v2/flowscope/machine/1', $valid, false)->getStatusCode(), 'Non-admin settings write denied');
backendSame(403, flowScopeRequest('GET', '/api/v2/flowscope/dashboard', [], null)->getStatusCode(), 'Anonymous dashboard denied');
foreach (['0', '999', '9223372036854775808'] as $missing) {
    backendSame(404, flowScopeRequest('GET', '/api/v2/flowscope/machine/'.$missing)->getStatusCode(), 'Unknown machine rejected '.$missing);
}
backendSame(7, count(backendJson(flowScopeRequest('GET', '/api/v2/flowscope/machines'))['data']['machines']), 'Index lists machines, not child nodes');
foreach (['/servers', '/server/vless/1'] as $legacy) {
    backendSame(410, flowScopeRequest('GET', '/api/v2/flowscope'.$legacy)->getStatusCode(), 'Legacy node API explicitly retired');
}
backendSame(410, flowScopeRequest('POST', '/api/v2/flowscope/server/vless/1', $valid)->getStatusCode(), 'Legacy writes cannot mutate proxy settings');
backendSame(2, ServerSetting::query()->count(), 'Legacy settings retained exactly');

$midnight = (new DateTimeImmutable('now', new DateTimeZone(config('app.timezone'))))->setTime(0, 0)->getTimestamp();
$sample = [
    'protocol' => 1, 'machine_id' => 1, 'token' => str_repeat('1', 32), 'collector_id' => str_repeat('a', 32),
    'sequence' => '1', 'started_at' => $midnight - 40, 'ended_at' => $midnight + 20,
    'boot_id' => '0a6bb2f4-b88d-4ef2-b5a0-828dcbdb2255', 'interfaces' => ['eth0'],
    'rx_bytes' => '101', 'tx_bytes' => '203',
];
$accepted = flowScopeRequest('POST', '/api/v2/flowscope/collect', $sample, null);
backendSame(200, $accepted->getStatusCode(), 'Machine token authenticates independently of Admin/node middleware');
backendSame(['accepted' => true, 'sequence' => '1', 'duplicate' => false], backendJson($accepted)['data'], 'Collector accepted acknowledgement');
$daily = MachineDaily::query()->orderBy('record_at')->get();
backendSame(2, $daily->count(), 'Midnight-spanning interval produces two daily buckets');
backendSame(['67', '34'], $daily->pluck('rx_bytes')->all(), 'Proportional RX allocation keeps integer remainder in final bucket');
backendSame(['135', '68'], $daily->pluck('tx_bytes')->all(), 'Proportional TX allocation conserves all bytes');
backendSame('interval_prorated', $usage->dashboard()['allocation'], 'Allocation accuracy explicitly labeled');
backendSame(true, backendJson(flowScopeRequest('POST', '/api/v2/flowscope/collect', $sample, null))['data']['duplicate'], 'Retry acknowledgement is idempotent');
backendSame(['67', '34'], MachineDaily::query()->orderBy('record_at')->pluck('rx_bytes')->all(), 'Retry does not count traffic twice');
backendSame(409, flowScopeRequest('POST', '/api/v2/flowscope/collect', array_replace($sample, ['rx_bytes' => '102']), null)->getStatusCode(), 'Latest sequence payload conflict rejected');
backendSame(409, flowScopeRequest('POST', '/api/v2/flowscope/collect', array_replace($sample, ['collector_id' => str_repeat('b', 32)]), null)->getStatusCode(), 'Second collector cannot duplicate machine traffic');
$gap = flowScopeRequest('POST', '/api/v2/flowscope/collect', array_replace($sample, ['sequence' => '3']), null);
backendSame(409, $gap->getStatusCode(), 'Sequence gap rejected');
backendSame('2', backendJson($gap)['expected_sequence'], 'Sequence gap gives expected sequence');
backendSame(409, flowScopeRequest('POST', '/api/v2/flowscope/collect', array_replace($sample, ['sequence' => '2']), null)->getStatusCode(), 'Noncontiguous interval rejected');
foreach ([
    ['token' => 'bad'], ['machine_id' => 7, 'token' => str_repeat('7', 32)],
    ['machine_id' => 999], ['machine_id' => 2],
] as $badAuth) {
    backendSame(403, flowScopeRequest('POST', '/api/v2/flowscope/collect', array_replace($sample, $badAuth), null)->getStatusCode(), 'Exact active-machine credentials required');
}
foreach ([
    ['protocol' => 2], ['protocol' => '1'], ['machine_id' => '1'], ['sequence' => 1],
    ['sequence' => '0'], ['sequence' => '9223372036854775808'], ['collector_id' => str_repeat('A', 32)],
    ['rx_bytes' => 0], ['rx_bytes' => 9007199254740993.0], ['rx_bytes' => '-1'], ['rx_bytes' => '9223372036854775808'],
    ['tx_bytes' => '1.5'], ['started_at' => '1577836800'], ['started_at' => 1577836799],
    ['ended_at' => time() + 600], ['ended_at' => $sample['started_at']], ['ended_at' => $sample['started_at'] + CollectorService::MAX_INTERVAL + 1],
    ['boot_id' => 'bad'], ['interfaces' => []], ['interfaces' => ['eth0', 'eth0']], ['interfaces' => ['../eth0']],
    ['resets' => -1], ['resets' => 1.0], ['boot_changed' => 'true'],
] as $invalid) {
    backendSame(422, flowScopeRequest('POST', '/api/v2/flowscope/collect', array_replace($sample, $invalid), null)->getStatusCode(), 'Invalid collector field rejected '.json_encode($invalid));
}
$next = array_replace($sample, [
    'sequence' => '2', 'started_at' => $sample['ended_at'], 'ended_at' => $sample['ended_at'] + 10,
    'rx_bytes' => QuotaService::MAX_BYTES, 'tx_bytes' => QuotaService::MAX_BYTES,
    'interfaces' => ['ens3'], 'resets' => 1, 'boot_changed' => true,
]);
backendSame(200, flowScopeRequest('POST', '/api/v2/flowscope/collect', $next, null)->getStatusCode(), 'New sequence and interface set accepted once');
backendSame('9223372036854775841', MachineDaily::query()->orderByDesc('record_at')->first()->rx_bytes, 'Daily strings safely exceed signed BIGINT without SQL SUM');
backendSame(true, backendJson(flowScopeRequest('POST', '/api/v2/flowscope/collect', $sample, null))['data']['duplicate'], 'Older accepted sequence acknowledged without reaccumulation');
$cursor = CollectorCursor::query()->first();
backendSame(['ens3'], $cursor->interfaces, 'Latest interfaces persisted');
backendSame(1, $cursor->resets, 'Reset diagnostics persisted');
backendSame(1, $cursor->boot_changes, 'Boot change diagnostics persisted');
backendSame($sample['started_at'], $cursor->first_sample_at, 'Coverage begins at first measured interval');
backendSame('stale', CollectorService::metadata($cursor, $cursor->last_sample_at + 181)['status'], 'Staleness uses sample time, not outbox receipt');
backendSame('active', CollectorService::metadata($cursor, $cursor->last_sample_at + 180)['status'], 'Recent sample considered active');
backendCheck(!str_contains(flowScopeRequest('GET', '/api/v2/flowscope/machine/1')->getContent(), $sample['token']), 'Admin response never exposes machine token');
backendSame(true, (new CollectorService(new QuotaService()))->accept($next)['duplicate'], 'Fresh service instance reuses persisted cursor without reaccumulating');
$beforeRotation = MachineDaily::query()->get()->toArray();
DB::table('v2_server_machine')->where('id', 1)->update(['token' => 'rotated-machine-token']);
backendSame(403, flowScopeRequest('POST', '/api/v2/flowscope/collect', $next, null)->getStatusCode(), 'Rotated native token immediately invalidates old credentials even on duplicate');
$rotatedRetry = array_replace($next, ['token' => 'rotated-machine-token']);
backendSame(true, backendJson(flowScopeRequest('POST', '/api/v2/flowscope/collect', $rotatedRetry, null))['data']['duplicate'], 'Same durable payload can retry with new token without digest conflict');
backendSame($beforeRotation, MachineDaily::query()->get()->toArray(), 'Token rotation does not reaccumulate accepted bytes');
DB::table('v2_server_machine')->where('id', 1)->update(['token' => $sample['token']]);

// Force a second-bucket DB failure after the first insert. The complete report
// must roll back and leave the same sequence safely retryable.
$storageFailure = array_replace($sample, ['machine_id' => 2, 'token' => str_repeat('2',32), 'collector_id' => str_repeat('f',32)]);
DB::unprepared("CREATE TRIGGER flowscope_test_failure BEFORE INSERT ON flowscope_machine_daily WHEN NEW.machine_id = 2 AND NEW.record_at >= ".$midnight." BEGIN SELECT RAISE(ABORT, 'test write failure'); END");
$failedStorageResponse = flowScopeRequest('POST', '/api/v2/flowscope/collect', $storageFailure, null);
backendSame(503, $failedStorageResponse->getStatusCode(), 'DB write failure yields retryable 503, not partial acceptance');
backendSame(true, backendJson($failedStorageResponse)['retryable'], 'DB failure explicitly retryable');
backendSame(0, MachineDaily::query()->where('machine_id',2)->count(), 'First bucket rolled back when second bucket fails');
backendSame(0, CollectorCursor::query()->where('machine_id',2)->count(), 'Failed transaction does not advance or bind cursor');
DB::unprepared('DROP TRIGGER flowscope_test_failure');
backendSame(200, flowScopeRequest('POST', '/api/v2/flowscope/collect', $storageFailure, null)->getStatusCode(), 'Retry after storage recovery accepts original sequence once');

$beforeReset = MachineDaily::query()->get()->toArray();
backendSame(403, flowScopeRequest('POST', '/api/v2/flowscope/machine/1/collector-reset', [], false)->getStatusCode(), 'Reset requires native Admin authorization');
backendSame(200, flowScopeRequest('POST', '/api/v2/flowscope/machine/1/collector-reset')->getStatusCode(), 'Explicit Admin reset unbinds collector');
backendSame(0, CollectorCursor::query()->where('machine_id',1)->count(), 'Reset removes only target cursor binding');
backendSame(1, CollectorCursor::query()->where('machine_id',2)->count(), 'Reset cannot unbind another machine collector');
backendSame($beforeReset, MachineDaily::query()->get()->toArray(), 'Reset preserves all historical daily bytes');
backendSame(409, flowScopeRequest('POST', '/api/v2/flowscope/collect', $next, null)->getStatusCode(), 'After reset old sequence cannot resume implicitly');
$newCollector = array_replace($sample, ['collector_id' => str_repeat('c', 32), 'started_at' => $next['ended_at'], 'ended_at' => $next['ended_at'] + 1, 'rx_bytes' => '0', 'tx_bytes' => '0']);
backendSame(200, flowScopeRequest('POST', '/api/v2/flowscope/collect', $newCollector, null)->getStatusCode(), 'Explicit reset permits new collector sequence 1');

// Exact allocation and calendar/DST boundaries.
$allocation = $collector->allocate((new DateTimeImmutable('2026-03-08T00:00:00-08:00'))->getTimestamp(), (new DateTimeImmutable('2026-03-10T00:00:00-07:00'))->getTimestamp(), '47', '95', 'America/Los_Angeles');
backendSame(['23', '24'], array_column($allocation, 'rx_bytes'), 'DST-short day uses 23-hour weighting');
backendSame(['46', '49'], array_column($allocation, 'tx_bytes'), 'DST remainder conserves odd bytes');
$largeAllocation = $collector->allocate($midnight - 1, $midnight + 2, QuotaService::MAX_BYTES, '0', config('app.timezone'));
backendSame(QuotaService::MAX_BYTES, $quotas->add($largeAllocation[0]['rx_bytes'], $largeAllocation[1]['rx_bytes']), 'Boundary allocation conserves very large exact bytes');

// Batch dashboard reads only plugin NIC buckets, never native proxy statistics.
MachineSetting::query()->delete();
MachineDaily::query()->delete();
CollectorCursor::query()->delete();
$at = new DateTimeImmutable('2026-10-07T12:00:00+08:00');
foreach ([[1,'inbound',1,'100'],[2,'outbound',15,'100'],[3,'both',31,'100'],[4,'outbound',1,'100'],[5,'outbound',1,'100'],[6,'outbound',1,'100']] as [$id,$mode,$day,$quota]) {
    MachineSetting::query()->create(['machine_id' => $id, 'enabled' => $id !== 5, 'quota_bytes' => $quota, 'traffic_mode' => $mode, 'cycle_start_day' => $day]);
}
DB::table('v2_server_machine')->where('id', 6)->delete();
foreach ([[1,'2026-10-01',10,90],[1,'2026-09-30',999,999],[2,'2026-09-15',20,200],[2,'2026-09-14',999,999],[3,'2026-09-30',30,70],[4,'2026-10-07',0,0],[4,'2026-10-08',999,999]] as [$id,$day,$rx,$tx]) {
    MachineDaily::query()->create(['machine_id' => $id, 'record_at' => (new DateTimeImmutable($day.'T00:00:00+08:00'))->getTimestamp(), 'timezone' => 'Asia/Shanghai', 'rx_bytes' => (string) $rx, 'tx_bytes' => (string) $tx]);
}
DB::table('v2_stat_server')->insert(['server_id' => 1, 'server_type' => 'vless', 'u' => 999999999, 'd' => 999999999, 'record_at' => $at->setTime(0,0)->getTimestamp()]);
DB::enableQueryLog();
$dashboard = $usage->dashboard($at);
$queries = DB::getQueryLog();
DB::disableQueryLog();
backendSame(4, count($dashboard['servers']), 'Deleted/disabled settings excluded, waiting machines remain visible');
backendSame([2,3,1,4], array_column($dashboard['servers'], 'machine_id'), 'Dashboard sorted by exact usage ratio descending');
$byId = array_column($dashboard['servers'], null, 'machine_id');
backendSame('10', $byId[1]['used_bytes'], 'RX is machine inbound and ignores native proxy u');
backendSame('200', $byId[2]['used_bytes'], 'TX is machine outbound');
backendSame('100', $byId[3]['used_bytes'], 'Both includes RX plus TX exactly once');
backendSame(100, $byId[2]['progress_percentage'], 'Over-quota visual progress capped');
backendSame(200.0, $byId[2]['percentage'], 'Text percentage can exceed quota');
backendSame(true, $byId[2]['over_quota'], 'Over-quota flag exact');
backendSame(false, $byId[3]['over_quota'], 'Equal quota is not over quota');
backendSame('waiting', $byId[4]['collector_status'], 'No samples explicitly shown as waiting');
backendSame('0', $byId[4]['used_bytes'], 'No sample future buckets do not fabricate usage');
backendSame('nic', $byId[1]['traffic_source'], 'Dashboard provenance is explicit NIC');
backendSame('interval_prorated', $byId[1]['allocation'], 'Dashboard states interval allocation method');
backendSame(4, count($queries), 'Dashboard uses four batched queries with no N+1');
backendCheck(!str_contains(strtolower(json_encode($queries)), 'v2_stat_server'), 'Dashboard never queries native proxy traffic');

// Plugin restart reuses persisted cursor/daily state; disable gates every API.
DB::table('v2_plugins')->where('code','flowscope')->update(['is_enabled' => false]);
backendSame(404, flowScopeRequest('POST', '/api/v2/flowscope/collect', $sample, null)->getStatusCode(), 'Disabled plugin rejects collectors immediately');
backendSame(404, flowScopeRequest('GET', '/api/v2/flowscope/dashboard')->getStatusCode(), 'Disabled plugin gates retained Admin routes');
backendSame(6, MachineSetting::query()->count(), 'Disable keeps machine settings');
DB::table('v2_plugins')->where('code','flowscope')->update(['is_enabled' => true]);
backendSame(200, flowScopeRequest('GET', '/api/v2/flowscope/dashboard')->getStatusCode(), 'Re-enable restores API');
backendSame(2, DB::table('v2_server')->count(), 'Core proxy nodes never changed');
backendSame(6, DB::table('v2_server_machine')->count(), 'Only deliberate test deletion touched native machine identity');
backendSame(1, DB::table('v2_stat_server')->count(), 'Native proxy statistics never changed');
$machineMigration->down();
foreach (['flowscope_machine_settings','flowscope_collector_cursors','flowscope_machine_daily'] as $table) { backendCheck(!Schema::hasTable($table), 'Rollback removes '.$table); }
backendSame(2, ServerSetting::query()->count(), 'v2 migration rollback leaves legacy settings untouched');
backendSame(6, DB::table('v2_server_machine')->count(), 'Rollback preserves native machines');
$legacyMigration->down();
backendCheck(!Schema::hasTable('flowscope_server_settings'), 'Full uninstall legacy migration rolls back only owned legacy table');
$legacyMigration->up();
$machineMigration->up();
backendSame(0, MachineSetting::query()->count(), 'Reinstall clean machine settings');
echo 'PASS backend: '.$assertions." assertions (machine identity, NIC collector, exact allocation, APIs, auth, rollback).\n";
