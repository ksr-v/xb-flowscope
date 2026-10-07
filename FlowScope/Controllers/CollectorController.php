<?php

namespace Plugin\Flowscope\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Plugin\Flowscope\Services\CollectorService;
use Plugin\Flowscope\Services\QuotaService;

final class CollectorController extends Controller
{
    public function store(Request $request, CollectorService $collector, QuotaService $bytes): JsonResponse
    {
        $values = $request->validate([
            'protocol' => ['required', 'integer', 'in:1'],
            'machine_id' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'max:256'],
            'collector_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
            'sequence' => ['required', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'started_at' => ['required', 'integer', 'min:1577836800'],
            'ended_at' => ['required', 'integer', 'gt:started_at'],
            'boot_id' => ['required', 'uuid'],
            'interfaces' => ['required', 'array', 'min:1', 'max:32'],
            'interfaces.*' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z0-9_.:@-]{1,64}$/D'],
            'rx_bytes' => ['required', 'regex:/^[0-9]{1,19}$/D'],
            'tx_bytes' => ['required', 'regex:/^[0-9]{1,19}$/D'],
            'resets' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'boot_changed' => ['sometimes', 'boolean'],
        ]);
        // JSON numbers can lose precision before validation; byte fields and
        // sequence are a string-only wire contract, including small values.
        foreach (['sequence', 'rx_bytes', 'tx_bytes'] as $field) {
            if (!is_string($values[$field]) || $bytes->compare($values[$field], QuotaService::MAX_BYTES) > 0) {
                throw ValidationException::withMessages([$field => ['Must be an integer decimal string within signed BIGINT range.']]);
            }
        }
        foreach (['protocol', 'machine_id', 'started_at', 'ended_at'] as $field) {
            if (!is_int($values[$field])) {
                throw ValidationException::withMessages([$field => ['Must be a JSON integer.']]);
            }
        }
        if (isset($values['resets']) && !is_int($values['resets'])) {
            throw ValidationException::withMessages(['resets' => ['Must be a JSON integer.']]);
        }
        if (isset($values['boot_changed']) && !is_bool($values['boot_changed'])) {
            throw ValidationException::withMessages(['boot_changed' => ['Must be a JSON boolean.']]);
        }
        if ($values['ended_at'] > time() + 300 || $values['ended_at'] - $values['started_at'] > CollectorService::MAX_INTERVAL) {
            throw ValidationException::withMessages(['ended_at' => ['Sample must not be over 300 seconds in the future or span more than 366 days.']]);
        }
        $values['rx_bytes'] = ltrim($values['rx_bytes'], '0') ?: '0';
        $values['tx_bytes'] = ltrim($values['tx_bytes'], '0') ?: '0';
        $values['boot_id'] = strtolower($values['boot_id']);
        $values['resets'] ??= 0;
        $values['boot_changed'] ??= false;
        sort($values['interfaces'], SORT_STRING);
        try {
            return response()->json(['data' => $collector->accept($values, $request->header('X-FlowScope-IPv4'))]);
        } catch (QueryException) {
            // No native reporting or shared records are written by this path.
            return response()->json(['message' => 'Collector storage temporarily unavailable; retry this sequence.', 'retryable' => true], 503);
        }
    }
}
