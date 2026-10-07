<?php

namespace Plugin\Flowscope\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Plugin\Flowscope\Services\InstallService;
use Plugin\Flowscope\Services\MachineIdentityService;

final class InstallController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer'];

    public function bootstrap(InstallService $installer)
    {
        return response($installer->file('bootstrap.py'), 200, self::HEADERS + ['Content-Type' => 'text/x-python; charset=UTF-8']);
    }

    public function issue(Request $request, string $id, InstallService $installer, MachineIdentityService $identities)
    {
        if (!$identities->find($id)) { abort(404); }
        $values = $request->validate(['panel_url' => ['required', 'string', 'max:512'],
            'interfaces' => ['sometimes', 'array', 'max:32'],
            'interfaces.*' => ['required', 'string', 'distinct', 'not_in:lo', 'regex:/^[A-Za-z0-9_.:@-]{1,64}$/D']]);
        $url = rtrim($values['panel_url'], '/');
        $parsed = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !$parsed || ($parsed['scheme'] ?? '') !== 'https'
            || strtolower($parsed['host'] ?? '') !== strtolower($request->getHost())
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])
            || ($parsed['path'] ?? '') !== '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw ValidationException::withMessages(['panel_url' => ['Use the current panel HTTPS origin without credentials or paths.']]);
        }
        try { return response()->json(['data' => $installer->issue($id, $url, $values['interfaces'] ?? [])], 200, self::HEADERS); }
        catch (QueryException) { return response()->json(['message' => 'Installation storage temporarily unavailable.'], 503, self::HEADERS); }
    }

    public function shell(Request $request, string $id, InstallService $installer)
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) { abort(404); }
        $raw = $request->query('interfaces', '');
        if (!is_string($raw) || strlen($raw) > 2079) { abort(422); }
        $interfaces = $raw === '' ? [] : explode(',', $raw);
        if (count($interfaces) > 32 || count(array_unique($interfaces)) !== count($interfaces)) { abort(422); }
        foreach ($interfaces as $name) {
            if ($name === 'lo' || !preg_match('/^[A-Za-z0-9_.:@-]{1,64}$/D', $name)) { abort(422); }
        }
        // TLS may terminate at a trusted reverse proxy; never emit an HTTP download.
        $url = 'https://'.$request->getHttpHost();
        if (!filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { abort(422); }
        return response($installer->shell($id, $url, $interfaces), 200, self::HEADERS + ['Content-Type'=>'text/x-shellscript; charset=UTF-8']);
    }

    public function redeem(Request $request, InstallService $installer)
    {
        if (strlen($request->getContent()) > 1024) { abort(413); }
        $values = $request->validate(['ticket' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        if ($request->json('ticket') !== $values['ticket']) { abort(422, 'Use a JSON request body.'); }
        try { return response()->json(['data' => $installer->redeem($values['ticket'])], 200, self::HEADERS); }
        catch (QueryException) { return response()->json(['message' => 'Installation storage temporarily unavailable; retry.'], 503, self::HEADERS); }
    }
}
