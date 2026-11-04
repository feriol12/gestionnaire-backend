<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$engine=$argv[1]??'';
if(!in_array($engine,['sqlite','mysql','mariadb','pgsql'],true))throw new RuntimeException('Only explicit isolated engines allowed');
$runtime=dirname(__DIR__,2).'/storage/app/admin-harden-09';
$app=foundationApp(false);$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$results=[];
$check=function($name,$pass)use(&$results){$results[]=['name'=>$name,'status'=>$pass?'PASS':'FAIL'];echo ($pass?'PASS ':'FAIL ').$name."\n";};
if(in_array($engine,['mysql','mariadb','pgsql'],true)){
    $port=['mysql'=>33379,'mariadb'=>33380,'pgsql'=>33381][$engine];
    config(['database.default'=>'harden_mysql','database.connections.harden_mysql'=>[
        'driver'=>$engine,'host'=>'127.0.0.1','port'=>$port,'database'=>'fm_admin_harden09_test',
        'username'=>$engine==='pgsql'?'postgres':'root','password'=>'','charset'=>$engine==='pgsql'?'utf8':'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,
    ]]);
    $server=$engine==='pgsql'
        ? DB::selectOne("SELECT current_setting('server_version') AS version, current_setting('port') AS port, current_setting('data_directory') AS datadir")
        : DB::selectOne('SELECT @@version AS version, @@port AS port, @@datadir AS datadir');
    $expected=str_replace('\\','/',(string)realpath($runtime.'/'.$engine.'-data'));
    if($expected==='' || (int)$server->port!==$port || rtrim(str_replace('\\','/',$server->datadir),'/')!==$expected)throw new RuntimeException('Wrong isolated SQL instance');
    $version=$server->version;
}else{
    $file=$runtime.'/sql-test.sqlite';if(!file_exists($file))touch($file);
    config(['database.default'=>'harden_sqlite','database.connections.harden_sqlite'=>['driver'=>'sqlite','database'=>$file,'prefix'=>'','foreign_key_constraints'=>true]]);
    $version=DB::selectOne('select sqlite_version() as version')->version;
}
$migration=require dirname(__DIR__,2).'/database/migrations/2026_10_27_000001_create_admin_foundations.php';
try{
    if(Schema::hasTable('admin_identities')||Schema::hasTable('admin_sessions'))throw new RuntimeException('Tables already present: preserve and inspect before retry');
    $migration->up();
    $check('tables created',Schema::hasTable('admin_identities')&&Schema::hasTable('admin_sessions'));
    DB::table('admin_identities')->insert(['id'=>1,'email'=>'fixture@example.test','password'=>'unused']);
    foreach([1,2] as $id){$denied=false;try{DB::table('admin_identities')->insert(['id'=>$id,'email'=>'other'.$id.'@example.test','password'=>'unused']);}catch(\Illuminate\Database\QueryException){$denied=true;}$check('second identity rejected '.$id,$denied);}
    $denied=false;try{DB::table('admin_identities')->where('id',1)->update(['id'=>2]);}catch(\Illuminate\Database\QueryException){$denied=true;}$check('singleton enforced on update',$denied);
    $row=['id'=>'fixture-session','user_id'=>2,'payload'=>'fictional','last_activity'=>1];
    $denied=false;try{DB::table('admin_sessions')->insert($row);}catch(\Illuminate\Database\QueryException){$denied=true;}$check('foreign key enforced',$denied);
    $row['user_id']=1;DB::table('admin_sessions')->insert($row);
    DB::table('admin_identities')->where('id',1)->delete();$check('session cascade',DB::table('admin_sessions')->count()===0);
    $indexes=Schema::getIndexes('admin_sessions');
    $check('user_id indexed',count(array_filter($indexes,fn($i)=>$i['columns']===['user_id']))>0);
    $check('last_activity indexed',count(array_filter($indexes,fn($i)=>$i['columns']===['last_activity']))>0);
    $migration->down();$check('rollback removes both tables',!Schema::hasTable('admin_sessions')&&!Schema::hasTable('admin_identities'));
    // DDL transaction semantics, on a clean disposable schema.
    DB::beginTransaction();$migration->up();$level=DB::connection()->getPdo()->inTransaction();
    if($level){DB::rollBack();$check('transactional DDL rolls back',in_array($engine,['sqlite','pgsql'],true)&&!Schema::hasTable('admin_identities'));}
    else{$check('DDL commits implicitly',in_array($engine,['mysql','mariadb'],true));$migration->down();}
    // Deliberately fail the second CREATE. Never touch a non-fixture table.
    DB::statement('CREATE TABLE admin_sessions (fixture_sentinel INTEGER)');
    $errored=false;
    try{
        if(in_array($engine,['sqlite','pgsql'],true))DB::transaction(fn()=>$migration->up());
        else $migration->up();
    }catch(\Illuminate\Database\QueryException){$errored=true;}
    $check('mid-migration error observed',$errored);
    $check('partial DDL state matches engine',Schema::hasTable('admin_identities')===in_array($engine,['mysql','mariadb'],true));
    if(in_array($engine,['mysql','mariadb'],true)){
        $denied=false;try{DB::table('admin_identities')->insert(['id'=>2,'email'=>'bad@example.test','password'=>'unused']);}catch(\Illuminate\Database\QueryException){$denied=true;}
        $check('partial MySQL table still enforces singleton',$denied);
    }
    $migration->down();
}catch(Throwable $e){$results[]=['name'=>'migration execution','status'=>'FAIL','error'=>$e->getMessage()];echo $e->getMessage()."\n";}
file_put_contents($runtime.'/evidence/sql-'.$engine.'.json',json_encode(['engine'=>$engine,'version'=>$version,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
exit(count(array_filter($results,fn($r)=>$r['status']==='FAIL'))?1:0);
