<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * BE-04 (D1) — immuabilité en base de admin_audit_events (append-only).
 *
 * Additive : ne recrée ni ne modifie la table BE-03, n'ajoute aucune colonne
 * (D3). Deux triggers refusent toute instruction UPDATE ou DELETE ordinaire,
 * pour TOUS les comptes SQL, y compris celui de l'application ; l'INSERT et le
 * rollback transactionnel (qui n'exécute pas de DELETE) restent inchangés.
 *
 * Limites assumées (documentées dans FM-ADMIN-BE-04-IMPLEMENTATION.md) :
 * DROP TABLE, TRUNCATE, ALTER, RENAME et la suppression des triggers eux-mêmes
 * ne déclenchent pas ces triggers ; seuls les privilèges SQL les bornent.
 *
 * Moteurs : MySQL 8.4 / MariaDB 10.11 (qualifiés), SQLite (bancs locaux).
 * PostgreSQL : implémenté, NON qualifié (aucune instance de test autorisée).
 *
 * down() : supprime uniquement les triggers — aucune ligne d'audit n'est
 * touchée (D4, Q8).
 */
return new class extends Migration
{
    private const TABLE = 'admin_audit_events';

    private const MESSAGE = 'admin_audit_events is append-only';

    private const TRIGGERS = ['admin_audit_events_no_update' => 'UPDATE', 'admin_audit_events_no_delete' => 'DELETE'];

    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (self::TRIGGERS as $name => $event) {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE
                    ." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'");
            }
        } elseif ($driver === 'sqlite') {
            foreach (self::TRIGGERS as $name => $event) {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE
                    ." BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END");
            }
        } elseif ($driver === 'pgsql') {
            DB::unprepared('CREATE FUNCTION admin_audit_events_append_only() RETURNS trigger LANGUAGE plpgsql AS $$ '
                ."BEGIN RAISE EXCEPTION '".self::MESSAGE."'; END $$");
            foreach (self::TRIGGERS as $name => $event) {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE
                    .' FOR EACH ROW EXECUTE FUNCTION admin_audit_events_append_only()');
            }
        } else {
            throw new RuntimeException("Unsupported database driver for the append-only audit log: {$driver}.");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared($driver === 'pgsql'
                ? "DROP TRIGGER IF EXISTS {$name} ON ".self::TABLE
                : "DROP TRIGGER IF EXISTS {$name}");
        }
        if ($driver === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS admin_audit_events_append_only()');
        }
    }
};
