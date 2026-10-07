<?php

namespace Plugin\Flowscope\Services;

use App\Models\Server;
use Illuminate\Support\Collection;

final class ServerIdentityService
{
    public function normalizeType(string $type): string
    {
        return (string) Server::normalizeType(strtolower($type));
    }

    public function find(string $type, string|int $id): ?Server
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)
            || strlen((string) $id) === 19 && strcmp((string) $id, QuotaService::MAX_BYTES) > 0) {
            return null;
        }

        // Native getServer() also matches legacy code and can select a different
        // server. FlowScope always resolves the exact unified primary id + type.
        return Server::query()->whereKey($id)->where('type', $this->normalizeType($type))->first();
    }

    public function all(): Collection
    {
        return Server::query()->orderBy('name')->orderBy('id')->get(['id', 'type', 'name']);
    }

    /** @return array<string, Server> Live, exact identities; orphan settings are ignored. */
    public function forSettings(Collection $settings): array
    {
        if ($settings->isEmpty()) {
            return [];
        }
        $servers = Server::query()->whereIn('id', $settings->pluck('server_id')->unique()->all())
            ->get(['id', 'type', 'name']);
        $result = [];
        foreach ($servers as $server) {
            $result[$this->key($server->type, $server->id)] = $server;
        }
        return $result;
    }

    public function key(string $type, string|int $id): string
    {
        return $this->normalizeType($type).':'.$id;
    }
}
