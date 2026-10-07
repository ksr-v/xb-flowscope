<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
foreach (glob(dirname(__DIR__).'/FlowScope/database/migrations/*.php') as $migration) { (require $migration)->up(); }
DB::table('v2_plugins')->insert(['code'=>'flowscope','name'=>'FlowScope','version'=>\Plugin\Flowscope\Plugin::VERSION,'is_enabled'=>true]);
DB::table('v2_server_machine')->insert(['id'=>41,'name'=>'IPv4 fixture','token'=>str_repeat('a',32),'is_active'=>true]);
require dirname(__DIR__).'/FlowScope/routes/api.php';
$count=0;
function ipCheck(bool $ok,string $name):void { global $count; ++$count; if(!$ok)throw new RuntimeException('FAIL '.$name); }
$sample=['protocol'=>1,'machine_id'=>41,'token'=>str_repeat('a',32),'collector_id'=>str_repeat('b',32),'sequence'=>'1','started_at'=>time()-20,'ended_at'=>time()-10,'boot_id'=>'0a6bb2f4-b88d-4ef2-b5a0-828dcbdb2255','interfaces'=>['eth0'],'rx_bytes'=>'123','tx_bytes'=>'456'];
ipCheck(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses',[],null)->getStatusCode()===403,'anonymous blocked');
ipCheck(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses',[],false)->getStatusCode()===403,'nonadmin blocked');
ipCheck(flowScopeRequest('POST','/api/v2/flowscope/collect',$sample,null)->getStatusCode()===200,'old collector no header accepted');
ipCheck(DB::table('flowscope_machine_addresses')->count()===0,'old collector does not invent address');
$headers=['X-FlowScope-IPv4'=>'8.8.4.4'];
ipCheck(flowScopeRequest('POST','/api/v2/flowscope/collect',$sample,null,$headers)->getStatusCode()===200,'duplicate with new IP accepted without digest conflict');
ipCheck(DB::table('flowscope_machine_addresses')->value('ipv4')==='8.8.4.4','IPv4 persisted');
foreach(['::1','2001:4860:4860::8888','192.168.1.1','127.0.0.1','bad<script>','999.1.1.1'] as $bad) {
    ipCheck(flowScopeRequest('POST','/api/v2/flowscope/collect',$sample,null,['X-FlowScope-IPv4'=>$bad])->getStatusCode()===200,'bad metadata never stops accounting');
    ipCheck(DB::table('flowscope_machine_addresses')->value('ipv4')==='8.8.4.4','bad metadata cannot overwrite good address');
}
$bad=$sample; $bad['token']='wrong';
ipCheck(flowScopeRequest('POST','/api/v2/flowscope/collect',$bad,null,['X-FlowScope-IPv4'=>'1.1.1.1'])->getStatusCode()===403,'address writes require machine auth');
$response=flowScopeRequest('GET','/api/v2/flowscope/machine-addresses');
$json=json_decode($response->getContent(),true);
ipCheck($response->getStatusCode()===200 && $json['data']['machines'][0]['ipv4']==='8.8.4.4','Admin batched addresses');
ipCheck(!str_contains($response->getContent(),$sample['token']),'no token in address response');
ipCheck((string)DB::table('flowscope_machine_daily')->value('rx_bytes')==='123','IP retry does not duplicate traffic');
DB::table('v2_plugins')->update(['is_enabled'=>false]);
ipCheck(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses')->getStatusCode()===404,'disable gated');
$migration=require dirname(__DIR__).'/FlowScope/database/migrations/2026_10_07_000004_create_flowscope_machine_addresses_table.php';
$migration->down(); ipCheck(!Schema::hasTable('flowscope_machine_addresses') && DB::table('v2_server_machine')->count()===1,'owned rollback only');
echo "PASS ipv4: $count checks\n";
