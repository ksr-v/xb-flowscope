<?php
declare(strict_types=1);

// TEST ONLY: native FlowScope routes/models on an isolated, persistent SQLite DB.
// Never load Xboard's .env, bootstrap/app.php or a production database config.
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Plugin\Flowscope\Models\CollectorCursor;
use Plugin\Flowscope\Models\MachineDaily;
use Plugin\Flowscope\Models\MachineSetting;
use Plugin\Flowscope\Services\QuotaService;

function flowScopeVmPath(string $path): string
{
    if (!str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, '//') ||
        preg_match('/[\x00-\x1f\x7f]/', $path) ||
        preg_match('~(?:^|/)\.\.?(/|$)~', $path)) {
        throw new RuntimeException('VM fixture paths must be absolute without traversal.');
    }
    return rtrim($path, '/');
}

function flowScopeVmAssertSafeAncestors(string $path, string $leafType): void
{
    // Inspect EVERY existing component before any mkdir/fopen. Checking only
    // realpath after creation would already have written through an ancestor
    // symlink. This fixture runs in a private, operator-controlled test tree.
    $components = explode('/', ltrim($path, '/'));
    $current = '';
    foreach ($components as $index => $component) {
        $current .= '/'.$component;
        $info = @lstat($current);
        if ($info === false) {
            if (file_exists($current) || is_link($current)) {
                throw new RuntimeException('Cannot safely inspect a VM fixture path.');
            }
            continue; // A missing descendant is safe to create after this preflight.
        }
        $kind = $info['mode'] & 0170000;
        $leaf = $index === count($components) - 1;
        if ($kind === 0120000 || realpath($current) !== $current) {
            throw new RuntimeException('VM fixture paths must not traverse symlinks or noncanonical ancestors.');
        }
        if ((!$leaf || $leafType === 'directory') && $kind !== 0040000) {
            throw new RuntimeException('A VM fixture directory component is not a directory.');
        }
        if ($leaf && $leafType === 'file' && $kind !== 0100000) {
            throw new RuntimeException('VM fixture database must be a regular file.');
        }
        if ($kind === 0040000 && !is_executable($current)) {
            throw new RuntimeException('A VM fixture directory component is not searchable.');
        }
    }
}

$fixtureRoot = '/home/codex/flowscope-vm-test';
$runtime = flowScopeVmPath(getenv('FLOWSCOPE_VM_RUNTIME') ?: $fixtureRoot.'/runtime/api');
if (!str_starts_with($runtime, $fixtureRoot.'/')) {
    throw new RuntimeException('VM fixture runtime must remain under /home/codex/flowscope-vm-test.');
}
$database = flowScopeVmPath(getenv('FLOWSCOPE_VM_DB') ?: $runtime.'/database.sqlite3');
if (!str_starts_with($database, $runtime.'/') || !str_ends_with($database, '.sqlite3')) {
    throw new RuntimeException('VM fixture SQLite DB must remain inside the private fixture runtime.');
}
$initializing = defined('FLOWSCOPE_VM_INITIALIZING') && FLOWSCOPE_VM_INITIALIZING === true;
if (PHP_OS_FAMILY !== 'Linux') {
    throw new RuntimeException('This fixture is deliberately Linux-VM-only.');
}
$runtimeDirectories = [$runtime, dirname($database), $runtime.'/storage/framework/views',
    $runtime.'/storage/framework/cache', $runtime.'/storage/logs', $runtime.'/bootstrap/cache',
    $runtime.'/public', $runtime.'/resources/views'];
flowScopeVmAssertSafeAncestors($fixtureRoot, 'directory');
foreach ($runtimeDirectories as $directory) { flowScopeVmAssertSafeAncestors($directory, 'directory'); }
flowScopeVmAssertSafeAncestors($database, 'file');
if ($initializing) {
    foreach ($runtimeDirectories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create private VM fixture runtime.');
        }
    }
    if (!file_exists($database)) {
        $handle = fopen($database, 'x');
        if ($handle === false) { throw new RuntimeException('Cannot create VM fixture database.'); }
        fclose($handle);
        chmod($database, 0600);
    }
}
if (!is_dir($runtime) || is_link($database) || !is_file($database) ||
    (fileperms($database) & 0777) !== 0600) {
    throw new RuntimeException('Run fixture-control.php init first; fixture DB must be a private regular file.');
}
$resolvedRuntime = realpath($runtime);
$resolvedDatabase = realpath($database);
if ($resolvedRuntime !== $runtime || $resolvedDatabase !== $database) {
    throw new RuntimeException('VM fixture paths must not traverse symlinked ancestors.');
}

$xboardPath = getenv('XBOARD_PATH');
if (!$xboardPath || !is_file($xboardPath.'/vendor/autoload.php')) {
    throw new RuntimeException('Set XBOARD_PATH to the isolated audited checkout containing vendor dependencies.');
}
require_once $xboardPath.'/vendor/autoload.php';
$sourceRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($sourceRoot): void {
    $prefix = 'Plugin\\Flowscope\\';
    if (str_starts_with($class, $prefix)) {
        $file = $sourceRoot.'/FlowScope/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) { require_once $file; }
    }
});

final class FlowScopeVmApplication extends Application
{
    public function runningInConsole() { return false; }
}

$app = new FlowScopeVmApplication($runtime);
$app->useStoragePath($runtime.'/storage');
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['name' => 'FlowScope VM TEST ONLY', 'env' => 'testing', 'debug' => false,
        'key' => 'base64:'.base64_encode(str_repeat('v', 32)), 'locale' => 'en', 'fallback_locale' => 'en',
        'timezone' => 'Asia/Shanghai', 'url' => 'http://127.0.0.1:18080'],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
        'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
        'busy_timeout' => 10000, 'journal_mode' => 'WAL', 'synchronous' => 'FULL',
    ]], 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
    'view' => ['paths' => [$runtime.'/resources/views'], 'compiled' => $runtime.'/storage/framework/views'],
    'logging' => ['default' => 'single', 'channels' => ['single' => [
        'driver' => 'single', 'path' => $runtime.'/storage/logs/fixture.log', 'level' => 'warning']]],
    'auth' => ['defaults' => ['guard' => 'sanctum'], 'guards' => ['sanctum' => ['driver' => 'flowscope-vm-test']], 'providers' => []],
    'session' => ['driver' => 'array'],
]));
$app->instance('request', Illuminate\Http\Request::create('/'));
Facade::clearResolvedInstances();
Facade::setFacadeApplication($app);
foreach ([
    Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Database\MigrationServiceProvider::class,
    Illuminate\Cache\CacheServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class,
    Illuminate\Auth\AuthServiceProvider::class,
    Illuminate\Foundation\Providers\FoundationServiceProvider::class,
] as $provider) { $app->register($provider); }
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Illuminate\Foundation\Exceptions\Handler::class);
$app->boot();
$app->instance(Plugin\Flowscope\Services\InstallService::class,
    new Plugin\Flowscope\Services\InstallService($sourceRoot.'/collector'));
date_default_timezone_set('Asia/Shanghai');
DB::statement('PRAGMA busy_timeout=10000');
DB::statement('PRAGMA journal_mode=WAL');
DB::statement('PRAGMA synchronous=FULL');

final class FlowScopeVmAuth
{
    public static ?App\Models\User $user = null;
}
$app['auth']->extend('flowscope-vm-test', static fn ($app) => new Illuminate\Auth\RequestGuard(
    static fn () => FlowScopeVmAuth::$user, $app['request']));
$app['router']->aliasMiddleware('admin', App\Http\Middleware\Admin::class);
$app['router']->middlewareGroup('api', []);
$app['router']->middlewareGroup('web', []);
require $sourceRoot.'/FlowScope/routes/api.php';

function flowScopeVmInitialize(array $fixture): void
{
    if (($fixture['machine_id'] ?? null) !== 41 || !is_string($fixture['token'] ?? null) ||
        strlen($fixture['token']) < 16 || strlen($fixture['token']) > 256) {
        throw new RuntimeException('Fixture config requires machine_id integer 41 and a generated test token of 16..256 characters.');
    }
    $existing = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    if ($existing !== [] && !Schema::hasTable('flowscope_vm_meta')) {
        throw new RuntimeException('Refusing an unrecognized nonempty database; no data was removed.');
    }
    if (Schema::hasTable('flowscope_vm_meta')) {
        if (DB::table('flowscope_vm_meta')->where('key', 'schema_version')->value('value') !== '1' ||
            !hash_equals((string) DB::table('v2_server_machine')->where('id', 41)->value('token'), $fixture['token'])) {
            throw new RuntimeException('Existing fixture schema or test credentials differ; state was preserved.');
        }
        return; // Idempotent init MUST NOT re-enable/reset/reseed an existing test DB.
    }
    DB::transaction(static function () use ($fixture): void {
        Schema::create('flowscope_vm_meta', static function (Blueprint $table): void {
            $table->string('key')->primary(); $table->text('value');
        });
        Schema::create('v2_server_machine', static function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('token', 256)->unique();
            $table->text('notes')->nullable(); $table->boolean('is_active')->default(true);
            $table->unsignedInteger('last_seen_at')->nullable(); $table->json('load_status')->nullable(); $table->timestamps();
        });
        Schema::create('v2_plugins', static function (Blueprint $table): void {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->string('version');
            $table->string('type')->default('feature'); $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable(); $table->timestamp('installed_at')->nullable(); $table->timestamps();
        });
        foreach (glob(dirname(__DIR__, 2).'/FlowScope/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        DB::table('v2_server_machine')->insert(['id' => 41, 'name' => 'FlowScope disposable VM fixture',
            'token' => $fixture['token'], 'is_active' => true]);
        DB::table('v2_plugins')->insert(['code' => 'flowscope', 'name' => 'FlowScope', 'version' => Plugin\Flowscope\Plugin::VERSION, 'is_enabled' => true]);
        MachineSetting::query()->create(['machine_id' => 41, 'enabled' => true, 'quota_bytes' => '1073741824',
            'traffic_mode' => 'both', 'cycle_start_day' => 1]);
        DB::table('flowscope_vm_meta')->insert([
            ['key' => 'schema_version', 'value' => '1'], ['key' => 'initialized_at', 'value' => (string) time()],
            ['key' => 'warning', 'value' => 'TEST ONLY: isolated native models, fixture Admin auth, file SQLite'],
        ]);
    });
}

function flowScopeVmRequireInitialized(): void
{
    if (!Schema::hasTable('flowscope_vm_meta') ||
        DB::table('flowscope_vm_meta')->where('key', 'schema_version')->value('value') !== '1') {
        throw new RuntimeException('Initialize the dedicated VM fixture using the CLI first.');
    }
}

function flowScopeVmDispatch(Illuminate\Http\Request $request, bool $fixtureAdmin = false): Symfony\Component\HttpFoundation\Response
{
    global $app;
    // Deliberately NOT production authentication: only the loopback router/CLI may opt in.
    FlowScopeVmAuth::$user = $fixtureAdmin ? (new App\Models\User())->forceFill(['id' => 1, 'is_admin' => true]) : null;
    $app->instance('request', $request);
    $app['auth']->forgetGuards();
    try { return $app['router']->dispatch($request); }
    catch (Throwable $exception) { return $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $exception); }
}

function flowScopeVmAudit(): array
{
    // A CLI audit may overlap the HTTP receiver. Read cursor and daily rows from
    // one SQLite snapshot so accepted totals cannot straddle two transactions.
    return DB::transaction(static function (): array {
    $bytes = new QuotaService();
    $rx = $tx = '0';
    $daily = [];
    foreach (MachineDaily::query()->where('machine_id', 41)->orderBy('record_at')->get() as $row) {
        $daily[] = ['record_at' => $row->record_at, 'timezone' => $row->timezone, 'rx_bytes' => $row->rx_bytes, 'tx_bytes' => $row->tx_bytes];
        $rx = $bytes->add($rx, $row->rx_bytes); $tx = $bytes->add($tx, $row->tx_bytes);
    }
    $cursor = CollectorCursor::query()->where('machine_id', 41)->first();
    $setting = MachineSetting::query()->where('machine_id', 41)->first();
    return ['fixture' => 'TEST ONLY: file SQLite; fixture Admin auth is not production Admin auth', 'schema_version' => '1',
        'machine_id' => 41, 'plugin_enabled' => (bool) DB::table('v2_plugins')->where('code', 'flowscope')->value('is_enabled'),
        'machine_active' => (bool) DB::table('v2_server_machine')->where('id', 41)->value('is_active'),
        'database_journal_mode' => DB::selectOne('PRAGMA journal_mode')->journal_mode,
        'cursor' => $cursor ? $cursor->only(['collector_id', 'sequence', 'last_digest', 'first_sample_at', 'last_sample_at',
            'last_report_at', 'boot_id', 'interfaces', 'resets', 'boot_changes']) : null,
        'settings' => $setting ? $setting->only(['enabled', 'quota_bytes', 'traffic_mode', 'cycle_start_day']) : null,
        'daily' => $daily, 'totals' => ['rx_bytes' => $rx, 'tx_bytes' => $tx, 'both_bytes' => $bytes->add($rx, $tx)]];
    });
}

return $app;
