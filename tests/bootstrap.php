<?php
declare(strict_types=1);

// Dependencies are borrowed read-only. All runtime files and DB state are isolated.
$xboardPath = getenv('XBOARD_PATH') ?: 'D:/Xboard-Independent/source/Xboard';
if (!is_file($xboardPath . '/vendor/autoload.php')) {
    throw new RuntimeException('Set XBOARD_PATH to an audited Xboard checkout with composer dependencies.');
}
require_once $xboardPath . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\Flowscope\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/FlowScope/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

final class FlowScopeTestApplication extends Application
{
    public function runningInConsole() { return false; }
}

$runtime = __DIR__ . '/.runtime';
foreach (['', '/storage/framework/views', '/storage/framework/cache', '/storage/logs', '/bootstrap/cache', '/public', '/plugins', '/resources/views'] as $path) {
    if (!is_dir($runtime . $path)) {
        mkdir($runtime . $path, 0777, true);
    }
}
$app = new FlowScopeTestApplication($runtime);
$app->useStoragePath($runtime . '/storage');
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['name' => 'FlowScope Test', 'env' => 'testing', 'debug' => false, 'key' => 'base64:' . base64_encode(str_repeat('x', 32)), 'locale' => 'en', 'fallback_locale' => 'en', 'timezone' => 'Asia/Shanghai', 'url' => 'http://localhost'],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]], 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
    'view' => ['paths' => [$runtime . '/resources/views'], 'compiled' => $runtime . '/storage/framework/views'],
    'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $runtime . '/storage/logs/test.log', 'level' => 'debug']]],
    'auth' => ['defaults' => ['guard' => 'sanctum'], 'guards' => ['sanctum' => ['driver' => 'flowscope-test']], 'providers' => []],
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
] as $provider) {
    $app->register($provider);
}
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Illuminate\Foundation\Exceptions\Handler::class);
$app->boot();
date_default_timezone_set(config('app.timezone'));

Schema::create('v2_server_machine', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('token', 64)->unique();
    $table->text('notes')->nullable();
    $table->boolean('is_active')->default(true);
    $table->unsignedInteger('last_seen_at')->nullable();
    $table->json('load_status')->nullable();
    $table->timestamps();
});
Schema::create('v2_server', function (Blueprint $table): void {
    $table->id();
    $table->string('type');
    $table->string('code')->nullable();
    $table->string('name');
    $table->unsignedBigInteger('parent_id')->nullable();
    $table->unsignedBigInteger('machine_id')->nullable();
    $table->boolean('enabled')->default(true);
    $table->unsignedBigInteger('u')->default(0);
    $table->unsignedBigInteger('d')->default(0);
    $table->timestamps();
});
Schema::create('v2_stat_server', function (Blueprint $table): void {
    $table->id();
    $table->integer('server_id')->index();
    $table->string('server_type', 11);
    $table->bigInteger('u')->default(0);
    $table->bigInteger('d')->default(0);
    $table->char('record_type', 1)->default('d');
    $table->integer('record_at')->index();
    $table->integer('created_at')->nullable();
    $table->integer('updated_at')->nullable();
    $table->unique(['server_id', 'server_type', 'record_at']);
});
Schema::create('v2_plugins', function (Blueprint $table): void {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->string('version');
    $table->string('type')->default('feature');
    $table->boolean('is_enabled')->default(false);
    $table->text('config')->nullable();
    $table->timestamp('installed_at')->nullable();
    $table->timestamps();
});

final class FlowScopeTestAuth
{
    public static ?App\Models\User $user = null;
}
$app['auth']->extend('flowscope-test', static fn ($app) => new Illuminate\Auth\RequestGuard(static fn () => FlowScopeTestAuth::$user, $app['request']));
$app['router']->aliasMiddleware('admin', App\Http\Middleware\Admin::class);
$app['router']->middlewareGroup('api', []);
$app['router']->middlewareGroup('web', []);

function flowScopeRequest(string $method, string $uri, array $body = [], ?bool $admin = true, array $headers = []): Symfony\Component\HttpFoundation\Response
{
    global $app;
    FlowScopeTestAuth::$user = $admin === null ? null : (new App\Models\User())->forceFill(['id' => 1, 'is_admin' => $admin]);
    $request = Illuminate\Http\Request::create($uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $app->instance('request', $request);
    foreach ($headers as $key => $value) { $request->headers->set($key, $value); }
    $app['auth']->forgetGuards();
    try {
        return $app['router']->dispatch($request);
    } catch (Throwable $exception) {
        return $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $exception);
    }
}

return $app;
