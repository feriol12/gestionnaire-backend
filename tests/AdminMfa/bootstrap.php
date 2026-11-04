<?php

$root = dirname(__DIR__, 2);
if (basename($root) !== 'FM-ADMIN-MFA-12' || !is_file($root.'/.git/HEAD')) throw new RuntimeException('Isolated MFA copy required');
$runtime = $root.'/storage/app/admin-mfa-12/runtime';
foreach (['config','cache','tmp','sessions','views'] as $dir) if (!is_dir($runtime.'/'.$dir)) mkdir($runtime.'/'.$dir,0700,true);
foreach (['APP_ENV'=>'admin-mfa-test','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(str_repeat('M',32)),
    'APP_URL'=>'https://client.foundation.test','FRONTEND_URL'=>'https://client.foundation.test','APP_BASE_PATH'=>$root,
    'APP_CONFIG_CACHE'=>'storage/app/admin-mfa-12/runtime/cache/config.php','APP_ROUTES_CACHE'=>'storage/app/admin-mfa-12/runtime/cache/routes.php',
    'APP_SERVICES_CACHE'=>'storage/app/admin-mfa-12/runtime/cache/services.php','APP_PACKAGES_CACHE'=>'storage/app/admin-mfa-12/runtime/cache/packages.php',
    'APP_EVENTS_CACHE'=>'storage/app/admin-mfa-12/runtime/cache/events.php'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
require_once $root.'/vendor/autoload.php';

function mfaApp() {
    $root=dirname(__DIR__,2); $runtime=$root.'/storage/app/admin-mfa-12/runtime';
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    $app=require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('NO-ENV-MFA-TEST');
    $app->useConfigPath($runtime.'/config');
    $app->useBootstrapPath(__DIR__.'/bootstrap');
    $app->useStoragePath($runtime);
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,function()use($app,$runtime){
        $c=$app['config'];
        $c->set('database.connections',['sqlite'=>$c->get('database.connections.sqlite')]);
        if ($c->get('database.default')!=='sqlite' || $c->get('database.connections.sqlite.database')!==$runtime.'/test.sqlite') throw new RuntimeException('Unsafe test DB');
        if (getenv('MFA_TEST_MYSQL') === '1') {
            // Only the fresh task-specific loopback instance. Check its identity
            // before providers or migrations can perform any writes.
            $pdo=new PDO('mysql:host=127.0.0.1;port=33412;dbname=admin_mfa12_test','root','');
            $server=$pdo->query('SELECT @@port AS port, @@datadir AS dir')->fetch(PDO::FETCH_ASSOC);
            $expected=dirname($runtime).'/mysql-data';
            if ((int)$server['port']!==33412 || strtolower(str_replace('\\','/',rtrim($server['dir'],'/\\')))!==strtolower(str_replace('\\','/',$expected))) throw new RuntimeException('Wrong local MySQL instance');
            $c->set('database.default','mysql');
            $c->set('database.connections',['mysql'=>['driver'=>'mysql','host'=>'127.0.0.1','port'=>33412,'database'=>'admin_mfa12_test','username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]]);
            $c->set('session.connection','mysql'); $c->set('admin.session.connection','mysql');
        }
    });
    return $app;
}
