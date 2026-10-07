<?php

namespace Plugin\Flowscope\Services;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Plugin\Flowscope\Plugin;

final class InstallService
{
    public function __construct(private ?string $collectorDirectory = null) {}

    private static function shellQuote(string $value): string
    {
        // Target is always Linux bash, even when the panel/test host is Windows.
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }

    public function file(string $name): string
    {
        if (!in_array($name, ['bootstrap.py', 'flowscope_collector.py', 'install.sh', 'flowscope-collector.service'], true)) { abort(404); }
        $path = ($this->collectorDirectory ?? dirname(__DIR__).'/collector').'/'.$name;
        if (!is_file($path) || is_link($path)) { abort(503, 'Collector installation files unavailable.'); }
        $contents = file_get_contents($path);
        if ($contents === false) { abort(503, 'Collector installation files unavailable.'); }
        return $contents;
    }

    public function issue(string $id, string $url, array $interfaces): array
    {
        // Fail before issuing a usable credential if this upload is incomplete.
        foreach (['bootstrap.py', 'flowscope_collector.py', 'install.sh', 'flowscope-collector.service'] as $name) { $this->file($name); }
        $ticket = bin2hex(random_bytes(32));
        $expires = time() + 600;
        DB::transaction(function () use ($id, $url, $ticket, $expires): void {
            $machine = ServerMachine::query()->whereKey($id)->lockForUpdate()->first();
            if (!$machine) { abort(404); }
            if (!$machine->is_active) { abort(422, 'Enable this machine before installing.'); }
            DB::table('flowscope_install_tickets')->where('machine_id', $id)->delete();
            DB::table('flowscope_install_tickets')->where('expires_at', '<', time())->delete();
            DB::table('flowscope_install_tickets')->insert(['machine_id' => $id, 'ticket_hash' => hash('sha256', $ticket),
                'machine_token_hash' => hash('sha256', (string) $machine->token), 'panel_url' => $url,
                'expires_at' => $expires, 'used_at' => null]);
        }, 3);
        $script = 'set -eu; umask 077; d=$(mktemp -d /tmp/flowscope-install.XXXXXXXX); '
            .'trap '.self::shellQuote('rm -f -- "$d/bootstrap.py"; rmdir -- "$d"').' EXIT; '
            .'code=$(curl --proto =https --tlsv1.2 --fail --silent --show-error --max-time 60 --max-filesize 262144 --write-out '.self::shellQuote('%{http_code}').' --output "$d/bootstrap.py" '
            .self::shellQuote($url.'/api/v2/flowscope/installer').'); [ "$code" = 200 ]; '
            .'/usr/bin/python3 "$d/bootstrap.py" --panel '.self::shellQuote($url).' --machine-id '.self::shellQuote($id)
            .' --ticket-stdin';
        if ($interfaces !== []) { $script .= ' --interfaces '.self::shellQuote(implode(',', $interfaces)); }
        return ['command' => 'printf '.self::shellQuote('%s\n').' '.self::shellQuote($ticket).' | sudo bash -c '.self::shellQuote($script), 'expires_at' => $expires,
            'machine_id' => (int) $id, 'version' => Plugin::VERSION];
    }

    public function redeem(string $ticket): array
    {
        $files = $hashes = [];
        foreach (['flowscope_collector.py', 'install.sh', 'flowscope-collector.service'] as $name) {
            $contents = $this->file($name);
            $files[$name] = base64_encode($contents); $hashes[$name] = hash('sha256', $contents);
        }
        $hash = hash('sha256', $ticket);
        // Discover identity without locking, then take locks in machine->ticket order.
        $id = DB::table('flowscope_install_tickets')->where('ticket_hash', $hash)->value('machine_id');
        if (!$id) { abort(403, 'Installation credential invalid or expired.'); }
        return DB::transaction(function () use ($id, $hash, $files, $hashes): array {
            $machine = ServerMachine::query()->whereKey($id)->lockForUpdate()->first();
            $grant = DB::table('flowscope_install_tickets')->where('ticket_hash', $hash)->lockForUpdate()->first();
            if (!$machine || !$machine->is_active || !$grant || $grant->used_at !== null || $grant->expires_at <= time()
                || !hash_equals($grant->machine_token_hash, hash('sha256', (string) $machine->token))) {
                abort(403, 'Installation credential invalid or expired.');
            }
            $changed = DB::table('flowscope_install_tickets')->where('id', $grant->id)->whereNull('used_at')
                ->where('expires_at', '>', time())->update(['used_at' => time()]);
            if ($changed !== 1) { abort(403, 'Installation credential already used.'); }
            return ['protocol' => 1, 'version' => Plugin::VERSION, 'machine_id' => (int) $id,
                'panel_url' => $grant->panel_url, 'token' => (string) $machine->token, 'files' => $files, 'sha256' => $hashes];
        }, 3);
    }
}
