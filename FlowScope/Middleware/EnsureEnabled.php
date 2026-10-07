<?php

declare(strict_types=1);

namespace Plugin\Flowscope\Middleware;

use App\Models\Plugin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEnabled
{
    public const CODE = 'flowscope';

    public static function isEnabled(): bool
    {
        // Do not cache this beyond the current call: Octane routes and callbacks
        // can outlive a plugin state change.
        return (bool) Plugin::query()->where('code', self::CODE)->value('is_enabled');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::isEnabled()) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return $next($request);
    }
}
