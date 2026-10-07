<?php

namespace Plugin\Flowscope\Services;

use App\Models\ServerMachine;
use Illuminate\Database\Eloquent\Collection;

final class MachineIdentityService
{
    public function find(string $id): ?ServerMachine
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (new QuotaService())->compare($id, QuotaService::MAX_BYTES) > 0) {
            return null;
        }
        return ServerMachine::query()->find($id);
    }

    public function all(): Collection
    {
        return ServerMachine::query()->orderBy('id')->get(['id', 'name', 'is_active']);
    }
}
