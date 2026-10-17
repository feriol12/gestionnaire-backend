<?php

/*
 * LWS-BE-06C (tests, I1) — processus PHP distinct, sans Laravel : acquiert le
 * verrou du worker, l'annonce sur STDOUT, le garde N secondes puis le rend.
 *
 * Usage : php hold_lock.php <vendor/autoload.php> <chemin du verrou> <secondes>
 */

require $argv[1];

$lock = new App\Queue\SingleWorker\WorkerLock($argv[2]);
$result = $lock->tryAcquire();

fwrite(STDOUT, strtoupper($result).PHP_EOL);
fflush(STDOUT);

if ($result !== App\Queue\SingleWorker\WorkerLock::ACQUIRED) {
    exit(3);
}

sleep((int) $argv[3]);

$lock->release();

exit(0);
