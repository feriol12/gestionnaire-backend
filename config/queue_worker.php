<?php

/*
|--------------------------------------------------------------------------
| LWS-BE-06C — worker de file unique (ops:queue-*)
|--------------------------------------------------------------------------
|
| Chemins ABSOLUS, hors de l'application (base_path) et des racines web,
| stables d'un déploiement à l'autre. AUCUNE valeur par défaut : sans eux
| (ou s'ils sont invalides), les commandes ops:queue-* refusent de démarrer
| (code 2) et aucun worker ne tourne.
|
| Frontières (V2) : tous les chemins opérationnels doivent être DANS la
| racine privée QUEUE_WORKER_OPS_ROOT et HORS de l'application et de chaque
| racine publique de QUEUE_WORKER_PUBLIC_ROOTS (liste séparée par des
| virgules, non vide). Le verrou et la garde ne partagent jamais le dossier du
| journal ni celui de la capture Cron (la rotation ne peut pas les toucher).
|
| Production LWS (voir deploy/lws/QUEUE-WORKER-RUNBOOK.md) :
|   QUEUE_WORKER_OPS_ROOT         /home/fluxmoneva-ops
|   QUEUE_WORKER_PUBLIC_ROOTS     /home/htdocs
|   QUEUE_WORKER_LOCK_PATH        /home/fluxmoneva-ops/queue/worker.lock
|   QUEUE_WORKER_PAUSE_PATH       /home/fluxmoneva-ops/queue/paused
|   QUEUE_WORKER_LOG_PATH         /home/fluxmoneva-ops/logs/queue-worker.log
|   QUEUE_WORKER_CRON_OUTPUT_PATH /home/fluxmoneva-ops/logs/cron-output.log
|
| Le chemin du journal structuré est celui du canal « queue_ops »
| (config/logging.php, même variable QUEUE_WORKER_LOG_PATH).
|
| Les paramètres du worker sont PROVISOIRES : leurs valeurs finales sont
| fixées par la répétition LWS-BE-06D. Aucun défaut ici non plus.
| Invariants vérifiés avant tout démarrage :
|   timeout < queue.connections.database.retry_after (valeur courante) ;
|   max_time + timeout <= runtime_budget.
|
*/

return [

    'ops_root' => env('QUEUE_WORKER_OPS_ROOT'),

    'public_roots' => env('QUEUE_WORKER_PUBLIC_ROOTS'),

    'lock_path' => env('QUEUE_WORKER_LOCK_PATH'),

    'pause_path' => env('QUEUE_WORKER_PAUSE_PATH'),

    // Facultatif : fichier de capture de la sortie Cron (échecs antérieurs au
    // démarrage de Laravel, lignes de repli du journal). Validé avec les
    // autres chemins ; seulement lu par ops:queue-status (taille).
    'cron_output_path' => env('QUEUE_WORKER_CRON_OUTPUT_PATH'),

    'max_time' => env('QUEUE_WORKER_MAX_TIME'),

    'timeout' => env('QUEUE_WORKER_TIMEOUT'),

    'sleep' => env('QUEUE_WORKER_SLEEP'),

    'memory' => env('QUEUE_WORKER_MEMORY'),

    'runtime_budget' => env('QUEUE_WORKER_RUNTIME_BUDGET'),

];
