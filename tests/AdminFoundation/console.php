<?php
require __DIR__.'/bootstrap.php';
$app=foundationApp();
$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
$input=new \Symfony\Component\Console\Input\ArgvInput();
$status=$kernel->handle($input,new \Symfony\Component\Console\Output\ConsoleOutput());
$kernel->terminate($input,$status);
exit($status);
