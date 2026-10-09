<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\Flowscope\Services\AddressService;
$app->instance(App\Support\Setting::class, new class extends App\Support\Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $key==='server_token' ? 'fixture-server-token' : $default; }
});
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
$before=DB::table('flowscope_machine_daily')->get()->toJson();
$cursor=DB::table('flowscope_collector_cursors')->get()->toJson();
$node=['machine_id'=>41,'token'=>str_repeat('a',32),'ipv4'=>'1.1.1.1','age_seconds'=>0];
$endpoint='/api/v2/flowscope/node-address';
ipCheck(flowScopeRequest('POST',$endpoint,$node,null)->getStatusCode()===200,'machine Node uses existing credential without collector dependency');
ipCheck(DB::table('flowscope_machine_addresses')->value('ipv4')==='1.1.1.1','Node preferred over collector');
ipCheck(flowScopeRequest('POST','/api/v2/flowscope/collect',$sample,null,$headers)->getStatusCode()===200,'legacy collector still works beside Node');
ipCheck(DB::table('flowscope_machine_addresses')->count()===1 && DB::table('flowscope_machine_addresses')->value('ipv4')==='1.1.1.1','two producers one machine record; Node priority');
DB::table('v2_server_machine')->insert(['id'=>42,'name'=>'No collector or settings','token'=>str_repeat('c',32),'is_active'=>true]);
$other=$node; $other['machine_id']=42;
ipCheck(flowScopeRequest('POST',$endpoint,$other,null)->getStatusCode()===401,'forged machine ID cannot cross credential boundary');
$other['token']=str_repeat('c',32);
ipCheck(flowScopeRequest('POST',$endpoint,$other,null)->getStatusCode()===200,'Node-only new machine no traffic settings or cursor required');
ipCheck(DB::table('flowscope_machine_settings')->count()===0,'IPv4 does not create or enable traffic settings');
DB::table('v2_server')->insert([
    ['id'=>11,'type'=>'vless','name'=>'Bound node','machine_id'=>41,'enabled'=>true],
    ['id'=>12,'type'=>'trojan','name'=>'Second node same host','machine_id'=>41,'enabled'=>true],
    ['id'=>13,'type'=>'vless','name'=>'Unassigned node','machine_id'=>null,'enabled'=>true],
    ['id'=>14,'type'=>'vless','name'=>'Other machine','machine_id'=>42,'enabled'=>true],
]);
$legacy=['node_id'=>11,'token'=>'fixture-server-token','ipv4'=>'9.9.9.9','age_seconds'=>0];
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===200,'node mode resolves native machine association');
$legacy['node_id']=12;
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===200 && DB::table('flowscope_machine_addresses')->count()===2,'multiple nodes share one machine address');
ipCheck(DB::table('flowscope_machine_addresses')->where('machine_id',41)->value('ipv4')==='1.1.1.1','legacy global credential cannot write machine address record');
$cross=$node; $cross['node_id']=14;
ipCheck(flowScopeRequest('POST',$endpoint,$cross,null)->getStatusCode()===403,'machine token cannot claim another machine node');
$legacy['machine_id']=42;
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===401,'panel server token is never interpreted as machine token');
unset($legacy['machine_id']); $legacy['node_id']=13;
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===200,'unassigned node safe node-only fallback');
$listing=json_decode(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses')->getContent(),true)['data'];
ipCheck($listing['nodes'][0]['node_id']===13 && count($listing['machines'])===2,'node fallback cannot invent machine identity');
$legacy['ipv4']='1.0.0.1';
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===200,'node fallback changes address without cache stale-value bug');
$listing=json_decode(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses')->getContent(),true)['data'];
ipCheck($listing['nodes'][0]['ipv4']==='1.0.0.1','node fallback returns latest valid value');
$legacy['token']='wrong-panel-token';
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===401,'foreign panel credential cannot publish node metadata');
$legacy['token']='fixture-server-token'; $legacy['node_id']=9999;
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===403,'unknown node cannot invent machine');
DB::table('v2_server')->where('id',13)->update(['enabled'=>false]); $legacy['node_id']=13;
ipCheck(flowScopeRequest('POST',$endpoint,$legacy,null)->getStatusCode()===403,'disabled node refused');
DB::table('v2_server')->where('id',13)->update(['enabled'=>true,'machine_id'=>9999]);
$listing=json_decode(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses')->getContent(),true)['data'];
ipCheck($listing['nodes'][0]['node_id']===13 && count($listing['machines'])===2,'orphaned native relationship never creates phantom machine');
foreach(['192.168.0.1','100.64.0.1','198.18.0.1','192.0.2.1','203.0.113.1','224.0.0.1','240.0.0.1','::ffff:8.8.8.8','01.2.3.4'] as $bad) {
    $invalid=$node; $invalid['ipv4']=$bad;
    ipCheck(flowScopeRequest('POST',$endpoint,$invalid,null)->getStatusCode()===422,'nonpublic or malformed Node result rejected: '.$bad);
}
$bad=$node; $bad['token']='bad';
ipCheck(flowScopeRequest('POST',$endpoint,$bad,null)->getStatusCode()===401,'wrong Node token rejected');
$bad=$node; $bad['age_seconds']=86401;
ipCheck(flowScopeRequest('POST',$endpoint,$bad,null)->getStatusCode()===422,'expired observation rejected');
ipCheck(flowScopeRequest('POST',$endpoint.'?token=bad',$node,null)->getStatusCode()===400,'credentials in URL refused');
DB::table('v2_server_machine')->where('id',42)->update(['is_active'=>false]);
ipCheck(flowScopeRequest('POST',$endpoint,$other,null)->getStatusCode()===403,'inactive machine refused');
$addresses=app(AddressService::class); $now=time();
DB::table('flowscope_machine_addresses')->where('machine_id',41)->update(['node_updated_at'=>$now-86401]);
$selected=$addresses->select((array)DB::table('flowscope_machine_addresses')->where('machine_id',41)->first(),$now);
ipCheck($selected['source']==='collector' && $selected['ipv4']==='8.8.4.4' && !$selected['stale'],'fresh collector fallback after Node expires');
$addresses->accept(41,'1.0.0.1','node',0,$now);
$sourceMigration=require dirname(__DIR__).'/FlowScope/database/migrations/2026_10_09_000005_add_address_sources.php';
$sourceMigration->up();
ipCheck(DB::table('flowscope_machine_addresses')->where('machine_id',42)->value('collector_ipv4')===null,'repeat migration does not invent a collector for Node-only row');
$addresses->accept(41,'8.8.8.8','node',3600,$now);
ipCheck(DB::table('flowscope_machine_addresses')->where('machine_id',41)->value('node_ipv4')==='1.0.0.1','older observation cannot overwrite newer Node result');
$row=(array)DB::table('flowscope_machine_addresses')->where('machine_id',41)->first();
ipCheck($addresses->select($row,$now+86401)['stale'] && $addresses->select($row,$now+86401)['ipv4']==='1.0.0.1','expired result retained and marked stale');
ipCheck(DB::table('flowscope_machine_daily')->get()->toJson()===$before && DB::table('flowscope_collector_cursors')->get()->toJson()===$cursor,'IPv4 leaves exact counters and durable replay cursor unchanged');
DB::table('flowscope_machine_settings')->insert(['machine_id'=>41,'enabled'=>false,'quota_bytes'=>'1073741824','traffic_mode'=>'both','cycle_start_day'=>15]);
ipCheck(count(json_decode(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses')->getContent(),true)['data']['machines'])===2,'disabled periodic traffic still displays IPv4');
for($i=0;$i<10;$i++)$limit=flowScopeRequest('POST',$endpoint,$node,null);
ipCheck($limit->getStatusCode()===429,'authenticated excess reports limited');
DB::table('v2_plugins')->update(['is_enabled'=>false]);
ipCheck(flowScopeRequest('GET','/api/v2/flowscope/machine-addresses')->getStatusCode()===404,'disable gated');
ipCheck(flowScopeRequest('POST',$endpoint,$other,null)->getStatusCode()===404,'disabled plugin Node endpoint absent');
DB::table('v2_plugins')->update(['is_enabled'=>true]);
DB::table('v2_server_machine')->where('id',42)->update(['is_active'=>true]);
ipCheck(flowScopeRequest('POST',$endpoint,$other,null)->getStatusCode()===200,'reenable automatically accepts retained machine credential');
$migration=require dirname(__DIR__).'/FlowScope/database/migrations/2026_10_07_000004_create_flowscope_machine_addresses_table.php';
$migration->down(); ipCheck(!Schema::hasTable('flowscope_machine_addresses') && DB::table('v2_server_machine')->count()===2,'owned rollback only');
echo "PASS ipv4: $count checks\n";
