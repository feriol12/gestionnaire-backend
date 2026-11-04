<?php

// Standalone test environment: does not alter tests/Support/TestDatabaseSafety.
$root = dirname(__DIR__,2);
$runtime = $root.'/storage/app/admin-harden-09';
if (!is_file($root.'/.git/HEAD')) throw new RuntimeException('Independent checkout required');
foreach(['config','evidence','cache','tmp','sessions','views'] as $dir) if(!is_dir($runtime.'/'.$dir)) mkdir($runtime.'/'.$dir,0700,true);
foreach(['APP_ENV'=>'admin-foundation-test','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(str_repeat('T',32)),
    'APP_URL'=>'https://client.foundation.test','FRONTEND_URL'=>'https://client.foundation.test','APP_BASE_PATH'=>$root,
    'APP_CONFIG_CACHE'=>'storage/app/admin-harden-09/cache/config.php','APP_ROUTES_CACHE'=>'storage/app/admin-harden-09/cache/routes.php',
    'APP_SERVICES_CACHE'=>'storage/app/admin-harden-09/cache/services.php','APP_PACKAGES_CACHE'=>'storage/app/admin-harden-09/cache/packages.php',
    'APP_EVENTS_CACHE'=>'storage/app/admin-harden-09/cache/events.php'] as $key=>$value) {
    putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;
}
require_once $root.'/vendor/autoload.php';
require_once __DIR__.'/Fixtures.php';

function foundationApp(bool $fixtures=true) {
    $root=dirname(__DIR__,2);$runtime=$root.'/storage/app/admin-harden-09';
    \Illuminate\Support\Facades\Facade::clearResolvedInstances();
    $app=require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('NO-ENV-ADMIN-FOUNDATION-TEST');
    $app->useConfigPath($runtime.'/config');
    // Stock cache commands bootstrap a second application through this test-only entry.
    $app->useBootstrapPath(__DIR__.'/bootstrap');
    $app->useStoragePath($runtime);
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class,function()use($app){
        // Laravel merges built-in connection defaults. Remove those before any provider boots.
        $app['config']->set('database.connections',['sqlite'=>$app['config']->get('database.connections.sqlite')]);
    });
    $app->instance('foundation.trace',new \ArrayObject());
    foreach(['auth','session','session.store',\App\Http\Middleware\SelectAdminContext::class,
        \App\Http\Middleware\EncryptCookies::class,\Illuminate\Session\Middleware\StartSession::class] as $service) {
        $app->beforeResolving($service,function()use($app,$service){$app['foundation.trace'][]=$service;});
    }
    if ($fixtures) {
        $app->instance(\Tests\AdminFoundation\FixtureCapability::class,new \Tests\AdminFoundation\FixtureCapability());
        $app->register(\Tests\AdminFoundation\FixtureProvider::class);
    }
    $app->booted(function()use($app,$runtime){
        $config=$app['config'];
        if($config->get('database.default')!=='sqlite' || $config->get('database.connections.sqlite.database')!==$runtime.'/test.sqlite'
            || count($config->get('database.connections'))!==1 || $app->environment()!=='admin-foundation-test') {
            throw new RuntimeException('Unsafe foundation test database configuration');
        }
    });
    return $app;
}
