<?php
require __DIR__.'/bootstrap.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$url=parse_url($input['url']);
if(($url['host']??'')!=='client.foundation.test')exit(7);
$cookies=[];$server=['HTTPS'=>'on','SERVER_PORT'=>443,'REMOTE_ADDR'=>'127.0.0.1'];
foreach($input['headers'] as $key=>$value){
    if(strtolower($key)==='cookie')foreach(explode(';',$value) as $pair){$parts=explode('=',trim($pair),2);if(count($parts)===2)$cookies[$parts[0]]=rawurldecode($parts[1]);}
    $server['HTTP_'.strtoupper(str_replace('-','_',$key))]=$value;
}
$request=\Illuminate\Http\Request::create($input['url'],$input['method'],json_decode($input['postData']??'',true)??[],$cookies,[],$server);
$app=foundationApp();$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$response=$kernel->handle($request);$headers=[];
foreach($response->headers->allPreserveCaseWithoutCookies() as $name=>$values)foreach($values as $value)$headers[]=['name'=>$name,'value'=>$value];
foreach($response->headers->getCookies() as $cookie)$headers[]=['name'=>'Set-Cookie','value'=>(string)$cookie];
$kernel->terminate($request,$response);
echo json_encode(['status'=>$response->getStatusCode(),'headers'=>$headers,'body'=>base64_encode($response->getContent())]);
