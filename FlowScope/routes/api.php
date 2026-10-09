<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugin\Flowscope\Controllers\AssetController;
use Plugin\Flowscope\Controllers\TrafficController;
use Plugin\Flowscope\Controllers\CollectorController;
use Plugin\Flowscope\Controllers\InstallController;
use Plugin\Flowscope\Controllers\AddressController;
use Plugin\Flowscope\Middleware\EnsureEnabled;

Route::prefix('api/v2/flowscope')->group(function (): void {
    // Public metadata contains no identities, traffic, quotas, or credentials.
    Route::get('status', [AssetController::class, 'status']);

    // A script tag cannot supply the Admin Authorization header. Only two
    // public plugin files are addressable, and plugin state still gates access.
    Route::get('assets/{asset}', [AssetController::class, 'show'])
        ->where('asset', '(?:script|style|flowscope\\.(?:js|css))')
        ->middleware(EnsureEnabled::class);

    // Machine authentication is separate from Admin and node-only server.v2.
    Route::post('collect', [CollectorController::class, 'store'])->middleware(EnsureEnabled::class);
    Route::post('node-address', [AddressController::class, 'store'])->middleware(EnsureEnabled::class);
    Route::get('installer', [InstallController::class, 'bootstrap'])->middleware(EnsureEnabled::class);
    Route::get('install/{id}', [InstallController::class, 'shell'])->whereNumber('id')->middleware(EnsureEnabled::class);
    Route::post('install/redeem', [InstallController::class, 'redeem'])->middleware(EnsureEnabled::class);

    Route::middleware([EnsureEnabled::class, 'admin'])->group(function (): void {
        Route::get('dashboard', [TrafficController::class, 'dashboard']);
        Route::get('machines', [TrafficController::class, 'index']);
        Route::get('machine-addresses', [TrafficController::class, 'addresses']);
        Route::get('machine/{id}', [TrafficController::class, 'show'])->whereNumber('id');
        Route::post('machine/{id}', [TrafficController::class, 'store'])->whereNumber('id');
        Route::post('machine/{id}/collector-reset', [TrafficController::class, 'resetCollector'])->whereNumber('id');
        Route::post('machine/{id}/install-ticket', [InstallController::class, 'issue'])->whereNumber('id');
        Route::get('servers', [TrafficController::class, 'legacy']);
        Route::get('server/{type}/{id}', [TrafficController::class, 'legacy'])
            ->where(['type' => '[a-z0-9_]+', 'id' => '[1-9][0-9]*']);
        Route::post('server/{type}/{id}', [TrafficController::class, 'legacy'])
            ->where(['type' => '[a-z0-9_]+', 'id' => '[1-9][0-9]*']);
    });
});
