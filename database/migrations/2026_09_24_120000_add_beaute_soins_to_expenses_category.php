<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Ajoute la catégorie « Beauté & soins » (6e valeur) à expenses.category.
 *
 * La migration d'origine (2026_04_20_125418) crée la colonne avec $table->enum() :
 *  - PostgreSQL (production) : varchar(255) + contrainte CHECK
 *  - MySQL (développement)   : ENUM(...) NOT NULL
 *
 * Aucune ligne existante n'est modifiée, la table n'est pas recréée.
 * Le down() refuse le rollback si des dépenses utilisent déjà la nouvelle catégorie.
 */
return new class extends Migration
{
    private const PREVIOUS_CATEGORIES = ['Nourriture', 'Transport', 'Factures', 'Loisirs', 'Imprévu'];

    private const NEW_CATEGORY = 'Beauté & soins';

    public function up(): void
    {
        $this->applyCategories([...self::PREVIOUS_CATEGORIES, self::NEW_CATEGORY]);
    }

    public function down(): void
    {
        $count = DB::table('expenses')->where('category', self::NEW_CATEGORY)->count();

        if ($count > 0) {
            throw new RuntimeException(
                "Rollback refusé : {$count} dépense(s) utilisent déjà la catégorie « " . self::NEW_CATEGORY . ' ». '
                . 'Aucune donnée n\'a été modifiée.'
            );
        }

        $this->applyCategories(self::PREVIOUS_CATEGORIES);
    }

    private function applyCategories(array $categories): void
    {
        $driver = DB::getDriverName();
        $values = implode(', ', array_map(fn (string $value) => "'" . str_replace("'", "''", $value) . "'", $categories));

        match ($driver) {
            'pgsql' => $this->applyPostgres($values),
            'mysql', 'mariadb' => DB::statement("ALTER TABLE `expenses` MODIFY `category` ENUM({$values}) NOT NULL"),
            default => throw new RuntimeException(
                "Driver « {$driver} » non pris en charge pour la contrainte expenses.category (pgsql ou mysql attendu)."
            ),
        };
    }

    private function applyPostgres(string $values): void
    {
        // Contrainte CHECK portant uniquement sur expenses.category, dans le schéma courant.
        $constraints = DB::select(
            "SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS definition
             FROM pg_constraint con
             JOIN pg_class rel ON rel.oid = con.conrelid
             JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
             JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
             WHERE con.contype = 'c'
               AND nsp.nspname = current_schema()
               AND rel.relname = 'expenses'
               AND att.attname = 'category'
               AND array_length(con.conkey, 1) = 1"
        );

        if (count($constraints) !== 1 || ! str_contains($constraints[0]->definition, 'Nourriture')) {
            throw new RuntimeException(
                'Contrainte CHECK de expenses.category introuvable ou ambiguë (' . count($constraints) . ' trouvée(s)) : migration interrompue.'
            );
        }

        $name = '"' . str_replace('"', '""', $constraints[0]->name) . '"';

        // Exécuté dans la transaction de la migration (DDL transactionnel sous PostgreSQL).
        DB::statement("ALTER TABLE \"expenses\" DROP CONSTRAINT {$name}");
        DB::statement("ALTER TABLE \"expenses\" ADD CONSTRAINT {$name} CHECK (\"category\" IN ({$values}))");
    }
};
