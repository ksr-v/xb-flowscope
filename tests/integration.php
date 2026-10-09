<?php
declare(strict_types=1);

$app = require __DIR__ . '/bootstrap.php';

use App\Models\Plugin as PluginRecord;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Plugin\Flowscope\Services\AdminCompatibility;
use Plugin\Flowscope\Services\AdminIntegration;

$releaseVersion = json_decode(file_get_contents(dirname(__DIR__) . '/FlowScope/config.json'), true, 512, JSON_THROW_ON_ERROR)['version'];
$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL ' . $message); }
    ++$checks;
    echo 'PASS ' . $message . PHP_EOL;
}
function jsonBody(Symfony\Component\HttpFoundation\Response $response): array
{
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

File::copyDirectory(dirname(__DIR__) . '/FlowScope', base_path('plugins/Flowscope'));
File::ensureDirectoryExists(public_path('assets/admin/assets'));
$originalBundle = file_get_contents($xboardPath . '/public/assets/admin/assets/' . AdminCompatibility::BUILD);
file_put_contents(public_path('assets/admin/assets/' . AdminCompatibility::BUILD), $originalBundle);
copy($xboardPath . '/public/assets/admin/manifest.json', public_path('assets/admin/manifest.json'));
$nativeView = resource_path('views/admin.blade.php');
copy($xboardPath . '/resources/views/admin.blade.php', $nativeView);
$foreignView = resource_path('views/foreign-admin.blade.php');
file_put_contents($foreignView, str_replace('</body>', '<!-- foreign-template:keep --></body>', file_get_contents($nativeView)));
$app['events']->listen('composing: admin', static function (Illuminate\View\View $view) use ($foreignView): void {
    $view->setPath($foreignView);
});

$bundleFile = public_path('assets/admin/assets/' . AdminCompatibility::BUILD);
$initialHash = hash_file('sha256', $bundleFile);
verify($initialHash === AdminCompatibility::AUDITED_SHA256, 'audited real Admin bundle provenance');
$compatibility = new AdminCompatibility();
verify($compatibility->inspect()['supported'], 'all real Admin structural signatures unique');
$foreignBefore = "\n/* foreign-plugin-before:keep */\n";
file_put_contents($bundleFile, $originalBundle . $foreignBefore);
verify($compatibility->inspect()['supported'], 'unrelated pre-existing foreign patch accepted');
$foreignHash = hash_file('sha256', $bundleFile);
$manager = new PluginManager();
// Install only the real 1.x migration first: migration batches, rather than
// a metadata-only version change, must exercise native update/rollback.
$fixtureConfigPath = base_path('plugins/Flowscope/config.json');
$currentFixtureConfig = file_get_contents($fixtureConfigPath);
$legacyFixtureConfig = json_decode($currentFixtureConfig, true, 512, JSON_THROW_ON_ERROR);
$legacyFixtureConfig['version'] = '1.0.1';
file_put_contents($fixtureConfigPath, json_encode($legacyFixtureConfig, JSON_THROW_ON_ERROR));
$newMigrationPath = base_path('plugins/Flowscope/database/migrations/2026_10_07_000002_create_flowscope_machine_tables.php');
File::move($newMigrationPath, $newMigrationPath . '.pending');
$ticketMigrationPath = base_path('plugins/Flowscope/database/migrations/2026_10_07_000003_create_flowscope_install_tickets_table.php');
File::move($ticketMigrationPath, $ticketMigrationPath . '.pending');
$addressMigrationPath = base_path('plugins/Flowscope/database/migrations/2026_10_07_000004_create_flowscope_machine_addresses_table.php');
File::move($addressMigrationPath, $addressMigrationPath . '.pending');
$sourcesMigrationPath = base_path('plugins/Flowscope/database/migrations/2026_10_09_000005_add_address_sources.php');
File::move($sourcesMigrationPath, $sourcesMigrationPath . '.pending');

$app['router']->get('/native/order', static fn () => response()->json(['native' => true]));
verify($manager->install('flowscope'), 'native PluginManager install');
verify(Schema::hasTable('flowscope_server_settings'), 'native migration creates plugin table');
verify(!PluginRecord::where('code', 'flowscope')->first()->is_enabled, 'install defaults plugin disabled');
verify(!Schema::hasTable('flowscope_machine_settings'), 'legacy installation has only the actual old migration batch');
DB::table('flowscope_server_settings')->insert([
    ['server_type' => 'vless', 'server_id' => 11, 'enabled' => true, 'quota_bytes' => 100, 'traffic_mode' => 'inbound', 'cycle_start_day' => 1],
    ['server_type' => 'trojan', 'server_id' => 42, 'enabled' => true, 'quota_bytes' => 200, 'traffic_mode' => 'outbound', 'cycle_start_day' => 15],
]);
File::move($newMigrationPath . '.pending', $newMigrationPath);
File::move($ticketMigrationPath . '.pending', $ticketMigrationPath);
File::move($addressMigrationPath . '.pending', $addressMigrationPath);
File::move($sourcesMigrationPath . '.pending', $sourcesMigrationPath);
file_put_contents($fixtureConfigPath, $currentFixtureConfig);
verify($manager->update('flowscope'), 'native update of actual 1.0.1 migration batch to machine release');
verify(PluginRecord::where('code', 'flowscope')->value('version') === $releaseVersion, 'real upgrade records machine SemVer');
verify(Schema::hasTable('flowscope_machine_settings') && Schema::hasTable('flowscope_collector_cursors') && Schema::hasTable('flowscope_machine_daily'), 'native update creates all independent machine tables');
verify(DB::table('flowscope_server_settings')->count() === 2 && DB::table('flowscope_machine_settings')->count() === 0, 'upgrade retains conflicting legacy settings without guessing machine mapping');
verify($manager->enable('flowscope'), 'native PluginManager enable');
verify(flowScopeRequest('GET', '/api/v2/flowscope/dashboard', [], null)->getStatusCode() === 403, 'anonymous API denied by native admin middleware');
verify(flowScopeRequest('GET', '/api/v2/flowscope/dashboard', [], false)->getStatusCode() === 403, 'non-admin API denied by native admin middleware');
verify(flowScopeRequest('GET', '/api/v2/flowscope/dashboard')->getStatusCode() === 200, 'admin dashboard API available');
verify(jsonBody(flowScopeRequest('GET', '/api/v2/flowscope/dashboard'))['data']['servers'] === [], 'empty dashboard JSON');

DB::table('v2_server_machine')->insert([
    ['id' => 11, 'name' => 'Native Machine', 'token' => str_repeat('a', 32), 'is_active' => true],
    ['id' => 42, 'name' => 'Native Machine', 'token' => str_repeat('b', 32), 'is_active' => true],
]);
DB::table('v2_server')->insert(['id' => 11, 'type' => 'vless', 'code' => '42', 'name' => 'Native Node', 'machine_id' => 11]);
DB::table('v2_server')->insert(['id' => 42, 'type' => 'trojan', 'code' => '11', 'name' => 'Other Node', 'machine_id' => 11]);
$payload = ['enabled' => true, 'display_name' => 'Hong Kong 01', 'quota_bytes' => '1099511627776', 'traffic_mode' => 'outbound', 'cycle_start_day' => 15];
verify(flowScopeRequest('POST', '/api/v2/flowscope/machine/11', $payload)->getStatusCode() === 200, 'validated plugin machine settings write');
verify((int) DB::table('flowscope_machine_settings')->value('machine_id') === 11, 'machine identity uses exact primary id independently of child nodes');
verify(DB::table('v2_server_machine')->where('id', 11)->value('name') === 'Native Machine', 'settings write preserves native machine row');
verify(DB::table('flowscope_server_settings')->count() === 2, 'machine save does not merge conflicting child-node settings');
verify(flowScopeRequest('GET', '/api/v2/flowscope/server/vless/11')->getStatusCode() === 410, 'old node API explicitly retired rather than returning proxy traffic');
verify(flowScopeRequest('GET', '/api/v2/flowscope/machine/999999')->getStatusCode() === 404, 'unknown machine rejected');
verify(flowScopeRequest('GET', '/api/v2/flowscope/machine/0')->getStatusCode() === 404, 'invalid machine id rejected');
foreach (['quota_bytes' => '0', 'traffic_mode' => 'sql', 'cycle_start_day' => 32, 'display_name' => '<script>alert(1)</script>'] as $field => $value) {
    verify(flowScopeRequest('POST', '/api/v2/flowscope/machine/11', array_replace($payload, [$field => $value]))->getStatusCode() === 422, 'invalid ' . $field . ' returns 422');
}
verify(flowScopeRequest('POST', '/api/v2/flowscope/machine/11', $payload, false)->getStatusCode() === 403, 'unauthorized writes denied');
$collect = ['protocol' => 1, 'machine_id' => 11, 'token' => str_repeat('a', 32), 'collector_id' => str_repeat('c', 32), 'sequence' => '1', 'started_at' => time() - 10, 'ended_at' => time(), 'boot_id' => '0a6bb2f4-b88d-4ef2-b5a0-828dcbdb2255', 'interfaces' => ['eth0'], 'rx_bytes' => '123', 'tx_bytes' => '456'];
verify(flowScopeRequest('POST', '/api/v2/flowscope/collect', $collect, null)->getStatusCode() === 200, 'native lifecycle registers standalone machine-authenticated collector');
verify(DB::table('flowscope_machine_daily')->count() >= 1 && DB::table('flowscope_collector_cursors')->count() === 1, 'collector writes only persistent plugin-owned state');
verify(jsonBody(flowScopeRequest('GET', '/api/v2/flowscope/machine/11'))['data']['collector']['status'] === 'active', 'fresh collector metadata available without token');
verify(!str_contains(flowScopeRequest('GET', '/api/v2/flowscope/machines')->getContent(), str_repeat('a',32)), 'machine list does not disclose native credentials');
$assetCases = [
    'script' => ['flowscope.js', 'application/javascript; charset=UTF-8'],
    'style' => ['flowscope.css', 'text/css; charset=UTF-8'],
    'flowscope.js' => ['flowscope.js', 'application/javascript; charset=UTF-8'],
    'flowscope.css' => ['flowscope.css', 'text/css; charset=UTF-8'],
];
foreach ($assetCases as $asset => [$file, $contentType]) {
    $assetResponse = flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset, [], null);
    verify($assetResponse->getStatusCode() === 200, 'enabled canonical or compatibility asset: ' . $asset);
    verify($assetResponse->headers->get('Content-Type') === $contentType, 'fixed asset MIME: ' . $asset);
    ob_start();
    $assetResponse->sendContent();
    $assetBody = ob_get_clean();
    verify(hash('sha256', $assetBody) === hash_file('sha256', dirname(__DIR__) . '/FlowScope/resources/assets/' . $file), 'asset serves exact owned file bytes: ' . $asset);
    verify(str_contains($assetResponse->headers->get('Cache-Control', ''), 'no-store'), 'asset remains uncached: ' . $asset);
    verify($assetResponse->headers->get('X-Content-Type-Options') === 'nosniff', 'asset MIME protection: ' . $asset);
    verify($assetResponse->headers->get('Cross-Origin-Resource-Policy') === 'same-origin', 'asset remains same-origin: ' . $asset);
}
foreach (['config.json', 'script.js', 'style.css', '%2e%2e%2fconfig.json'] as $asset) {
    verify(flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset)->getStatusCode() === 404, 'route whitelist refuses unknown or traversal key: ' . $asset);
}
verify(app(Plugin\Flowscope\Controllers\AssetController::class)->show('../config.json')->getStatusCode() === 404, 'controller whitelist independently refuses traversal');

$viewData = ['title' => 'Native & Foreign', 'version' => 'test', 'logo' => '', 'secure_path' => 'secure-test'];
$html = view('admin', $viewData)->render();
verify(substr_count($html, 'data-flowscope-loader') === 1, 'real native Blade wrapper injects one loader');
verify(str_contains($html, 'src="/api/v2/flowscope/assets/script?v=' . $releaseVersion . '"'), 'injected script uses extensionless canonical URL');
verify(str_contains($html, 'href="/api/v2/flowscope/assets/style?v=' . $releaseVersion . '"'), 'injected stylesheet uses extensionless canonical URL');
verify(!str_contains($html, '/api/v2/flowscope/assets/flowscope.'), 'injected loader avoids static extension proxy interception');
verify(str_contains($html, '/assets/admin/assets/' . AdminCompatibility::BUILD), 'native manifest loader preserved');
verify(str_contains($html, '<div id="root"></div>'), 'native React root preserved');
verify(str_contains($html, '<!-- foreign-template:keep -->'), 'prior foreign view composer path preserved');
verify(AdminIntegration::inject($html) === $html, 'response injection idempotent');
verify(AdminIntegration::inject('<div>partial</div>') === '<div>partial</div>', 'partial HTML left unchanged');
$eventCount = count($app['events']->getRawListeners()['composing: admin'] ?? []);
$routeCount = count($app['router']->getRoutes());
app(AdminIntegration::class)->register();
app(AdminIntegration::class)->register();
verify(count($app['events']->getRawListeners()['composing: admin'] ?? []) === $eventCount, 'composer registration does not duplicate');

// Foreign changes made after FlowScope is active are also untouched.
$foreignAfter = "/* foreign-plugin-after:keep */\n";
file_put_contents($bundleFile, $originalBundle . $foreignBefore . $foreignAfter);
$afterHash = hash_file('sha256', $bundleFile);
foreach ([1, 2] as $round) {
    verify($manager->disable('flowscope'), 'native disable round ' . $round);
    verify(flowScopeRequest('GET', '/api/v2/flowscope/dashboard')->getStatusCode() === 404, 'retained route gated while disabled round ' . $round);
    foreach (array_keys($assetCases) as $asset) {
        verify(flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset)->getStatusCode() === 404, 'canonical or compatibility asset gated while disabled round ' . $round . ': ' . $asset);
    }
    verify(flowScopeRequest('POST', '/api/v2/flowscope/collect', $collect, null)->getStatusCode() === 404, 'collector gated immediately while disabled round ' . $round);
    verify(!str_contains(view('admin', $viewData)->render(), 'data-flowscope-loader'), 'native UI restored on disabled render round ' . $round);
    verify(DB::table('flowscope_machine_settings')->count() === 1, 'disable retains configuration round ' . $round);
    verify(hash_file('sha256', $bundleFile) === $afterHash, 'foreign bundle bytes preserved while disabled round ' . $round);
    verify($manager->enable('flowscope'), 'native re-enable round ' . $round);
    verify(substr_count(view('admin', $viewData)->render(), 'data-flowscope-loader') === 1, 'no loader duplication after re-enable round ' . $round);
    verify(count($app['router']->getRoutes()) === $routeCount, 'route collection does not grow after re-enable round ' . $round);
}
verify(flowScopeRequest('GET', '/native/order')->getStatusCode() === 200, 'original route remains reachable');

// Exercise the native update path from the release affected by suffix routing.
PluginRecord::where('code', 'flowscope')->update(['version' => '1.0.0']);
verify($manager->update('flowscope'), 'native update 1.0.0 record to ' . $releaseVersion);
verify(PluginRecord::where('code', 'flowscope')->value('version') === $releaseVersion, 'update records current SemVer');
verify(DB::table('flowscope_machine_settings')->count() === 1, 'update retains settings');
verify(hash_file('sha256', $bundleFile) === $afterHash, 'update preserves foreign bundle modifications');

verify($manager->uninstall('flowscope'), 'native uninstall');
verify(!Schema::hasTable('flowscope_machine_settings') && !Schema::hasTable('flowscope_collector_cursors') && !Schema::hasTable('flowscope_machine_daily'), 'upgraded uninstall rolls back latest machine migration batch');
verify(Schema::hasTable('flowscope_server_settings') && DB::table('flowscope_server_settings')->count() === 2, 'native latest-batch rollback preserves older legacy settings');
verify(DB::table('v2_server_machine')->count() === 2, 'uninstall preserves native machines');
verify(Schema::hasTable('v2_server') && DB::table('v2_server')->count() === 2 && Schema::hasTable('v2_stat_server'), 'uninstall preserves core server/traffic schema and data');
verify(!str_contains(view('admin', $viewData)->render(), 'data-flowscope-loader'), 'native UI restored after uninstall');
foreach (array_keys($assetCases) as $asset) {
    verify(flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset)->getStatusCode() === 404, 'canonical or compatibility asset gated after uninstall: ' . $asset);
}
verify(hash_file('sha256', $bundleFile) === $afterHash, 'uninstall preserves all foreign bytes');
verify($manager->install('flowscope') && $manager->enable('flowscope'), 'native reinstall and enable');
verify(DB::table('flowscope_machine_settings')->count() === 0 && DB::table('flowscope_collector_cursors')->count() === 0 && DB::table('flowscope_machine_daily')->count() === 0, 'reinstall machine migration state clean');
verify(DB::table('flowscope_server_settings')->count() === 2, 'reinstall does not overwrite retained legacy settings');
verify(substr_count(view('admin', $viewData)->render(), 'data-flowscope-loader') === 1, 'single loader after reinstall');

foreach (['missing' => '', 'duplicate' => AdminCompatibility::SIGNATURES['machineIdentity']] as $case => $replacement) {
    $candidate = $case === 'missing'
        ? str_replace(AdminCompatibility::SIGNATURES['machineIdentity'], $replacement, $originalBundle)
        : $originalBundle . $replacement;
    file_put_contents($bundleFile, $candidate);
    verify(!$compatibility->inspect()['supported'], $case . ' structural signature fails closed');
}
file_put_contents($bundleFile, $originalBundle . $foreignBefore . $foreignAfter);
$manifestPath = public_path('assets/admin/manifest.json');
$manifest = file_get_contents($manifestPath);
file_put_contents($manifestPath, str_replace(AdminCompatibility::BUILD, 'unsupported.js', $manifest));
verify(!$compatibility->inspect()['supported'], 'unknown Admin build fails closed');
file_put_contents($manifestPath, $manifest);
verify($compatibility->inspect()['supported'], 'foreign modifications retained after compatibility checks');
verify(hash_file('sha256', $bundleFile) === $afterHash, 'lifecycle never writes shared bundle');
verify(hash_file('sha256', $nativeView) === hash_file('sha256', $xboardPath . '/resources/views/admin.blade.php'), 'native Blade source byte-for-byte preserved');

echo "PASS integration: $checks checks\n";
