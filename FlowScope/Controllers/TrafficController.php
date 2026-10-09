<?php

namespace Plugin\Flowscope\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Plugin\Flowscope\Models\CollectorCursor;
use Plugin\Flowscope\Models\MachineSetting;
use Plugin\Flowscope\Services\CollectorService;
use Plugin\Flowscope\Services\AddressService;
use Plugin\Flowscope\Services\MachineIdentityService;
use Plugin\Flowscope\Services\QuotaService;
use Plugin\Flowscope\Services\TrafficUsageService;

final class TrafficController extends Controller
{
    public function __construct(private MachineIdentityService $identities, private TrafficUsageService $usage, private QuotaService $quotas) {}

    public function index(): JsonResponse
    {
        $settings = MachineSetting::query()->get()->keyBy('machine_id');
        $cursors = CollectorCursor::query()->get()->keyBy('machine_id');
        $machines = $this->identities->all()->map(fn (ServerMachine $machine): array => $this->machineData($machine) + [
            'settings' => $this->settingsData($settings->get($machine->id)),
            'collector' => CollectorService::metadata($cursors->get($machine->id)),
        ])->values()->all();
        return response()->json(['data' => ['machines' => $machines]]);
    }

    public function addresses(AddressService $addresses): JsonResponse
    {
        return response()->json(['data' => $addresses->listing()], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function show(string $id): JsonResponse
    {
        $machine = $this->identities->find($id);
        if (!$machine) { return $this->notFound(); }
        return response()->json(['data' => [
            'server' => $this->machineData($machine),
            'settings' => $this->settingsData(MachineSetting::query()->where('machine_id', $machine->id)->first()),
            'collector' => CollectorService::metadata(CollectorCursor::query()->where('machine_id', $machine->id)->first()),
        ]]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $machine = $this->identities->find($id);
        if (!$machine) { return $this->notFound(); }
        $values = $request->validate([
            'enabled' => ['required', 'boolean'],
            'display_name' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'quota_bytes' => ['required_without:quota_value', 'regex:/^[0-9]{1,19}$/D'],
            'quota_value' => ['required_without:quota_bytes', 'regex:/^[0-9]{1,19}(?:\.[0-9]{1,12})?$/D'],
            'quota_unit' => ['required_with:quota_value', 'in:GB,TB,GiB,TiB'],
            'traffic_mode' => ['required', 'in:outbound,inbound,both'],
            'cycle_start_day' => ['required', 'integer', 'between:1,31'],
        ]);
        try {
            if (isset($values['quota_bytes']) && is_int($values['quota_bytes']) && $values['quota_bytes'] > 9007199254740991) {
                throw new InvalidArgumentException('Large quotas must be decimal strings.');
            }
            if (isset($values['quota_value']) && !is_string($values['quota_value']) && !is_int($values['quota_value'])) {
                throw new InvalidArgumentException('Decimal quota values must be strings.');
            }
            $quota = array_key_exists('quota_bytes', $values) ? $this->quotas->normalizeBytes($values['quota_bytes']) : $this->quotas->fromValue((string) $values['quota_value'], $values['quota_unit']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['quota_bytes' => [$exception->getMessage()]]);
        }
        $name = trim($values['display_name'] ?? '');
        MachineSetting::query()->updateOrCreate(['machine_id' => $machine->id], [
            'enabled' => (bool) $values['enabled'], 'display_name' => $name !== '' ? $name : null,
            'quota_bytes' => $quota, 'traffic_mode' => $values['traffic_mode'], 'cycle_start_day' => (int) $values['cycle_start_day'],
        ]);
        return $this->show($id);
    }

    public function resetCollector(string $id): JsonResponse
    {
        if (!$this->identities->find($id)) { return $this->notFound(); }
        try {
            DB::transaction(function () use ($id): void {
                $machine = ServerMachine::query()->whereKey($id)->lockForUpdate()->first();
                if (!$machine) { abort(404); }
                CollectorCursor::query()->where('machine_id', $machine->id)->delete();
            }, 3);
        } catch (QueryException) {
            return response()->json(['message' => 'Collector reset temporarily unavailable; retry.', 'retryable' => true], 503);
        }
        return $this->show($id);
    }

    public function dashboard(): JsonResponse { return response()->json(['data' => $this->usage->dashboard()]); }
    public function legacy(): JsonResponse
    {
        return response()->json(['message' => 'Node-based FlowScope APIs were retired. Configure an explicit machine collector; legacy settings are retained without automatic migration.'], 410);
    }
    private function settingsData(?MachineSetting $setting): array
    {
        return ['enabled' => $setting?->enabled ?? false, 'display_name' => $setting?->display_name,
            'quota_bytes' => $setting ? (string) $setting->quota_bytes : QuotaService::DEFAULT_BYTES,
            'traffic_mode' => $setting?->traffic_mode ?? 'outbound', 'cycle_start_day' => $setting?->cycle_start_day ?? 1];
    }
    private function machineData(ServerMachine $machine): array { return ['machine_id' => (int) $machine->id, 'name' => $machine->name, 'is_active' => (bool) $machine->is_active]; }
    private function notFound(): JsonResponse { return response()->json(['message' => 'Machine not found.'], 404); }
}
