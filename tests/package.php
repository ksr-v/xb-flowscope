<?php

declare(strict_types=1);

// Exercise the shipped archive, not the repository's plugin autoloader.
// The native manager and its dependencies are borrowed read-only; every native
// upload, migration, published asset, and DB row belongs to a unique fixture.
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Enable the PHP zip extension before running tests/package.php.');
}

$root = dirname(__DIR__);
$sourceConfig = json_decode(file_get_contents($root . '/FlowScope/config.json'), true, 512, JSON_THROW_ON_ERROR);
$archive = $root . '/dist/flowscope-' . $sourceConfig['version'] . '.zip';
if (!is_file($archive)) {
    throw new RuntimeException('Build the release archive with scripts/package.php first.');
}

$checks = 0;
function packageVerify(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }
    ++$checks;
    echo 'PASS ' . $message . PHP_EOL;
}

$zip = new ZipArchive();
packageVerify($zip->open($archive) === true, 'release archive opens');
$entries = [];
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $name = $zip->getNameIndex($index);
    packageVerify(
        is_string($name) && str_starts_with($name, 'Flowscope/') && !str_contains($name, '\\')
        && !preg_match('~(?:^|/)\.\.(?:/|$)~', $name),
        'native case-sensitive ZIP root and safe path: ' . $name,
    );
    $entries[] = $name;
}
packageVerify(count($entries) === count(array_unique($entries)), 'archive paths are unique');

$required = [
    'Plugin.php', 'config.json', 'routes/api.php',
    'Controllers/TrafficController.php', 'Controllers/AssetController.php',
    'Controllers/CollectorController.php',
    'Controllers/InstallController.php', 'Services/InstallService.php',
    'Middleware/EnsureEnabled.php', 'Models/ServerSetting.php',
    'Models/MachineSetting.php', 'Models/CollectorCursor.php', 'Models/MachineDaily.php',
    'Services/AdminIntegration.php', 'Services/AdminCompatibility.php',
    'Services/TrafficUsageService.php', 'Services/TrafficCycleService.php',
    'Services/QuotaService.php', 'Services/ServerIdentityService.php',
    'Services/MachineIdentityService.php', 'Services/CollectorService.php',
    'resources/views/admin.blade.php', 'resources/assets/flowscope.js', 'resources/assets/flowscope.css',
    'database/migrations/2026_10_07_000001_create_flowscope_server_settings_table.php',
    'database/migrations/2026_10_07_000002_create_flowscope_machine_tables.php',
    'database/migrations/2026_10_07_000003_create_flowscope_install_tickets_table.php',
    'database/migrations/2026_10_07_000004_create_flowscope_machine_addresses_table.php',
];
foreach ($required as $file) {
    $bytes = $zip->getFromName('Flowscope/' . $file);
    packageVerify($bytes !== false, 'archive contains ' . $file);
    packageVerify(hash('sha256', $bytes) === hash_file('sha256', $root . '/FlowScope/' . $file), 'archive matches current source: ' . $file);
}
$zipConfig = json_decode($zip->getFromName('Flowscope/config.json'), true, 512, JSON_THROW_ON_ERROR);
packageVerify($zipConfig['code'] === 'flowscope' && $zipConfig['version'] === $sourceConfig['version'], 'archive native plugin metadata');
$zip->close();

$app = require __DIR__ . '/bootstrap.php';

use App\Models\Plugin as PluginRecord;
use App\Services\Plugin\PluginManager;
use Composer\Autoload\ClassLoader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

$fixture = __DIR__ . '/.runtime/packages/' . bin2hex(random_bytes(8));
foreach (['/plugins', '/public/assets/admin/assets', '/resources/views', '/storage/framework/views', '/storage/framework/cache', '/storage/logs', '/bootstrap/cache'] as $path) {
    File::ensureDirectoryExists($fixture . $path);
}
$app->setBasePath($fixture);
$app->useStoragePath($fixture . '/storage');
$app['config']->set('view.paths', [$fixture . '/resources/views']);
$app['config']->set('view.compiled', $fixture . '/storage/framework/views');
$app['config']->set('logging.channels.single.path', $fixture . '/storage/logs/test.log');

// Remove only the test harness's source-code fallback. Composer's real Plugin\
// PSR-4 mapping now resolves the files extracted by PluginManager::upload().
foreach (spl_autoload_functions() as $autoload) {
    if ($autoload instanceof Closure && (new ReflectionFunction($autoload))->getFileName() === __DIR__ . '/bootstrap.php') {
        spl_autoload_unregister($autoload);
    }
}
packageVerify(!class_exists('Plugin\\Flowscope\\Plugin', false), 'repository plugin class has not been preloaded');
$composerConfigured = false;
foreach (ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->addPsr4('Plugin\\', $fixture . '/plugins', true);
    $composerConfigured = true;
}
packageVerify($composerConfigured, 'native Composer PSR-4 resolves extracted plugin directory');

$sourcePaths = [
    '/public/assets/admin/assets/index-CEIYH7i8.js',
    '/public/assets/admin/manifest.json',
    '/resources/views/admin.blade.php',
    '/app/Services/Plugin/PluginManager.php',
    '/composer.json',
];
$sourceHashes = [];
foreach ($sourcePaths as $path) {
    $sourceHashes[$path] = hash_file('sha256', $xboardPath . $path);
}
copy($xboardPath . '/public/assets/admin/manifest.json', $fixture . '/public/assets/admin/manifest.json');
copy($xboardPath . '/public/assets/admin/assets/index-CEIYH7i8.js', $fixture . '/public/assets/admin/assets/index-CEIYH7i8.js');
copy($xboardPath . '/resources/views/admin.blade.php', $fixture . '/resources/views/admin.blade.php');
$foreign = "\n/* package-test-foreign-owner:preserve */\n";
file_put_contents($fixture . '/public/assets/admin/assets/index-CEIYH7i8.js', $foreign, FILE_APPEND);
$foreignHash = hash_file('sha256', $fixture . '/public/assets/admin/assets/index-CEIYH7i8.js');
DB::table('v2_server')->insert(['id' => 71, 'type' => 'vless', 'name' => 'Native package fixture']);
DB::table('v2_server_machine')->insert(['id' => 71, 'name' => 'Native machine package fixture', 'token' => str_repeat('d', 32), 'is_active' => true]);

$manager = new PluginManager();
$upload = new UploadedFile($archive, basename($archive), 'application/zip', null, true);
packageVerify($manager->upload($upload), 'native PluginManager accepts the release ZIP');
packageVerify(is_file($fixture . '/plugins/Flowscope/config.json'), 'native upload creates Flowscope directory');
packageVerify(!PluginRecord::where('code', 'flowscope')->exists(), 'upload does not silently install or enable plugin');
packageVerify($manager->install('flowscope'), 'uploaded archive installs through native manager');
packageVerify(Schema::hasTable('flowscope_server_settings'), 'archived migration creates only plugin settings table');
foreach (['flowscope_machine_settings', 'flowscope_collector_cursors', 'flowscope_machine_daily'] as $table) {
    packageVerify(Schema::hasTable($table), 'archived migration creates owned machine table: ' . $table);
}
packageVerify(!PluginRecord::where('code', 'flowscope')->value('is_enabled'), 'archived installation remains disabled');
$classFile = (new ReflectionClass('Plugin\\Flowscope\\Plugin'))->getFileName();
packageVerify(realpath($classFile) === realpath($fixture . '/plugins/Flowscope/Plugin.php'), 'Plugin class is loaded from uploaded archive, not repository source');
packageVerify(is_file($fixture . '/public/plugins/flowscope/flowscope.js'), 'native install publishes archived plugin assets');
packageVerify($manager->enable('flowscope'), 'uploaded archive enables through native manager');
packageVerify(flowScopeRequest('GET', '/api/v2/flowscope/dashboard')->getStatusCode() === 200, 'archived backend serves authorized API');
packageVerify(flowScopeRequest('GET', '/api/v2/flowscope/dashboard', [], null)->getStatusCode() === 403, 'archived backend preserves native admin authorization');
packageVerify(flowScopeRequest('GET', '/api/v2/flowscope/server/vless/71')->getStatusCode() === 410, 'archived legacy node API is explicitly retired');
$machineSettings = ['enabled' => true, 'display_name' => 'Packaged NIC', 'quota_bytes' => '1099511627776', 'traffic_mode' => 'both', 'cycle_start_day' => 1];
packageVerify(flowScopeRequest('POST', '/api/v2/flowscope/machine/71', $machineSettings)->getStatusCode() === 200, 'archived Admin API saves exact machine identity');
$collectorPayload = ['protocol' => 1, 'machine_id' => 71, 'token' => str_repeat('d', 32), 'collector_id' => str_repeat('e', 32), 'sequence' => '1', 'started_at' => time() - 10, 'ended_at' => time(), 'boot_id' => '0a6bb2f4-b88d-4ef2-b5a0-828dcbdb2255', 'interfaces' => ['eth0'], 'rx_bytes' => '123', 'tx_bytes' => '456'];
$collectorResponse = flowScopeRequest('POST', '/api/v2/flowscope/collect', $collectorPayload, null);
packageVerify($collectorResponse->getStatusCode() === 200, 'archived collector authenticates machine independently of Admin');
packageVerify(json_decode($collectorResponse->getContent(), true)['data'] === ['accepted' => true, 'sequence' => '1', 'duplicate' => false], 'archived collector protocol acknowledgement');
$duplicateResponse = flowScopeRequest('POST', '/api/v2/flowscope/collect', $collectorPayload, null);
packageVerify(json_decode($duplicateResponse->getContent(), true)['data']['duplicate'] === true, 'archived collector retries are idempotent');
packageVerify(DB::table('flowscope_collector_cursors')->count() === 1 && DB::table('flowscope_machine_daily')->count() >= 1, 'archived collector persists cursor and daily buckets');
packageVerify(!str_contains(flowScopeRequest('GET', '/api/v2/flowscope/machine/71')->getContent(), str_repeat('d',32)), 'archived machine metadata omits native token');
$archivedCollectorFile = (new ReflectionClass('Plugin\\Flowscope\\Services\\CollectorService'))->getFileName();
packageVerify(realpath($archivedCollectorFile) === realpath($fixture . '/plugins/Flowscope/Services/CollectorService.php'), 'collector service executes from extracted ZIP');
$assetCases = [
    'script' => ['flowscope.js', 'application/javascript; charset=UTF-8'],
    'style' => ['flowscope.css', 'text/css; charset=UTF-8'],
    'flowscope.js' => ['flowscope.js', 'application/javascript; charset=UTF-8'],
    'flowscope.css' => ['flowscope.css', 'text/css; charset=UTF-8'],
];
foreach ($assetCases as $asset => [$file, $contentType]) {
    $assetResponse = flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset, [], null);
    packageVerify($assetResponse->getStatusCode() === 200, 'archived canonical or compatibility asset: ' . $asset);
    packageVerify($assetResponse->headers->get('Content-Type') === $contentType, 'archived asset fixed MIME: ' . $asset);
    ob_start();
    $assetResponse->sendContent();
    $assetBody = ob_get_clean();
    packageVerify(hash('sha256', $assetBody) === hash_file('sha256', $fixture . '/plugins/Flowscope/resources/assets/' . $file), 'archived asset response exact bytes: ' . $asset);
    packageVerify(str_contains($assetResponse->headers->get('Cache-Control', ''), 'no-store')
        && $assetResponse->headers->get('X-Content-Type-Options') === 'nosniff'
        && $assetResponse->headers->get('Cross-Origin-Resource-Policy') === 'same-origin', 'archived asset response protections: ' . $asset);
}
foreach (['config.json', 'script.js', 'style.css', '%2e%2e%2fconfig.json'] as $asset) {
    packageVerify(flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset)->getStatusCode() === 404, 'archived whitelist refuses unknown or traversal key: ' . $asset);
}
$adminHtml = view('admin', ['title' => 'Packaged FlowScope', 'version' => 'test', 'logo' => '', 'secure_path' => 'package-admin'])->render();
packageVerify(str_contains($adminHtml, 'src="/api/v2/flowscope/assets/script?v=' . $sourceConfig['version'] . '"')
    && str_contains($adminHtml, 'href="/api/v2/flowscope/assets/style?v=' . $sourceConfig['version'] . '"')
    && !str_contains($adminHtml, '/api/v2/flowscope/assets/flowscope.'), 'uploaded archive injects only extensionless canonical assets');
$status = json_decode(flowScopeRequest('GET', '/api/v2/flowscope/status')->getContent(), true, 512, JSON_THROW_ON_ERROR);
packageVerify($status['enabled'] === true && $status['compatibility']['supported'] === true, 'uploaded archive accepts unrelated foreign Admin modification');
packageVerify(hash_file('sha256', $fixture . '/public/assets/admin/assets/index-CEIYH7i8.js') === $foreignHash, 'native upload/install/enable preserves foreign shared bundle bytes');

$duplicateRejected = false;
try {
    $manager->upload($upload);
} catch (Exception $exception) {
    $duplicateRejected = str_contains($exception->getMessage(), '版本');
}
packageVerify($duplicateRejected, 'native manager rejects a duplicate same-version archive');
packageVerify(hash_file('sha256', $fixture . '/plugins/Flowscope/Plugin.php') === hash_file('sha256', $root . '/FlowScope/Plugin.php'), 'rejected duplicate leaves installed plugin intact');

$issue = flowScopeRequest('POST', '/api/v2/flowscope/machine/71/install-ticket', ['panel_url'=>'https://localhost']);
packageVerify($issue->getStatusCode() === 200, 'archived one-command installer issues an Admin credential');
$installCommand = json_decode($issue->getContent(), true)['data']['command'];
preg_match('/[a-f0-9]{64}/', $installCommand, $credential);
packageVerify(isset($credential[0]), 'archived command contains short installation credential');
$exchange = flowScopeRequest('POST', '/api/v2/flowscope/install/redeem', ['ticket'=>$credential[0]], null);
packageVerify($exchange->getStatusCode() === 200, 'archived machine credential exchange works');
packageVerify(flowScopeRequest('POST', '/api/v2/flowscope/install/redeem', ['ticket'=>$credential[0]], null)->getStatusCode() === 403, 'archived credential single use');
packageVerify(realpath((new ReflectionClass(Plugin\Flowscope\Services\InstallService::class))->getFileName()) === realpath($fixture.'/plugins/Flowscope/Services/InstallService.php'), 'installer service executes from extracted ZIP');
packageVerify($manager->disable('flowscope'), 'uploaded archive disables through native manager');
packageVerify(flowScopeRequest('POST', '/api/v2/flowscope/collect', $collectorPayload, null)->getStatusCode() === 404, 'archived collector immediately gated on disable');
foreach (array_keys($assetCases) as $asset) {
    packageVerify(flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset)->getStatusCode() === 404, 'archived canonical or compatibility asset gated while disabled: ' . $asset);
}
packageVerify($manager->enable('flowscope'), 'uploaded archive re-enables through native manager');
packageVerify($manager->uninstall('flowscope'), 'uploaded archive uninstalls through native manager');
packageVerify(!Schema::hasTable('flowscope_server_settings'), 'native uninstall rolls back archived plugin migration');
packageVerify(!Schema::hasTable('flowscope_machine_addresses'), 'fresh uninstall removes owned address table');
packageVerify(!Schema::hasTable('flowscope_install_tickets'), 'fresh native uninstall removes installation credentials');
foreach (['flowscope_machine_settings', 'flowscope_collector_cursors', 'flowscope_machine_daily'] as $table) {
    packageVerify(!Schema::hasTable($table), 'fresh native uninstall removes owned machine table: ' . $table);
}
packageVerify(!PluginRecord::where('code', 'flowscope')->exists(), 'native uninstall removes plugin registration');
packageVerify(Schema::hasTable('v2_stat_server') && DB::table('v2_server')->where('id', 71)->value('name') === 'Native package fixture', 'native uninstall preserves native schema and rows');
packageVerify(DB::table('v2_server_machine')->where('id', 71)->value('name') === 'Native machine package fixture', 'native uninstall preserves core machine identity');
packageVerify(flowScopeRequest('POST', '/api/v2/flowscope/collect', $collectorPayload, null)->getStatusCode() === 404, 'archived collector inert after uninstall');
packageVerify(flowScopeRequest('GET', '/api/v2/flowscope/dashboard')->getStatusCode() === 404, 'archived retained API route becomes inert after uninstall');
foreach (array_keys($assetCases) as $asset) {
    packageVerify(flowScopeRequest('GET', '/api/v2/flowscope/assets/' . $asset)->getStatusCode() === 404, 'archived canonical or compatibility asset gated after uninstall: ' . $asset);
}
packageVerify(hash_file('sha256', $fixture . '/public/assets/admin/assets/index-CEIYH7i8.js') === $foreignHash, 'native uninstall preserves foreign shared bundle bytes');
foreach ($sourceHashes as $path => $hash) {
    packageVerify(hash_file('sha256', $xboardPath . $path) === $hash, 'read-only Xboard source preserved: ' . $path);
}

echo 'Package checks passed: ' . $checks . PHP_EOL;
echo 'Archive SHA256: ' . hash_file('sha256', $archive) . PHP_EOL;
