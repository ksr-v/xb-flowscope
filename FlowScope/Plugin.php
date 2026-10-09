<?php

declare(strict_types=1);

namespace Plugin\Flowscope;

use App\Services\Plugin\AbstractPlugin;
use Plugin\Flowscope\Services\AdminIntegration;

final class Plugin extends AbstractPlugin
{
    public const VERSION = '2.4.0';

    public function boot(): void
    {
        if (!app()->runningInConsole()) {
            app(AdminIntegration::class)->register();
        }
    }

    public function install(): void
    {
        // Xboard owns migration execution and installation state.
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        // Routes serve the current plugin assets; there is no shared disk patch.
    }

    public function cleanup(): void
    {
        // Dynamic state gates make callbacks and existing routes inert immediately.
        // Xboard rolls back only this plugin's migrations during uninstall.
    }
}
