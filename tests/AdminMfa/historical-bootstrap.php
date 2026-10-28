<?php
// Existing Feature tests, unchanged, against the explicitly created disposable MySQL instance.
$root=dirname(__DIR__,2);$runtime=$root.'/storage/app/admin-mfa-12';
if(!is_dir($runtime.'/mysql-data'))throw new RuntimeException('Disposable MySQL instance missing');
foreach(['ADMIN_MFA_ENABLED'=>'false','APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('H',32)),
    'APP_BASE_PATH'=>$root,'APP_URL'=>'http://localhost:8000','FRONTEND_URL'=>'http://localhost:5173',
    'APP_CONFIG_CACHE'=>'storage/app/admin-mfa-12/cache/historical-config.php',
    'APP_ROUTES_CACHE'=>'storage/app/admin-mfa-12/cache/historical-routes.php',
    'APP_SERVICES_CACHE'=>'storage/app/admin-mfa-12/cache/historical-services.php',
    'APP_PACKAGES_CACHE'=>'storage/app/admin-mfa-12/cache/historical-packages.php',
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33412','DB_DATABASE'=>'gestionnaire_test',
    'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_SOCKET'=>'','DATABASE_URL'=>'',
    'MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync','SESSION_DRIVER'=>'array',
    'SESSION_DOMAIN'=>'','SESSION_SECURE_COOKIE'=>'false','SANCTUM_STATEFUL_DOMAINS'=>'localhost:5173',
    'CACHE_DRIVER'=>'array','CACHE_STORE'=>'array','BCRYPT_ROUNDS'=>'4'] as $key=>$value){
    putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;
}
require_once $root.'/vendor/autoload.php';
putenv('SESSION_CONNECTION');unset($_ENV['SESSION_CONNECTION'],$_SERVER['SESSION_CONNECTION']);
// Verify the actual server before allowing the historical test guard/migrations to run.
$pdo=new PDO('mysql:host=127.0.0.1;port=33412;dbname=gestionnaire_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server=$pdo->query('SELECT @@port AS port, @@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
if((int)$server['port']!==33412 || rtrim(str_replace('\\','/',$server['datadir']),'/')!==str_replace('\\','/',realpath($runtime.'/mysql-data')))throw new RuntimeException('Wrong database server');
if(file_exists($root.'/storage/app/admin-mfa-12/cache/historical-config.php')||file_exists($root.'/storage/app/admin-mfa-12/cache/historical-routes.php'))throw new RuntimeException('Unexpected historical test cache');
