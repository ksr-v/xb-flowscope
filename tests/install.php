<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\Flowscope\Services\InstallService;

foreach (glob(dirname(__DIR__).'/FlowScope/database/migrations/*.php') as $migration) { (require $migration)->up(); }
app()->instance(InstallService::class, new InstallService(dirname(__DIR__).'/collector'));
DB::table('v2_plugins')->insert(['code'=>'flowscope','name'=>'FlowScope','version'=>\Plugin\Flowscope\Plugin::VERSION,'is_enabled'=>true]);
DB::table('v2_server_machine')->insert(['id'=>41,'name'=>'Install test','token'=>str_repeat('a',32),'is_active'=>true]);
require dirname(__DIR__).'/FlowScope/routes/api.php';
$count = 0;
function checkInstall(bool $value, string $message): void { global $count; ++$count; if (!$value) throw new RuntimeException('FAIL '.$message); }
function installRequest(string $path, array $body = [], ?bool $admin = true) { return flowScopeRequest('POST', 'https://panel.example.test/api/v2/flowscope/'.$path, $body, $admin); }
function issueInstall(array $extra = []): array {
    $r = installRequest('machine/41/install-ticket', $extra + ['panel_url'=>'https://panel.example.test','interfaces'=>[]]);
    checkInstall($r->getStatusCode() === 200, 'issue status');
    checkInstall(str_contains($r->headers->get('Cache-Control'), 'no-store'), 'issue no-store');
    $data = json_decode($r->getContent(),true)['data'];
    checkInstall(!str_contains($data['command'], str_repeat('a',32)), 'command omits machine token');
    preg_match('/[a-f0-9]{64}/', $data['command'], $matches);
    checkInstall(isset($matches[0]), 'short credential present');
    return [$data,$matches[0]];
}
foreach ([null,false] as $admin) checkInstall(installRequest('machine/41/install-ticket',['panel_url'=>'https://panel.example.test'],$admin)->getStatusCode()===403,'native Admin required');
foreach (['http://panel.example.test','https://evil.example','https://panel.example.test/path','https://user@panel.example.test','https://panel.example.test/?ticket=abc','https://panel.example.test/#fragment',"https://panel.example.test\n"] as $bad) {
    checkInstall(installRequest('machine/41/install-ticket',['panel_url'=>$bad])->getStatusCode()===422,'bad origin rejected');
}
checkInstall(installRequest('machine/41/install-ticket',['panel_url'=>'https://panel.example.test','interfaces'=>['eth0','eth0']])->getStatusCode()===422,'duplicate NIC rejected');
checkInstall(installRequest('machine/999/install-ticket',['panel_url'=>'https://panel.example.test'])->getStatusCode()===404,'missing machine');
[$data,$ticket]=issueInstall(['interfaces'=>['ens33']]);
checkInstall(str_contains($data['command'],'--interfaces'),'NIC override encoded');
checkInstall(str_contains($data['command'],'--ticket-stdin') && str_starts_with($data['command'],'printf '),'credential delivered through stdin');
checkInstall(!str_contains(explode('| sudo bash -c',$data['command'])[1],$ticket),'root bash argv omits credential');
$stored=DB::table('flowscope_install_tickets')->first();
checkInstall($stored->ticket_hash===hash('sha256',$ticket) && !str_contains(json_encode($stored),$ticket),'only credential hash persisted');
checkInstall($stored->expires_at > time() && $stored->expires_at <= time()+600,'ten minute lifetime');
checkInstall(!str_contains(json_encode($stored),str_repeat('a',32)),'long token absent from ticket table');
$response=installRequest('install/redeem',['ticket'=>$ticket],null);
checkInstall($response->getStatusCode()===200,'anonymous short credential exchange');
checkInstall(str_contains($response->headers->get('Cache-Control'),'no-store'),'redeem no-store');
$bundle=json_decode($response->getContent(),true)['data'];
checkInstall($bundle['machine_id']===41 && $bundle['token']===str_repeat('a',32),'exchange matches exact current machine');
foreach ($bundle['files'] as $name=>$encoded) {
    checkInstall(base64_decode($encoded,true)===file_get_contents(dirname(__DIR__).'/collector/'.$name),'exact bundle bytes');
    checkInstall(hash('sha256',base64_decode($encoded))===$bundle['sha256'][$name],'bundle digest');
}
checkInstall(installRequest('install/redeem',['ticket'=>$ticket],null)->getStatusCode()===403,'one use only');
[$unused,$old]=issueInstall(); [$unused,$new]=issueInstall();
checkInstall(installRequest('install/redeem',['ticket'=>$old],null)->getStatusCode()===403,'regeneration revokes prior credential');
DB::table('flowscope_install_tickets')->update(['expires_at'=>time()-1]);
checkInstall(installRequest('install/redeem',['ticket'=>$new],null)->getStatusCode()===403,'expiry enforced');
[$unused,$ticket]=issueInstall(); DB::table('v2_server_machine')->update(['token'=>str_repeat('b',32)]);
checkInstall(installRequest('install/redeem',['ticket'=>$ticket],null)->getStatusCode()===403,'token rotation revokes outstanding credential');
[$unused,$ticket]=issueInstall(); DB::table('v2_server_machine')->update(['is_active'=>false]);
checkInstall(installRequest('install/redeem',['ticket'=>$ticket],null)->getStatusCode()===403,'disabled machine cannot redeem');
checkInstall(installRequest('machine/41/install-ticket',['panel_url'=>'https://panel.example.test'])->getStatusCode()===422,'disabled machine cannot issue without invalidating Admin session');
DB::table('v2_server_machine')->update(['is_active'=>true]);
checkInstall(installRequest('install/redeem',['ticket'=>str_repeat('f',64)],null)->getStatusCode()===403,'unknown credential rejected');
checkInstall(installRequest('install/redeem',['ticket'=>'../../secret'],null)->getStatusCode()===422,'invalid credential format');
checkInstall(installRequest('install/redeem',['ticket'=>$ticket,'padding'=>str_repeat('x',1200)],null)->getStatusCode()===413,'oversize exchange rejected');
[$unused,$ticket]=issueInstall();
$httpBefore=DB::table('flowscope_install_tickets')->where('ticket_hash',hash('sha256',$ticket))->value('used_at');
checkInstall(installRequest('install/redeem?ticket='.$ticket,[],null)->getStatusCode()===422,'credential in query string rejected');
checkInstall($httpBefore===null && DB::table('flowscope_install_tickets')->where('ticket_hash',hash('sha256',$ticket))->value('used_at')===null,'invalid body does not consume ticket');
$script=flowScopeRequest('GET','/api/v2/flowscope/installer',[],null);
checkInstall($script->getStatusCode()===200 && $script->getContent()===file_get_contents(dirname(__DIR__).'/collector/bootstrap.py'),'extensionless public fixed bootstrap bytes');
DB::table('v2_plugins')->update(['is_enabled'=>false]);
checkInstall(flowScopeRequest('GET','/api/v2/flowscope/installer',[],null)->getStatusCode()===404,'disabled bootstrap gated');
checkInstall(installRequest('install/redeem',['ticket'=>$ticket],null)->getStatusCode()===404,'disabled exchange gated');
(require dirname(__DIR__).'/FlowScope/database/migrations/2026_10_07_000003_create_flowscope_install_tickets_table.php')->down();
checkInstall(!Schema::hasTable('flowscope_install_tickets'),'owned migration rollback');
checkInstall(DB::table('v2_server_machine')->count()===1,'core machine preserved');
echo "PASS install: $count assertions\n";
