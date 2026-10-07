<?php
declare(strict_types=1);

// TEST ONLY. Run exactly: php -S 127.0.0.1:18080 tests/vm/api-router.php
// No public write controls. Never bind this fixture to a LAN/public interface.
umask(0077);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-FlowScope-Test-Fixture: isolated-file-sqlite-not-production');

function fixtureJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (PHP_SAPI !== 'cli-server' || !in_array($remote, ['127.0.0.1', '::1'], true) ||
    ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1' || (int) ($_SERVER['SERVER_PORT'] ?? 0) !== 18080) {
    fixtureJson(['message' => 'This test fixture requires php -S 127.0.0.1:18080.'], 403);
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Small, deterministic transfer endpoints for a trusted SSH-forwarded test client.
// A PHP built-in server is single-threaded; keep each transfer <=1 MiB and short.
if ($path === '/traffic/download' && $method === 'GET') {
    $raw = $_GET['bytes'] ?? '262144';
    if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,6}$/D', $raw) || (int) $raw > 1048576) {
        fixtureJson(['message' => 'bytes must be 1..1048576.'], 422);
    }
    $length = (int) $raw;
    $body = substr(str_repeat('0123456789abcdef', intdiv($length + 15, 16)), 0, $length);
    header('Content-Type: application/octet-stream'); header('Content-Length: '.strlen($body));
    header('X-Content-SHA256: '.hash('sha256', $body));
    echo $body;
    exit;
}
if ($path === '/traffic/upload' && $method === 'POST') {
    $length = $_SERVER['CONTENT_LENGTH'] ?? '';
    if (!is_string($length) || !preg_match('/^[1-9][0-9]{0,6}$/D', $length) || (int) $length > 1048576) {
        fixtureJson(['message' => 'Content-Length must be 1..1048576.'], 413);
    }
    $body = file_get_contents('php://input', false, null, 0, 1048577);
    if ($body === false || strlen($body) !== (int) $length) { fixtureJson(['message' => 'Incomplete upload.'], 400); }
    fixtureJson(['received_bytes' => strlen($body), 'sha256' => hash('sha256', $body)]);
}

try {
    require __DIR__.'/api-bootstrap.php';
    flowScopeVmRequireInitialized();
    if ($path === '/__test__/audit' && $method === 'GET') {
        fixtureJson(flowScopeVmAudit());
    }
    $installAdmin = $path === '/api/v2/flowscope/machine/41/install-ticket' && $method === 'POST';
    if (!(($path === '/api/v2/flowscope/collect' && $method === 'POST') || $installAdmin ||
        ($path === '/api/v2/flowscope/install/redeem' && $method === 'POST') ||
        ($path === '/api/v2/flowscope/installer' && $method === 'GET') ||
        (in_array($path, ['/api/v2/flowscope/dashboard', '/api/v2/flowscope/machine-addresses'], true) && $method === 'GET'))) {
        fixtureJson(['message' => 'Not Found. Fixture writes are CLI-only.'], 404);
    }
    if ($method === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
        fixtureJson(['message' => 'Fixture request too large.'], 413);
    }
    $request = Illuminate\Http\Request::capture();
    $request->headers->set('Accept', 'application/json');
    $response = flowScopeVmDispatch($request, in_array($path, ['/api/v2/flowscope/dashboard', '/api/v2/flowscope/machine-addresses'], true) || $installAdmin);
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('X-FlowScope-Test-Fixture', 'isolated-file-sqlite-not-production');
    $response->send();
} catch (Throwable) {
    // Never print request JSON, token, DSN or a framework exception to the response.
    fixtureJson(['message' => 'VM fixture unavailable; check initialization and private runtime permissions.'], 500);
}
