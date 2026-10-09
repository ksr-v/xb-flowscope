<?php
declare(strict_types=1);

namespace Plugin\Flowscope\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Plugin\Flowscope\Services\AddressService;

final class AddressController extends Controller
{
    public function store(Request $request, AddressService $addresses): JsonResponse
    {
        // POST JSON only: no credentials in URL, no precedence ambiguity with query fields.
        abort_if($request->query->count() || !$request->isJson() || strlen($request->getContent()) > 2048, 400);
        $v = $request->validate([
            'token' => ['required', 'string', 'max:256'],
            'machine_id' => ['sometimes', 'integer', 'min:1'],
            'node_id' => ['required_without:machine_id', 'integer', 'min:1'],
            'ipv4' => ['required', 'string', 'max:15'],
            'age_seconds' => ['required', 'integer', 'between:0,86400'],
        ]);
        foreach (['machine_id', 'node_id', 'age_seconds'] as $field) {
            abort_if(isset($v[$field]) && !is_int($v[$field]), 422);
        }
        abort_unless(AddressService::valid($v['ipv4']), 422, 'A public IPv4 is required.');
        try {
            return DB::transaction(function () use ($v, $addresses): JsonResponse {
                $node = null;
                if (isset($v['machine_id'])) {
                    $machine = ServerMachine::query()->whereKey($v['machine_id'])->lockForUpdate()->first();
                    abort_unless($machine && hash_equals((string) $machine->token, $v['token']), 401);
                    if (isset($v['node_id'])) {
                        abort_unless(Server::query()->whereKey($v['node_id'])->where('machine_id', $machine->id)->where('enabled', true)->exists(), 403);
                    }
                } else {
                    // Legacy server_token has panel-wide authority, exactly as native ServerV2.
                    // It cannot be represented as a per-node secret. Never accept a claimed machine.
                    $token = admin_setting('server_token');
                    abort_unless(is_string($token) && $token !== '' && hash_equals($token, $v['token']), 401);
                    $node = Server::query()->whereKey($v['node_id'])->where('enabled', true)->lockForUpdate()->first();
                    abort_unless($node, 403);
                    $machine = $node->machine_id ? ServerMachine::query()->whereKey($node->machine_id)->lockForUpdate()->first() : null;
                }
                abort_if($machine && !$machine->is_active, 403);
                $machineScoped = isset($v['machine_id']);
                $key = 'flowscope:address:'.($machineScoped ? 'machine:'.$machine->id : 'node:'.$node->id);
                if (RateLimiter::tooManyAttempts($key, 10)) {
                    return response()->json(['message' => 'Address report rate limited.'], 429, ['Retry-After' => '60']);
                }
                RateLimiter::hit($key, 60);
                $addresses->accept($machineScoped ? (int) $machine->id : null, $v['ipv4'], 'node', $v['age_seconds'], null, $node?->id);
                return response()->json(['data' => ['accepted' => true, 'protocol' => 1,
                    'scope' => $machineScoped ? 'machine' : 'node']], 200, ['Cache-Control' => 'no-store, private']);
            }, 3);
        } catch (QueryException) {
            return response()->json(['message' => 'Address storage temporarily unavailable.'], 503);
        }
    }
}
