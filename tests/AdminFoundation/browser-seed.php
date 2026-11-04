<?php
require __DIR__.'/bootstrap.php';
$app=foundationApp();$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(\App\Models\AdminIdentity::count()!==0)throw new RuntimeException('Browser fixture expects empty isolated admin table');
$admin=new \App\Models\AdminIdentity();$admin->forceFill(['id'=>1,'email'=>'browser-admin@example.test','password'=>'fixture-password'])->save();
$user=\App\Models\User::create(['name'=>'Browser fixture','email'=>'browser@example.test','password'=>'fixture-password']);
$user->forceFill(['email_verified_at'=>now()])->save();
echo "Browser fixtures created in isolated SQLite only.\n";
