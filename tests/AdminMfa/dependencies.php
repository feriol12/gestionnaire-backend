<?php
$root=dirname(__DIR__,2);
$before=json_decode(file_get_contents($root.'/storage/app/admin-mfa-12/before/composer.lock'),true,512,JSON_THROW_ON_ERROR);
$after=json_decode(file_get_contents($root.'/composer.lock'),true,512,JSON_THROW_ON_ERROR);
$old=[]; $new=[];
foreach(array_merge($before['packages'],$before['packages-dev']) as $p) $old[$p['name']]=$p;
foreach(array_merge($after['packages'],$after['packages-dev']) as $p) $new[$p['name']]=$p;
foreach($old as $name=>$p) if(!isset($new[$name])||$p!==$new[$name]) throw new RuntimeException('Existing package changed: '.$name);
$expected=['paragonie/constant_time_encoding'=>['v3.1.3','d5b01a39b3415c2cd581d3bd3a3575c1ebbd8e77'],
    'pragmarx/google2fa'=>['v9.1.0','f00bc788c555adfb6765c437ff3538e59cd88af1']];
$added=array_diff_key($new,$old);
if(array_keys($added)!==array_keys($expected)) throw new RuntimeException('Unexpected added packages');
foreach($expected as $name=>[$version,$reference]) {
    $p=$added[$name];
    if($p['version']!==$version||$p['source']['reference']!==$reference||$p['dist']['reference']!==$reference||$p['license']!==['MIT']) throw new RuntimeException('Provenance mismatch');
}
$beforeJson=json_decode(file_get_contents($root.'/storage/app/admin-mfa-12/before/composer.json'),true,512,JSON_THROW_ON_ERROR);
$afterJson=json_decode(file_get_contents($root.'/composer.json'),true,512,JSON_THROW_ON_ERROR);
$beforeJson['require']['pragmarx/google2fa']='9.1.0';
if($beforeJson!==$afterJson) throw new RuntimeException('Unexpected composer.json modification');
echo json_encode(['existing_packages_unchanged'=>count($old),'added'=>$expected,'framework'=>$new['laravel/framework']['version'],'composer_json_only_change'=>'require pragmarx/google2fa:9.1.0','composer_lock_sha256'=>hash_file('sha256',$root.'/composer.lock')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
