<?php

declare(strict_types=1);

namespace Plugin\Flowscope\Services;

use Illuminate\View\View;
use Plugin\Flowscope\Middleware\EnsureEnabled;
use Plugin\Flowscope\Plugin;

final class AdminIntegration
{
    private const EVENT = 'composing: admin';
    private const LISTENER = self::class . '@compose';

    public function register(): void
    {
        $events = app('events');

        // Register the native View::composer event by a recognizable class
        // listener. Inspect the actual dispatcher instead of using a static
        // boolean that would break fresh application instances or worker clones.
        foreach ($events->getRawListeners()[self::EVENT] ?? [] as $listener) {
            if ($listener === self::LISTENER) {
                return;
            }
        }

        $events->listen(self::EVENT, self::LISTENER);
    }

    public function compose(View $view): void
    {
        if (!EnsureEnabled::isEnabled()) {
            return;
        }

        $wrapper = dirname(__DIR__) . '/resources/views/admin.blade.php';
        $original = $view->getPath();
        if ($original === $wrapper || !is_file($original) || !is_file($wrapper)) {
            return;
        }

        // Capture the current path and data, including prior foreign composers.
        // Rendering that original by file path cannot re-enter the named admin
        // composer, so its template and modifications remain intact.
        $originalData = $view->getData();
        $view->with('__flowScopeOriginalPath', $original);
        $view->with('__flowScopeOriginalData', $originalData);
        $view->setPath($wrapper);
    }

    public static function publicConfiguration(?AdminCompatibility $compatibility = null): array
    {
        $enabled = EnsureEnabled::isEnabled();

        return [
            'owner' => 'flowscope',
            'version' => Plugin::VERSION,
            'apiBase' => '/api/v2/flowscope',
            'statusUrl' => '/api/v2/flowscope/status',
            'enabled' => $enabled,
            'timezone' => (string) config('app.timezone', 'UTC'),
            'compatibility' => ($compatibility ?? app(AdminCompatibility::class))->inspect(),
        ];
    }

    public static function inject(string $html): string
    {
        if (!EnsureEnabled::isEnabled() || str_contains($html, 'data-flowscope-loader')) {
            return $html;
        }

        $configuration = self::publicConfiguration();
        $encoded = json_encode(
            $configuration,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
        $version = rawurlencode(Plugin::VERSION);
        // Extensionless dynamic URLs avoid reverse proxies that intercept
        // .js/.css paths as static files before they can reach Laravel.
        $addition = "\n" . '<script data-flowscope-loader data-flowscope-asset="1">window.__FLOWSCOPE__=' . $encoded . ';</script>'
            . "\n" . '<link data-flowscope-style data-flowscope-asset="1" rel="stylesheet" href="/api/v2/flowscope/assets/style?v=' . $version . '">'
            . "\n" . '<script data-flowscope-script data-flowscope-asset="1" defer src="/api/v2/flowscope/assets/script?v=' . $version . '"></script>' . "\n";

        // Only inject into an actual complete Admin HTML response.
        $position = strripos($html, '</body>');
        if ($position === false) {
            return $html;
        }

        return substr($html, 0, $position) . $addition . substr($html, $position);
    }
}
