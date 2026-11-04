<?php
require __DIR__.'/bootstrap.php';
use App\Models\AdminIdentity;
use App\Models\User;
use App\Services\Admin\AdminMfa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

$mode=$argv[1]??'uncached'; $root=dirname(__DIR__,2); $runtime=$root.'/storage/app/admin-mfa-12/runtime';
$results=[]; $lastRequest=null; $lastApp=null;
function check($name,$actual,$expected=true) {
    global $results;
    $ok=$actual===$expected; $results[]=['name'=>$name,'pass'=>$ok];
    echo ($ok?'PASS ':'FAIL ').$name."\n";
}
function bootMfa() { $a=mfaApp(); $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $a; }
function tick($seconds=301) { Illuminate\Support\Carbon::setTestNow(now()->addSeconds($seconds)); }
function req($method,$uri,&$jar,$body=[],$extra=[]) {
    global $lastRequest,$lastApp;
    $app=mfaApp(); $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $cookies=$jar; unset($cookies['_csrf']);
    $server=['HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_ACCEPT'=>'application/json','HTTP_ORIGIN'=>'https://client.foundation.test','HTTP_REFERER'=>'https://client.foundation.test/','REMOTE_ADDR'=>'127.0.0.1'];
    if (isset($jar['_csrf'])) $server['HTTP_X_CSRF_TOKEN']=$jar['_csrf'];
    if (isset($jar['XSRF-TOKEN'])) $server['HTTP_X_XSRF_TOKEN']=$jar['XSRF-TOKEN'];
    $request=Illuminate\Http\Request::create('https://client.foundation.test'.$uri,$method,$body,$cookies,[],array_replace($server,$extra));
    $response=$kernel->handle($request);
    foreach($response->headers->getCookies() as $c) $jar[$c->getName()]=$c->getValue();
    $data=json_decode($response->getContent(),true)??[];
    if(isset($data['csrf'])) $jar['_csrf']=$data['csrf'];
    $lastRequest=$request; $lastApp=$app;
    $kernel->terminate($request,$response);
    return ['status'=>$response->getStatusCode(),'data'=>$data,'headers'=>$response->headers,'sid'=>$request->hasSession()?$request->session()->getId():null];
}
function login(&$jar) { req('GET','/api/admin/csrf',$jar); return req('POST','/api/admin/login',$jar,['email'=>'admin@example.test','password'=>'fictional-password-12']); }
function otp($secret,$offset=0) { return (new PragmaRX\Google2FA\Google2FA)->oathTotp($secret,intdiv(now()->timestamp,30)+$offset); }
function initMfaSchema() {
    global $root;
    if(DB::getDriverName()==='sqlite') {
        DB::statement('PRAGMA busy_timeout=10000');
        DB::statement('PRAGMA foreign_keys=OFF');
        foreach(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) DB::statement('DROP TABLE "'.str_replace('"','""',$t->name).'"');
        DB::statement('PRAGMA foreign_keys=ON');
    } else {
        if(DB::connection()->getDatabaseName()!=='admin_mfa12_test') throw new RuntimeException('Wrong test database');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach(DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema=DATABASE()') as $t) DB::statement('DROP TABLE `'.str_replace('`','``',$t->name).'`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
    foreach(['2014_10_12_000000_create_users_table.php','2014_10_12_100000_create_password_reset_tokens_table.php',
        '2019_12_14_000001_create_personal_access_tokens_table.php','2026_04_20_125418_create_expenses_table.php',
        '2026_05_08_211149_create_budgets_table.php','2026_06_02_232941_add_last_activity_to_users_table.php',
        '2026_10_02_002051_create_jobs_table.php','2026_10_06_210747_create_sessions_table.php',
        '2026_10_27_000001_create_admin_foundations.php','2026_10_28_000001_create_admin_mfa.php'] as $f) (require $root.'/database/migrations/'.$f)->up();
}

if (defined('MFA_HELPERS_ONLY')) return;
Illuminate\Support\Carbon::setTestNow('2026-10-28 12:00:01 UTC');
$app=bootMfa(); initMfaSchema();
check('cache configuration matches mode',$app->configurationIsCached(),$mode==='cached');
check('cache routes match mode',$app->routesAreCached(),$mode==='cached');
app(AdminMfa::class)->provision('admin@example.test','fictional-password-12');
check('provisioned without privilege',AdminIdentity::first()->mfa_state,'pending_enrollment');
try { app(AdminMfa::class)->provision('second@example.test','fictional-password-12'); check('second identity refused',false); }
catch (Illuminate\Database\QueryException) { check('second identity refused',AdminIdentity::count(),1); }
$u=User::create(['name'=>'Fictional client','email'=>'client@example.test','password'=>'fixture-password']);
$u->forceFill(['email_verified_at'=>now()])->save();
$bearer=$u->createToken('fictional')->plainTextToken;
check('same numeric IDs',$u->id,(int)AdminIdentity::first()->id);
check('no admin PAT',method_exists(AdminIdentity::class,'tokens'),false);
$jar=[]; $r=req('GET','/api/admin/csrf',$jar); check('csrf bootstrap',$r['status'],200);
$c=$r['headers']->getCookies()[0];
check('cookie path',$c->getPath(),'/api/admin'); check('cookie secure httponly',$c->isSecure()&&$c->isHttpOnly());
check('cookie host only',$c->getDomain(),null); check('cookie strict',$c->getSameSite(),'strict');
$empty=[]; check('missing csrf',req('POST','/api/admin/login',$empty)['status'],419);
check('wrong csrf',req('POST','/api/admin/login',$jar,[],['HTTP_X_CSRF_TOKEN'=>'wrong'])['status'],419);
check('wrong password',req('POST','/api/admin/login',$jar,['email'=>'admin@example.test','password'=>'wrong'])['status'],401);
check('unknown admin',req('POST','/api/admin/login',$jar,['email'=>'unknown@example.test','password'=>'wrong'])['status'],401);
$r=login($jar); $preSid=$r['sid']; check('password creates challenge',$r['status'],200);
check('pre MFA cannot access session',req('GET','/api/admin/session',$jar)['status'],401);
$lastRequest->session()->put('mfa_complete',true); $lastRequest->session()->save();
check('forged boolean does not grant access',req('GET','/api/admin/session',$jar)['status'],401);
$r=req('POST','/api/admin/enrollment',$jar); check('enrollment starts',$r['status'],200); $secret=$r['data']['secret'];
check('secret length',strlen($secret),32);
check('secret encrypted in DB',DB::table('admin_mfa_challenges')->value('pending_secret')!==$secret);
check('secret not redisclosed',req('POST','/api/admin/enrollment',$jar)['status'],409);
check('wrong totp',req('POST','/api/admin/mfa',$jar,['otp'=>'xxxxxx'])['status'],401);
check('expired totp',req('POST','/api/admin/mfa',$jar,['otp'=>otp($secret,-3)])['status'],401);
check('missing totp',req('POST','/api/admin/mfa',$jar)['status'],422);
// Enrollment + repeat + two invalid factors used 4 of the shared budget.
$old=$jar; $r=req('POST','/api/admin/mfa',$jar,['otp'=>otp($secret)]);
check('full MFA success',$r['status'],200); check('session ID rotated',$r['sid']!==$preSid);
$codes=$r['data']['recovery_codes']??[]; check('ten recovery codes',count($codes),10);
check('persisted factor encrypted',AdminIdentity::first()->totp_ciphertext!==$secret);
check('recovery hashes only',DB::table('admin_recovery_codes')->where('digest',$codes[0]??'')->count(),0);
check('no secret after enrollment',array_key_exists('secret',$r['data']),false);
check('authenticated session',req('GET','/api/admin/session',$jar)['status'],200);
check('old pre MFA session denied',req('GET','/api/admin/session',$old)['status'],401);
check('complete admin cannot access finance',req('GET','/api/expenses',$jar)['status'],401);
check('complete admin model exposes no factor',array_intersect(['totp_ciphertext','totp_last_step','auth_version'],array_keys(AdminIdentity::first()->toArray())),[]);
// A real proof copied to another authenticated session is not transferable.
req('GET','/api/admin/session',$jar); $copiedProof=$lastRequest->session()->get('admin.proof');
$forged=[]; req('GET','/api/admin/csrf',$forged);
Auth::guard('admin')->login(AdminIdentity::first(),false);
$lastRequest->session()->put('admin.proof',$copiedProof);
$lastRequest->session()->save();
// The existing cookie still references the destroyed pre-login session: also test
// the binding directly with the newly rotated session and a real admin principal.
try { app(AdminMfa::class)->requireGrant($lastRequest); check('copied proof wrong session refused',false); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { check('copied proof wrong session refused',$e->getStatusCode(),401); }
check('secret never available again',req('POST','/api/admin/enrollment',$jar)['status'],429);
tick();
$other=[]; login($other);
// Advancing time permits one new OTP; accepting it once must reject it in another challenge.
$newOtp=otp($secret); check('subsequent login',req('POST','/api/admin/mfa',$other,['otp'=>$newOtp])['status'],200);
$replay=[]; login($replay); check('TOTP replay rejected',req('POST','/api/admin/mfa',$replay,['otp'=>$newOtp])['status'],401);
$exp=[]; login($exp); tick(); check('expired challenge',req('POST','/api/admin/mfa',$exp,['otp'=>otp($secret)])['status'],401);
$old=$other; check('logout',req('POST','/api/admin/logout',$other)['status'],200);
check('old full session invalid',req('GET','/api/admin/session',$old)['status'],401);
$attacker=[]; login($attacker);
for($i=0;$i<5;$i++) req('POST','/api/admin/mfa',$attacker,['otp'=>'xxxxxx']);
check('MFA throttling',req('POST','/api/admin/mfa',$attacker,['otp'=>otp($secret)])['status'],429);
tick(); $failed=[]; req('GET','/api/admin/csrf',$failed);
for($i=0;$i<5;$i++) req('POST','/api/admin/login',$failed,['email'=>'admin@example.test','password'=>'wrong']);
check('identity login throttling',login($failed)['status'],429);
tick(); $spray=[]; req('GET','/api/admin/csrf',$spray);
for($i=0;$i<20;$i++) req('POST','/api/admin/login',$spray,['email'=>"unknown$i@example.test",'password'=>'wrong']);
check('IP login throttling',req('POST','/api/admin/login',$spray,['email'=>'different@example.test','password'=>'wrong'])['status'],429);
tick(); $jar=[]; login($jar); req('POST','/api/admin/mfa',$jar,['otp'=>otp($secret)]);
$victim=$jar; $recovery=[]; login($recovery);
check('recovery accepted',req('POST','/api/admin/recovery',$recovery,['recovery_code'=>$codes[0]])['status'],200);
check('recovery not full MFA',req('GET','/api/admin/session',$recovery)['status'],401);
check('old sessions revoked on recovery',req('GET','/api/admin/session',$victim)['status'],401);
$passwordOnly=[]; check('password cannot steal reenrollment',login($passwordOnly)['status'],403);
check('consumed recovery cannot repeat',req('POST','/api/admin/recovery',$recovery,['recovery_code'=>$codes[0]])['status'],403);
$r=req('POST','/api/admin/enrollment',$recovery); $secret2=$r['data']['secret']??'';
check('recovery new factor issued',$r['status'],200);
$r=req('POST','/api/admin/mfa',$recovery,['otp'=>otp($secret2)]); check('recovery needs new valid MFA',$r['status'],200);
check('same identity preserved',AdminIdentity::count(),1);
check('old version stays rejected after activation',req('GET','/api/admin/session',$victim)['status'],401);
tick(); $again=[]; login($again);
check('old recovery code invalid after reenrollment',req('POST','/api/admin/recovery',$again,['recovery_code'=>$codes[0]])['status'],401);
check('recent authentication required for factor change',req('POST','/api/admin/factor/change',$recovery)['status'],403);
check('reauth rejects wrong password',req('POST','/api/admin/reauthenticate',$recovery,['password'=>'wrong','otp'=>otp($secret2)])['status'],401);
check('reauth valid',req('POST','/api/admin/reauthenticate',$recovery,['password'=>'fictional-password-12','otp'=>otp($secret2)])['status'],200);
$old=$recovery; check('controlled factor change',req('POST','/api/admin/factor/change',$recovery)['status'],200);
check('factor change revokes old grant',req('GET','/api/admin/session',$old)['status'],401);
$r=req('POST','/api/admin/enrollment',$recovery); $secret3=$r['data']['secret']??'';
check('changed factor enrollment',req('POST','/api/admin/mfa',$recovery,['otp'=>otp($secret3)])['status'],200);
$proof=DB::table('admin_mfa_grants')->first();
DB::table('admin_mfa_grants')->where('id',$proof->id)->update(['last_seen_at'=>now()->timestamp-900]);
check('idle deadline enforced',req('GET','/api/admin/session',$recovery)['status'],401);
DB::table('admin_mfa_grants')->where('id',$proof->id)->update(['last_seen_at'=>now()->timestamp,'started_at'=>now()->timestamp-28800]);
check('absolute deadline enforced',req('GET','/api/admin/session',$recovery)['status'],401);
$client=[]; req('GET','/sanctum/csrf-cookie',$client);
check('client login preserved',req('POST','/api/login',$client,['email'=>'client@example.test','password'=>'fixture-password'])['status'],200);
check('client cannot enter admin',req('GET','/api/admin/session',$client)['status'],401);
check('client finance works',req('GET','/api/expenses',$client)['status'],200);
check('admin cannot enter finance',req('GET','/api/expenses',$recovery)['status'],401);
$j=[]; check('Bearer rejected admin',req('GET','/api/admin/csrf',$j,[],['HTTP_AUTHORIZATION'=>'Bearer '.$bearer])['status'],401);
check('foreign origin refused',req('GET','/api/admin/csrf',$j,[],['HTTP_ORIGIN'=>'https://other.test'])['status'],403);
check('client still works after admin requests',req('GET','/api/user',$client)['status'],200);
$app=bootMfa(); Auth::guard('admin')->setUser(AdminIdentity::first()); Auth::shouldUse('admin');
$r=Illuminate\Http\Request::create('/api/expenses'); $r->setUserResolver(fn()=>AdminIdentity::first());
check('injected admin principal refused finance',(new App\Http\Middleware\RequireClientIdentity)->handle($r,fn()=>throw new RuntimeException('Principal leak'))->getStatusCode(),403);
$app=bootMfa(); config(['admin.mfa_enabled'=>false]);
$r=Illuminate\Http\Request::create('/api/admin/session');
check('runtime disabled even cached routes',(new App\Http\Middleware\AdminMfaEnabled)->handle($r,fn()=>throw new RuntimeException('Gate bypass'))->getStatusCode(),503);
check('lot 1 unconditional gate preserved',(new App\Http\Middleware\AdminMfaNotDelivered)->handle($r,fn()=>throw new RuntimeException('Gate bypass'))->getStatusCode(),403);
$allowed=['api/admin/csrf','api/admin/login','api/admin/enrollment','api/admin/mfa','api/admin/recovery','api/admin/logout','api/admin/session','api/admin/reauthenticate','api/admin/factor/change'];
$actual=array_values(array_filter(array_map(fn($r)=>$r->uri(),iterator_to_array($app['router']->getRoutes())),fn($r)=>str_starts_with($r,'api/admin')));
sort($allowed); sort($actual); check('only auth endpoints no business routes',$actual,$allowed);
check('security event payload has no secret fields',array_keys((array)DB::table('admin_security_events')->first()),['id','event','admin_id','created_at']);
$failed=count(array_filter($results,fn($r)=>!$r['pass']));
file_put_contents($root.'/storage/app/admin-mfa-12/evidence/mfa-'.DB::getDriverName().'-'.$mode.'.json',json_encode(['mode'=>$mode,'passed'=>count($results)-$failed,'failed'=>$failed,'checks'=>$results],JSON_PRETTY_PRINT));
echo 'TOTAL '.count($results).' FAILED '.$failed."\n"; exit($failed?1:0);
