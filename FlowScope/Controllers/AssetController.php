<?php

declare(strict_types=1);

namespace Plugin\Flowscope\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Plugin\Flowscope\Middleware\EnsureEnabled;
use Plugin\Flowscope\Services\AdminCompatibility;
use Plugin\Flowscope\Services\AdminIntegration;
use Symfony\Component\HttpFoundation\Response;

final class AssetController extends Controller
{
    private const ASSETS = [
        'script' => 'flowscope.js',
        'style' => 'flowscope.css',
        // Keep the original names as explicit compatibility aliases. New
        // loaders use extensionless keys to reach Laravel through static-file
        // reverse-proxy rules without requiring changes to the host config.
        'flowscope.js' => 'flowscope.js',
        'flowscope.css' => 'flowscope.css',
    ];

    private const CONTENT_TYPES = [
        'flowscope.js' => 'application/javascript; charset=UTF-8',
        'flowscope.css' => 'text/css; charset=UTF-8',
    ];

    public function show(string $asset): Response
    {
        // Keep the whitelist at the controller boundary as well as in routing.
        if (!EnsureEnabled::isEnabled() || !isset(self::ASSETS[$asset])) {
            return response('Not Found', 404);
        }

        $file = self::ASSETS[$asset];
        $path = dirname(__DIR__) . '/resources/assets/' . $file;
        if (!is_file($path)) {
            return response('Not Found', 404);
        }

        return response()->file($path, [
            'Content-Type' => self::CONTENT_TYPES[$file],
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public function status(AdminCompatibility $compatibility): JsonResponse
    {
        return response()->json(
            AdminIntegration::publicConfiguration($compatibility),
            200,
            ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
