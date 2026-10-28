<?php
require __DIR__.'/bootstrap.php';
use App\Models\AdminIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

$mode=$argv[1]??'uncached';$root=dirname(__DIR__,2);$runtime=$root.'/storage/app/admin-harden-09';
$results=[];$requests=[];$injections=[];
function expect($name,$actual,$expected) {
    global $results;
    $pass=$actual===$expected;
    $results[]=['name'=>$name,'status'=>$pass?'PASS':'FAIL','actual'=>$actual,'expected'=>$expected];
    echo ($pass?'PASS ':'FAIL ').$name.($pass?'':' '.json_encode($actual))."\n";
}
function bootFoundation($fixtures=true) {
    $app=foundationApp($fixtures);$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();return $app;
}

$app=bootFoundation(false);
if($mode==='uncached') {
    $routes=array_map(fn($r)=>$r->uri(),iterator_to_array($app['router']->getRoutes()));
    expect('production has no admin routes',count(array_filter($routes,fn($r)=>str_starts_with($r,'api/admin'))),0);
}
expect('Sanctum keeps only web',config('sanctum.guard'),['web']);
expect('client reset broker provider',config('auth.passwords.users.provider'),'users');
expect('admin model no PAT',method_exists(AdminIdentity::class,'tokens'),false);
expect('admin does not implement client password reset contract',is_subclass_of(AdminIdentity::class,\Illuminate\Contracts\Auth\CanResetPassword::class),false);
expect('admin model no finance',method_exists(AdminIdentity::class,'budgets')||method_exists(AdminIdentity::class,'expenses'),false);

// Disposable, connection already checked by bootstrap; actual selected migrations, not fabricated schema.
DB::statement('PRAGMA foreign_keys=OFF');
foreach(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) DB::statement('DROP TABLE "'.str_replace('"','""',$t->name).'"');
DB::statement('PRAGMA foreign_keys=ON');
foreach(['2014_10_12_000000_create_users_table.php','2014_10_12_100000_create_password_reset_tokens_table.php',
 '2019_12_14_000001_create_personal_access_tokens_table.php','2026_04_20_125418_create_expenses_table.php',
 '2026_05_08_211149_create_budgets_table.php','2026_06_02_232941_add_last_activity_to_users_table.php',
 '2026_10_02_002051_create_jobs_table.php','2026_10_06_210747_create_sessions_table.php','2026_10_27_000001_create_admin_foundations.php'] as $file) {
    (require $root.'/database/migrations/'.$file)->up();
}
$user=User::create(['name'=>'Fixture','email'=>'client@example.test','password'=>'fixture-password']);
$user->forceFill(['email_verified_at'=>now()])->save();
$admin=new AdminIdentity();$admin->forceFill(['id'=>1,'email'=>'admin@example.test','password'=>'fixture-password','remember_token'=>'fictional','auth_version'=>1])->save();
expect('same numeric ID',$admin->id,$user->id);
expect('secret fields not serialized',array_intersect(['password','remember_token','auth_version'],array_keys($admin->toArray())),[]);
foreach([1,2] as $id) {
    $rejected=false;try {DB::table('admin_identities')->insert(['id'=>$id,'email'=>"duplicate$id@example.test",'password'=>'unused']);}catch(\Illuminate\Database\QueryException){$rejected=true;}
    expect('database refuses extra admin '.$id,$rejected,true);
}
expect('client broker cannot retrieve admin',\Illuminate\Support\Facades\Password::broker('users')->getUser(['email'=>'admin@example.test']),null);
$bearer=$user->createToken('fixture')->plainTextToken;

function requestFoundation($method,$uri,&$jar,$body=[],$token=null,$bearer=null,$origin='https://client.foundation.test') {
    global $requests,$mode;
    $app=foundationApp();$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
    $server=['HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_ACCEPT'=>'application/json','HTTP_ORIGIN'=>$origin,'HTTP_REFERER'=>$origin.'/','REMOTE_ADDR'=>'127.0.0.1'];
    if($token!==null)$server['HTTP_X_CSRF_TOKEN']=$token;
    if(isset($jar['XSRF-TOKEN']))$server['HTTP_X_XSRF_TOKEN']=$jar['XSRF-TOKEN'];
    if($bearer!==null){$server['HTTP_AUTHORIZATION']='Bearer '.$bearer;unset($server['HTTP_ORIGIN'],$server['HTTP_REFERER']);}
    $req=\Illuminate\Http\Request::create('https://client.foundation.test'.$uri,$method,$body,$jar,[],$server);
    $response=$kernel->handle($req);$cookies=[];
    foreach($response->headers->getCookies() as $cookie) {
        $jar[$cookie->getName()]=$cookie->getValue();
        $cookies[]=['name'=>$cookie->getName(),'domain'=>$cookie->getDomain(),'path'=>$cookie->getPath(),'secure'=>$cookie->isSecure(),'httpOnly'=>$cookie->isHttpOnly(),'sameSite'=>$cookie->getSameSite()];
    }
    $userAfter=$req->user();
    $record=['method'=>$method,'uri'=>$uri,'status'=>$response->getStatusCode(),'cookies'=>$cookies,
        'principalAtClientGate'=>$app->bound('foundation.principal')?$app['foundation.principal']:null,
        'guardAfter'=>$app['auth']->getDefaultDriver(),
        'principalAfter'=>['type'=>$userAfter?get_class($userAfter):null,'id'=>$userAfter?->getAuthIdentifier()],
        'trace'=>$app['foundation.trace']->getArrayCopy(),'table'=>config('session.table'),
        'configCached'=>$app->configurationIsCached(),'routesCached'=>$app->routesAreCached()];
    $requests[]=$record;
    $kernel->terminate($req,$response);
    return ['status'=>$response->getStatusCode(),'data'=>json_decode($response->getContent(),true)??[],'meta'=>$record];
}
$client=[];$r=requestFoundation('GET','/sanctum/csrf-cookie',$client);expect('client csrf',$r['status'],204);
$r=requestFoundation('POST','/api/login',$client,['email'=>'client@example.test','password'=>'wrong']);expect('wrong client password',$r['status'],401);
$before=$client['foundation_client'];
$r=requestFoundation('POST','/api/login',$client,['email'=>'client@example.test','password'=>'fixture-password']);expect('existing client login',$r['status'],200);
expect('client session cookie rotates',$client['foundation_client']!==$before,true);
$r=requestFoundation('GET','/api/user',$client);expect('existing client user',$r['data']['id']??null,1);
$r=requestFoundation('GET','/api/expenses',$client);expect('real client finance positive',$r['status'],200);
foreach(['/api/budgets','/api/dashboard/categories','/api/dashboard/evolution','/api/dashboard/recent-transactions','/api/export/pdf','/api/export/budgets/pdf'] as $uri){
    $r=requestFoundation('GET',$uri,$client);expect('legitimate SPA '.$uri,$r['status'],200);
}
$anonymous=[];$r=requestFoundation('GET','/api/expenses',$anonymous);expect('anonymous financial request refused',$r['status'],401);
$app=bootFoundation();$other=User::create(['name'=>'Other fixture','email'=>'other@example.test','password'=>'fixture-password']);
$expenseId=DB::table('expenses')->insertGetId(['user_id'=>$other->id,'description'=>'FICTIONAL TEST ONLY','amount'=>1,'category'=>'Transport','date'=>'2026-01-01']);
$r=requestFoundation('GET','/api/expenses/'.$expenseId,$client);expect('client ownership still enforced',$r['status'],404);
$onlyClient=$client;$r=requestFoundation('GET','/api/admin/protected',$onlyClient);expect('client refused admin',$r['status'],401);
$adminJar=[];$r=requestFoundation('GET','/api/admin/fixture/csrf',$adminJar);$csrf=$r['data']['csrf']??'';
$before=$adminJar['fluxmoneva_admin_session']??'';
$r=requestFoundation('POST','/api/admin/fixture/seed',$adminJar,[],$csrf);expect('test-only seed status',$r['status'],200);$csrf=$r['data']['csrf']??'';
expect('admin rotation',$adminJar['fluxmoneva_admin_session']!==$before,true);
$r=requestFoundation('GET','/api/admin/fixture/who',$adminJar);expect('admin resolves own guard',$r['data']['type']??null,AdminIdentity::class);expect('admin fixture ID',$r['data']['id']??null,1);
$r=requestFoundation('GET','/api/admin/protected',$adminJar);expect('MFA absence refuses even forged session claim',$r['data']['code']??null,'ADMIN_MFA_NOT_DELIVERED');
foreach(['/api/expenses','/api/budgets','/api/dashboard/recent-transactions','/api/export/pdf','/api/export/budgets/pdf','/api/user'] as $uri) {
    $j=$adminJar;$r=requestFoundation('GET',$uri,$j);expect('admin cookie refused '.$uri,$r['status'],401);
}
$both=array_merge($client,$adminJar);
$r=requestFoundation('GET','/api/user',$both);expect('mixed cookies client context',$r['data']['email']??null,'client@example.test');
$r=requestFoundation('GET','/api/admin/fixture/who',$both);expect('mixed cookies admin context',$r['data']['type']??null,AdminIdentity::class);
$r=requestFoundation('GET','/api/user',$both);expect('client-admin-client',$r['data']['email']??null,'client@example.test');
$r=requestFoundation('GET','/api/admin/fixture/who',$both);expect('admin-client-admin',$r['data']['type']??null,AdminIdentity::class);
$r=requestFoundation('POST','/api/admin/fixture/seed',$adminJar);expect('missing CSRF',$r['status'],419);
$r=requestFoundation('POST','/api/admin/fixture/seed',$both,[],'invalid');expect('client XSRF not admin CSRF',$r['status'],419);
$r=requestFoundation('POST','/api/logout',$client,[],$csrf);expect('admin token not client CSRF',$r['status'],419);
$r=requestFoundation('GET','/api/admin/fixture/error',$adminJar);expect('error response',$r['status'],422);
$r=requestFoundation('GET','/api/admin/protected',$adminJar,[],null,null,'https://other.foundation.test');expect('foreign origin refused',$r['status'],403);
$j=[];$r=requestFoundation('GET','/api/user',$j,[],null,$bearer);expect('client PAT still works',$r['status'],200);
$j=[];$r=requestFoundation('GET','/api/admin/protected',$j,[],null,$bearer);expect('client PAT refused admin',$r['status'],401);
$oldAdmin=$adminJar;$r=requestFoundation('POST','/api/admin/fixture/logout',$adminJar,[],$csrf);expect('admin fixture logout',$r['status'],200);
$r=requestFoundation('GET','/api/admin/fixture/who',$oldAdmin);expect('old admin session invalidated',$r['data']['id']??'none','none');
$r=requestFoundation('GET','/api/user',$client);expect('client survives admin logout',$r['status'],200);
$oldClient=$client;$r=requestFoundation('POST','/api/logout',$client);expect('existing client logout',$r['status'],200);
$r=requestFoundation('GET','/api/user',$oldClient);expect('old client session invalidated',$r['status'],401);

// Targeted regressions through the existing controllers (all data are disposable).
$new=[];requestFoundation('GET','/sanctum/csrf-cookie',$new);
$r=requestFoundation('POST','/api/register',$new,['name'=>'New fixture','email'=>'new@example.test','password'=>'fixture-password','password_confirmation'=>'fixture-password','role'=>'superadmin','admin'=>true]);
expect('existing registration',$r['status'],200);
expect('registration cannot create admin',AdminIdentity::count(),1);
$r=requestFoundation('POST','/api/login',$new,['email'=>'new@example.test','password'=>'fixture-password']);expect('unverified login denied',$r['status'],403);
expect('unverified login code',$r['data']['code']??null,'EMAIL_UNVERIFIED');
$r=requestFoundation('POST','/api/login',$client,['email'=>'client@example.test','password'=>'fixture-password']);expect('client relogin',$r['status'],200);
$r=requestFoundation('PUT','/api/user/password',$client,['current_password'=>'wrong','password'=>'changed-password','password_confirmation'=>'changed-password']);expect('change password rejects wrong current',$r['status'],422);
$r=requestFoundation('PUT','/api/user/password',$client,['current_password'=>'fixture-password','password'=>'changed-password','password_confirmation'=>'changed-password']);expect('existing password change',$r['status'],200);
$j=[];$r=requestFoundation('GET','/api/user',$j,[],null,$bearer);expect('password change revokes client PAT',$r['status'],401);
$r=requestFoundation('GET','/api/user',$client);expect('current session after password change',$r['status'],200);
$app=bootFoundation();$resetToken=\Illuminate\Support\Facades\Password::broker('users')->createToken(User::findOrFail(1));
$reset=[];requestFoundation('GET','/sanctum/csrf-cookie',$reset);
$r=requestFoundation('POST','/api/forgot-password',$reset,['email'=>'client@example.test']);expect('existing forgot-password',$r['status'],200);
$r=requestFoundation('POST','/api/reset-password',$reset,['email'=>'client@example.test','token'=>$resetToken,'password'=>'reset-password','password_confirmation'=>'reset-password']);expect('existing password reset',$r['status'],200);
$r=requestFoundation('GET','/api/user',$client);expect('reset invalidates old session',$r['status'],401);
$r=requestFoundation('GET','/api/user',$reset);expect('reset does not login',$r['status'],401);
$r=requestFoundation('POST','/api/login',$reset,['email'=>'client@example.test','password'=>'reset-password']);expect('login after reset',$r['status'],200);
$app=bootFoundation();DB::table('users')->where('id',1)->update(['last_activity'=>now()->subHours(25)]);
$r=requestFoundation('GET','/api/user',$reset);expect('existing inactivity expiration',$r['status'],401);

// Explicit wrong-principal injection checks the new server-side guard, not just cookies.
$app=bootFoundation();Auth::guard('admin')->setUser(AdminIdentity::findOrFail(1));
$req=\Illuminate\Http\Request::create('/api/expenses');$req->setUserResolver(fn()=>AdminIdentity::findOrFail(1));
$r=(new \App\Http\Middleware\RequireClientIdentity)->handle($req,fn()=>throw new RuntimeException('Wrong principal accepted'));
expect('injected admin principal denied client',$r->getStatusCode(),403);
$injections[]=['scenario'=>'admin injected','guard'=>Auth::getDefaultDriver(),'requestType'=>get_class($req->user()),'requestId'=>$req->user()->id,'ambientType'=>Auth::user()?get_class(Auth::user()):null,'http'=>$r->getStatusCode()];
Auth::guard('sanctum')->setUser(AdminIdentity::findOrFail(1));Auth::shouldUse('sanctum');
$r=(new \App\Http\Middleware\RequireClientIdentity)->handle($req,fn()=>throw new RuntimeException('Admin Sanctum principal accepted'));
expect('admin injected directly into Sanctum refused',$r->getStatusCode(),403);
$injections[]=['scenario'=>'admin injected in Sanctum','guard'=>Auth::getDefaultDriver(),'requestType'=>get_class($req->user()),'requestId'=>$req->user()->id,'sanctumType'=>get_class(Auth::guard('sanctum')->user()),'ambientType'=>get_class(Auth::user()),'http'=>$r->getStatusCode()];
$late=false;try{(new \App\Http\Middleware\SelectAdminContext($app))->handle($req,fn()=>response());}catch(LogicException){$late=true;}
expect('late context resolution fails closed',$late,true);
$app=bootFoundation();config(['admin.origin'=>null]);
$r=(new \App\Http\Middleware\SelectAdminContext($app))->handle(\Illuminate\Http\Request::create('https://client.foundation.test/admin/check'),fn()=>throw new RuntimeException('Missing origin accepted'));
expect('origin not configured fails closed',$r->getStatusCode(),503);
$app=bootFoundation();config(['admin.origin'=>'https://client.foundation.test']);
Auth::guard('admin')->setUser(AdminIdentity::findOrFail(1));Auth::shouldUse('admin');
$clientUser=User::findOrFail(1);Auth::guard('sanctum')->setUser($clientUser);
$req=\Illuminate\Http\Request::create('/api/expenses');$req->setUserResolver(fn()=>$clientUser);
$r=(new \App\Http\Middleware\RequireClientIdentity)->handle($req,fn()=>throw new RuntimeException('Ambient admin guard accepted'));
expect('client resolver with ambient admin guard refused',$r->getStatusCode(),403);
$injections[]=['scenario'=>'client Sanctum with ambient admin','guard'=>Auth::getDefaultDriver(),'requestType'=>get_class($req->user()),'requestId'=>$req->user()->id,'sanctumType'=>get_class(Auth::guard('sanctum')->user()),'ambientType'=>get_class(Auth::user()),'ambientId'=>Auth::id(),'http'=>$r->getStatusCode()];

$app=bootFoundation(false);config(['admin.enabled'=>true,'admin.fixtures'=>true]);
$denied=false;try{(new \Tests\AdminFoundation\FixtureProvider($app))->boot();}catch(LogicException){$denied=true;}
expect('configuration cannot register fixtures',$denied,true);
$denied=false;try{new \Tests\AdminFoundation\FixtureController();}catch(LogicException){$denied=true;}
expect('fixture controller refuses missing harness capability',$denied,true);

// Dedicated migration rollback and recreation are exercised only on the isolated SQLite database.
$app=bootFoundation();$migration=require $root.'/database/migrations/2026_10_27_000001_create_admin_foundations.php';
$migration->down();expect('migration rollback drops admin sessions',\Illuminate\Support\Facades\Schema::hasTable('admin_sessions'),false);
$migration->up();expect('migration recreates empty identity table',AdminIdentity::count(),0);

foreach($requests as $i=>$record) {
    expect('cache state '.$i,[$record['configCached'],$record['routesCached']],[$mode==='cached',$mode==='cached']);
    if(str_starts_with($record['uri'],'/api/admin/') && !in_array($record['status'],[401,403],true)) {
        expect('context before resolution '.$i,$record['trace'][0]??null,\App\Http\Middleware\SelectAdminContext::class);
        expect('separate admin store '.$i,$record['table'],'admin_sessions');
    }
    foreach($record['cookies'] as $c) {
        if(str_starts_with($record['uri'],'/api/admin/')) {
            expect('admin cookie contract '.$i,[$c['name'],$c['domain'],$c['path'],$c['secure'],$c['httpOnly'],$c['sameSite']],['fluxmoneva_admin_session',null,'/api/admin',true,true,'strict']);
        } else expect('no admin cookie on client '.$i,$c['name']==='fluxmoneva_admin_session',false);
    }
}
$failed=count(array_filter($results,fn($r)=>$r['status']==='FAIL'));
file_put_contents($runtime.'/evidence/'.$mode.'.json',json_encode(['mode'=>$mode,'php'=>PHP_VERSION,'assertions'=>$results,'requests'=>$requests,'injections'=>$injections,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'TOTAL '.count($results).' FAIL '.$failed.' REQUESTS '.count($requests)."\n";
exit($failed?1:0);
