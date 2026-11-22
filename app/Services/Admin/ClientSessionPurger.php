<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * BE-03 (OpenCode F-06) — purge PHYSIQUE complémentaire des sessions client
 * d'un compte lors d'une transition.
 *
 * La garantie reste LOGIQUE : la barrière client.active refuse toute session
 * dont la version liée diffère de users.auth_version, quel que soit le driver
 * ou l'état de cette purge. Ici, seulement de l'hygiène :
 *  - driver client ≠ database : rien à purger (aucune requête) ;
 *  - connexion client = connexion par défaut : purge dans la transaction de
 *    transition (atomique avec elle) ;
 *  - autre connexion (SESSION_CONNECTION) : impossible d'être atomique ; purge
 *    sur CETTE connexion après commit, best-effort, échec journalisé expurgé
 *    sans annuler la transition.
 */
final class ClientSessionPurger
{
    public function purge(int $userId): void
    {
        $config = (array) config('admin.client_session');
        if (($config['driver'] ?? null) !== 'database') {
            return;
        }

        $table = (string) ($config['table'] ?? 'sessions');
        $default = (string) config('database.default');
        $connection = (string) (($config['connection'] ?? null) ?: $default);

        if ($connection === $default) {
            DB::connection($connection)->table($table)->where('user_id', $userId)->delete();

            return;
        }

        DB::afterCommit(function () use ($connection, $table, $userId) {
            try {
                DB::connection($connection)->table($table)->where('user_id', $userId)->delete();
            } catch (Throwable $e) {
                report(new RuntimeException(sprintf(
                    'BE-03 client session purge failed for user #%d on connection %s (%s); logical revocation unaffected.',
                    $userId, $connection, $e::class,
                )));
            }
        });
    }
}
