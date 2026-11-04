<?php
define('MFA_HELPERS_ONLY',true);
require __DIR__.'/run.php';

function race(string $task, array $input): array {
    global $runtime;
    $path=$runtime.'/race-'.(getenv('MFA_TEST_MYSQL')==='1'?'mysql':'sqlite').'-'.$task;
    if(is_dir($path)) throw new RuntimeException('Use a new race directory; do not overwrite evidence');
    mkdir($path,0700,true);
    file_put_contents($path.'/input.json',json_encode($input+['now'=>now()->toIso8601String()]));
    $processes=[];
    foreach(['a','b'] as $slot) {
        $cmd=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=openssl','-d','extension=mbstring','-d','extension=fileinfo','-d','extension=pdo_sqlite','-d','extension=pdo_mysql',__DIR__.'/race-worker.php',$task,$slot];
        $p=proc_open($cmd,[0=>['pipe','r'],1=>['file',$path.'/out-'.$slot.'.txt','w'],2=>['file',$path.'/err-'.$slot.'.txt','w']],$pipes,dirname(__DIR__,2));
        if(!is_resource($p)) throw new RuntimeException('Cannot start independent worker');
        fclose($pipes[0]); $processes[]=$p;
    }
    $until=microtime(true)+20;
    while(!is_file($path.'/ready-a')||!is_file($path.'/ready-b')) {
        if(microtime(true)>$until) { foreach($processes as $p) proc_terminate($p); throw new RuntimeException('Barrier timed out'); }
        usleep(10000);
    }
    touch($path.'/go');
    foreach($processes as $p) if(proc_close($p)!==0) throw new RuntimeException('Worker failed');
    $statuses=[];
    foreach(['a','b'] as $slot) $statuses[]=json_decode(file_get_contents($path.'/result-'.$slot.'.json'),true)['status'];
    sort($statuses); return $statuses;
}

Illuminate\Support\Carbon::setTestNow('2026-10-28 16:00:01 UTC');
bootMfa(); initMfaSchema();
check('concurrent singleton one winner',race('provision',[]),[200,409]);
check('singleton still one',App\Models\AdminIdentity::count(),1);
// The winner identity is fictional; normalize only its email for the login helper.
Illuminate\Support\Facades\DB::table('admin_identities')->where('id',1)->update(['email'=>'admin@example.test']);
$jar=[]; login($jar); $r=req('POST','/api/admin/enrollment',$jar); $secret=$r['data']['secret'];
$r=req('POST','/api/admin/mfa',$jar,['otp'=>otp($secret)]); $codes=$r['data']['recovery_codes'];
tick(); $a=[]; $b=[]; login($a); login($b);
check('concurrent TOTP only once',race('totp',['a'=>$a,'b'=>$b,'body'=>['otp'=>otp($secret)]]),[200,401]);
tick(); $a=[]; $b=[]; login($a); login($b);
check('concurrent recovery only once',race('recovery',['a'=>$a,'b'=>$b,'body'=>['recovery_code'=>$codes[0]]]),[200,401]);
check('recovery invalidates all old grants',Illuminate\Support\Facades\DB::table('admin_mfa_grants')->count(),0);
$failed=count(array_filter($results,fn($r)=>!$r['pass']));
$engine=Illuminate\Support\Facades\DB::getDriverName();
file_put_contents($root.'/storage/app/admin-mfa-12/evidence/concurrency-'.$engine.'.json',json_encode(['engine'=>$engine,'mechanism'=>'separate PHP processes, common start barrier','checks'=>$results,'failed'=>$failed],JSON_PRETTY_PRINT));
exit($failed?1:0);
