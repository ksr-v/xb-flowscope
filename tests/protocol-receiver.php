<?php
declare(strict_types=1);

// CLI-only isolated counterpart for the Python producer/protocol regression.
// No HTTP listener, production DB, filesystem installation or native writes.
require __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Plugin\Flowscope\Models\MachineSetting;
use Plugin\Flowscope\Services\CollectorService;
use Plugin\Flowscope\Services\QuotaService;
use Plugin\Flowscope\Services\TrafficCycleService;
use Plugin\Flowscope\Services\TrafficUsageService;

foreach (glob(dirname(__DIR__).'/FlowScope/database/migrations/*.php') as $migration) {
    (require $migration)->up();
}
DB::table('v2_plugins')->insert(['code' => 'flowscope', 'name' => 'FlowScope', 'version' => '2.0.0', 'is_enabled' => true]);
DB::table('v2_server_machine')->insert(['id' => 41, 'name' => 'Protocol fixture', 'token' => 'fixture-machine-token', 'is_active' => true]);
MachineSetting::query()->create(['machine_id' => 41, 'enabled' => true, 'quota_bytes' => '1000', 'traffic_mode' => 'both', 'cycle_start_day' => 1]);
require dirname(__DIR__).'/FlowScope/routes/api.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$responses = [];
foreach ($input['reports'] ?? [] as $report) {
    $response = flowScopeRequest('POST', '/api/v2/flowscope/collect', $report, null);
    $responses[] = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)];
}
$bytes = new QuotaService();
$service = new CollectorService($bytes);
$allocations = [];
foreach ($input['allocations'] ?? [] as $case) {
    $allocations[] = $service->allocate($case['start'], $case['end'], $case['rx'], $case['tx'], $case['timezone']);
}
$usage = new TrafficUsageService(new TrafficCycleService(), $bytes);
echo json_encode(['responses' => $responses, 'allocations' => $allocations, 'dashboard' => $usage->dashboard(isset($input['at']) ? new DateTimeImmutable($input['at']) : null)], JSON_THROW_ON_ERROR);
