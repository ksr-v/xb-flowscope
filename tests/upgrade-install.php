<?php
declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
use App\Models\Plugin as PluginRecord;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

File::copyDirectory(dirname(__DIR__).'/FlowScope', base_path('plugins/Flowscope'));
$oldVersion=$argv[1]??'2.0.0';
if(!in_array($oldVersion,['2.0.0','2.1.1','2.3.0'],true))throw new RuntimeException('Unknown upgrade fixture version');
$file=base_path('plugins/Flowscope/database/migrations/2026_10_07_000003_create_flowscope_install_tickets_table.php');
if($oldVersion==='2.0.0')File::move($file,$file.'.pending');
$addressFile=base_path('plugins/Flowscope/database/migrations/2026_10_07_000004_create_flowscope_machine_addresses_table.php');
if($oldVersion!=='2.3.0')File::move($addressFile,$addressFile.'.pending');
$sourcesFile=base_path('plugins/Flowscope/database/migrations/2026_10_09_000005_add_address_sources.php');
File::move($sourcesFile,$sourcesFile.'.pending');
$configPath=base_path('plugins/Flowscope/config.json'); $current=file_get_contents($configPath);
$old=json_decode($current,true); $old['version']=$oldVersion; file_put_contents($configPath,json_encode($old));
$manager=new PluginManager(); $checks=0;
function upgradeCheck(bool $value, string $description):void { global $checks; ++$checks; if(!$value)throw new RuntimeException('FAIL '.$description); }
upgradeCheck($manager->install('flowscope'),'install real 2.0 migration set');
upgradeCheck(Schema::hasTable('flowscope_install_tickets')===($oldVersion!=='2.0.0'),'actual old migration set');
DB::table('v2_server_machine')->insert(['id'=>41,'name'=>'Upgrade fixture','token'=>str_repeat('a',32),'is_active'=>true]);
DB::table('flowscope_machine_settings')->insert(['machine_id'=>41,'enabled'=>true,'quota_bytes'=>'1073741824','traffic_mode'=>'both','cycle_start_day'=>15]);
DB::table('flowscope_machine_daily')->insert(['machine_id'=>41,'record_at'=>1791302400,'timezone'=>'Asia/Shanghai','rx_bytes'=>'9007199254740993','tx_bytes'=>'321']);
$before=DB::table('flowscope_machine_daily')->get()->toJson();
if($oldVersion==='2.3.0')DB::table('flowscope_machine_addresses')->insert(['machine_id'=>41,'ipv4'=>'8.8.4.4','updated_at'=>time()-300]);
if($oldVersion==='2.0.0')File::move($file.'.pending',$file); file_put_contents($configPath,$current);
if($oldVersion!=='2.3.0')File::move($addressFile.'.pending',$addressFile);
File::move($sourcesFile.'.pending',$sourcesFile);
upgradeCheck($manager->update('flowscope'),'native 2.0 to current update');
upgradeCheck(PluginRecord::where('code','flowscope')->value('version')===\Plugin\Flowscope\Plugin::VERSION,'version recorded');
upgradeCheck(Schema::hasTable('flowscope_install_tickets'),'incremental install migration');
upgradeCheck(Schema::hasTable('flowscope_machine_addresses'),'incremental address migration');
upgradeCheck(DB::table('flowscope_machine_daily')->get()->toJson()===$before,'exact old history retained');
upgradeCheck(DB::table('flowscope_machine_settings')->where('machine_id',41)->value('cycle_start_day')===15,'old setting retained');
upgradeCheck($manager->enable('flowscope'),'enable after upgrade');
if($oldVersion==='2.3.0') {
    upgradeCheck(DB::table('flowscope_machine_addresses')->value('collector_ipv4')==='8.8.4.4','existing IPv4 backfilled without loss');
    (require $sourcesFile)->up();
    upgradeCheck(DB::table('flowscope_machine_addresses')->value('ipv4')==='8.8.4.4','migration safely recognizes upgraded state');
}
upgradeCheck($manager->uninstall('flowscope'),'native uninstall latest batch');
upgradeCheck(Schema::hasTable('flowscope_install_tickets')===($oldVersion!=='2.0.0'),'only latest migration batch rolled back');
upgradeCheck(Schema::hasTable('flowscope_machine_addresses')===($oldVersion==='2.3.0'),'native latest-batch rollback retains older address table');
if($oldVersion==='2.3.0') {
    upgradeCheck(!Schema::hasColumn('flowscope_machine_addresses','node_ipv4') && DB::table('flowscope_machine_addresses')->value('ipv4')==='8.8.4.4','2.3 rollback keeps old IPv4 and removes only metadata');
}
upgradeCheck(Schema::hasTable('flowscope_machine_daily') && DB::table('flowscope_machine_daily')->get()->toJson()===$before,'native older migration batch history retained');
upgradeCheck(DB::table('v2_server_machine')->where('id',41)->exists(),'core machine retained');
echo "PASS upgrade-install from $oldVersion: $checks checks\n";
