<?php

require __DIR__.'/bootstrap.php';
$runtime=dirname(__DIR__,2).'/storage/app/admin-harden-09';
foreach(glob(dirname(__DIR__,2).'/config/*.php') as $source) copy($source,$runtime.'/config/'.basename($source));
function writeFoundationConfig($name,$value) {
    file_put_contents(dirname(__DIR__,2).'/storage/app/admin-harden-09/config/'.$name.'.php','<?php return '.var_export($value,true).';');
}
writeFoundationConfig('database',['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>$runtime.'/test.sqlite','prefix'=>'','foreign_key_constraints'=>true]],'migrations'=>['table'=>'migrations','update_date_on_publish'=>true]]);
writeFoundationConfig('cache',['default'=>'array','stores'=>['array'=>['driver'=>'array','serialize'=>false]],'prefix'=>'foundation-test']);
writeFoundationConfig('queue',['default'=>'sync','connections'=>['sync'=>['driver'=>'sync'],'database'=>['driver'=>'database','connection'=>'sqlite','table'=>'jobs','queue'=>'default','retry_after'=>90,'after_commit'=>false]],'failed'=>['driver'=>'null']]);
writeFoundationConfig('mail',['default'=>'array','mailers'=>['array'=>['transport'=>'array']],'from'=>['address'=>'fixture@example.test','name'=>'Fixture']]);
writeFoundationConfig('logging',['default'=>'single','channels'=>['single'=>['driver'=>'single','path'=>$runtime.'/test.log','level'=>'debug']]]);
writeFoundationConfig('services',[]);
writeFoundationConfig('cors',['paths'=>[],'allowed_methods'=>[],'allowed_origins'=>[],'allowed_origins_patterns'=>[],'allowed_headers'=>[],'exposed_headers'=>[],'max_age'=>0,'supports_credentials'=>false]);
writeFoundationConfig('session',['driver'=>'database','connection'=>'sqlite','table'=>'sessions','files'=>$runtime.'/sessions','lifetime'=>1440,'expire_on_close'=>false,'encrypt'=>false,'lottery'=>[0,100],'cookie'=>'foundation_client','path'=>'/','domain'=>null,'secure'=>true,'http_only'=>true,'same_site'=>'lax','partitioned'=>false]);
// Preserve the shipped admin configuration except the fixture origin / connection.
$config=require dirname(__DIR__,2).'/config/admin.php';
$config['origin']='https://client.foundation.test';$config['session']['connection']='sqlite';writeFoundationConfig('admin',$config);
writeFoundationConfig('sanctum',['stateful'=>['client.foundation.test'],'guard'=>['web'],'expiration'=>null,'middleware'=>[
 'authenticate_session'=>Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
 'encrypt_cookies'=>App\Http\Middleware\EncryptCookies::class,
 'validate_csrf_token'=>App\Http\Middleware\PreventRequestForgery::class,
]]);
echo "Isolated configuration prepared; no connection opened.\n";
