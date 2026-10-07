<?php
declare(strict_types=1);

// TEST ONLY: all fixture mutations are local CLI operations, never HTTP controls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$command = $argv[1] ?? '';
$configFile = null;
if (count($argv) === 4 && $argv[2] === '--config') { $configFile = $argv[3]; }
elseif (count($argv) !== 2) { $command = ''; }
$commands = ['init', 'audit', 'enable', 'disable', 'machine-enable', 'machine-disable', 'settings', 'reset-binding'];
if (!in_array($command, $commands, true) ||
    (in_array($command, ['init', 'settings'], true) !== ($configFile !== null))) {
    fwrite(STDERR, "TEST ONLY usage: fixture-control.php init|settings --config /absolute/private.json\n".
        "                   fixture-control.php audit|enable|disable|machine-enable|machine-disable|reset-binding\n");
    exit(2);
}

try {
    $values = [];
    if ($configFile !== null) {
        if (!str_starts_with($configFile, '/home/codex/flowscope-vm-test/') || is_link($configFile) ||
            !is_file($configFile) || realpath($configFile) !== $configFile || (fileperms($configFile) & 0777) !== 0600) {
            throw new RuntimeException('Fixture input must be a regular 0600 JSON file inside the dedicated VM test directory.');
        }
        $values = json_decode(file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($values)) { throw new RuntimeException('Fixture input must be a JSON object.'); }
    }
    if ($command === 'init') { define('FLOWSCOPE_VM_INITIALIZING', true); }
    require __DIR__.'/api-bootstrap.php';
    if ($command === 'init') { flowScopeVmInitialize($values); }
    flowScopeVmRequireInitialized();
    if (in_array($command, ['enable', 'disable'], true)) {
        Illuminate\Support\Facades\DB::table('v2_plugins')->where('code', 'flowscope')->update(['is_enabled' => $command === 'enable']);
    } elseif (in_array($command, ['machine-enable', 'machine-disable'], true)) {
        Illuminate\Support\Facades\DB::table('v2_server_machine')->where('id', 41)->update(['is_active' => $command === 'machine-enable']);
    } elseif (in_array($command, ['settings', 'reset-binding'], true)) {
        $uri = '/api/v2/flowscope/machine/41'.($command === 'reset-binding' ? '/collector-reset' : '');
        $request = Illuminate\Http\Request::create($uri, 'POST', [], [], [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($values, JSON_THROW_ON_ERROR));
        $response = flowScopeVmDispatch($request, true);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Fixture native controller rejected CLI action (HTTP '.$response->getStatusCode().'); no response body printed.');
        }
    }
    echo json_encode(['action' => $command, 'audit' => flowScopeVmAudit()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    // All input/token values remain private even when framework validation fails.
    $message = $error instanceof RuntimeException && !($error instanceof Illuminate\Database\QueryException)
        ? $error->getMessage() : 'Fixture operation failed; inspect private runtime permissions and dependencies.';
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
