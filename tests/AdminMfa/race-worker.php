<?php
define('MFA_HELPERS_ONLY',true);
require __DIR__.'/run.php';
$task=$argv[1]??''; $slot=$argv[2]??'';
if(!in_array($task,['provision','totp','recovery'],true)||!in_array($slot,['a','b'],true)) exit(3);
$race=$runtime.'/race-'.(getenv('MFA_TEST_MYSQL')==='1'?'mysql':'sqlite').'-'.$task;
$input=json_decode(file_get_contents($race.'/input.json'),true,512,JSON_THROW_ON_ERROR);
Illuminate\Support\Carbon::setTestNow($input['now']);
bootMfa(); if(Illuminate\Support\Facades\DB::getDriverName()==='sqlite') Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout=10000');
touch($race.'/ready-'.$slot);
$until=microtime(true)+20;
while(!is_file($race.'/go')) { if(microtime(true)>$until) exit(4); usleep(10000); }
if($task==='provision') {
    try { app(App\Services\Admin\AdminMfa::class)->provision('race-'.$slot.'@example.test','fictional-password-12'); $status=200; }
    catch (Illuminate\Database\UniqueConstraintViolationException) { $status=409; }
} else {
    $jar=$input[$slot];
    $r=req('POST','/api/admin/'.($task==='totp'?'mfa':'recovery'),$jar,$input['body']); $status=$r['status'];
}
file_put_contents($race.'/result-'.$slot.'.json',json_encode(['status'=>$status]));
echo $status."\n";
