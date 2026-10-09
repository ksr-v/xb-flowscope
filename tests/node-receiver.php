<?php
declare(strict_types=1);

// Cross-repository test adapter: real plugin routes/models, synthetic native identity,
// in-memory DB and existing test bootstrap. No production bootstrap/config is loaded.
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$app = require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$app->instance(App\Support\Setting::class, new class extends App\Support\Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $key==='server_token' ? 'fixture-server-token' : $default; }
});
foreach (glob(dirname(__DIR__).'/FlowScope/database/migrations/*.php') as $migration) { (require $migration)->up(); }
DB::table('v2_plugins')->insert(['code'=>'flowscope','name'=>'FlowScope','version'=>\Plugin\Flowscope\Plugin::VERSION,'is_enabled'=>$input['enabled']??true]);
DB::table('v2_server_machine')->insert([
    ['id'=>41,'name'=>'Go-PHP fixture','token'=>'fixture-machine-token','is_active'=>true],
    ['id'=>42,'name'=>'Other machine','token'=>'other-machine-token','is_active'=>true],
]);
DB::table('v2_server')->insert([
    ['id'=>11,'type'=>'vless','name'=>'Bound legacy node','machine_id'=>41,'enabled'=>true],
    ['id'=>13,'type'=>'trojan','name'=>'Unassigned legacy node','machine_id'=>null,'enabled'=>true],
]);
require dirname(__DIR__).'/FlowScope/routes/api.php';
$response=flowScopeRequest('POST','/api/v2/flowscope/node-address',$input['body'],null);
$addresses=app(Plugin\Flowscope\Services\AddressService::class)->listing();
echo json_encode(['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true),
    'addresses'=>$addresses,'machine_records'=>DB::table('flowscope_machine_addresses')->count(),
    'traffic_rows'=>DB::table('flowscope_machine_daily')->count(),'cursors'=>DB::table('flowscope_collector_cursors')->count()],JSON_THROW_ON_ERROR);
