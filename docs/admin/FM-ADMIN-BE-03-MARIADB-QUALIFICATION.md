# BE-03 — Qualification MariaDB 10.11 (Docker, environnement local jetable)

**Date :** 22/11/2026
**Commit qualifié :** le code produit est celui de `09c304c`. `eb8bc3a` ne modifie que le banc de test (support conteneur et code de sortie).
**Base :** `1a3141c6fb6ff5f50f45fd6abf8af54a9a663c13`
**Expurgation :** les identifiants de test étaient aléatoires et propres à cette exécution. Ils ont été détruits avec le conteneur et ne figurent pas ici.

## 1. Environnement

| Élément | Valeur |
|---|---|
| Moteur Docker | Docker Desktop, moteur 29.7.2 (linux) |
| Image | `docker.io/library/mariadb:10.11`, épinglée par digest `sha256:e5fe75b2b9de0eb9f6f9a4d9c97272fb3a7480e389ccd487a786bab2a12cc0d7` (image du 20/11/2026) |
| Serveur | `10.11.19-MariaDB-ubu2204` |
| Isolation | Conteneur et réseau bridge dédiés, marqués `fm.mission=be03-qual`, avec un nom d'hôte unique vérifié par le banc. Port publié sur `127.0.0.1` uniquement. Données en `tmpfs` (aucun volume). Le `.env` réel n'est pas utilisé. |
| Paramètres | InnoDB, `tx_isolation=REPEATABLE-READ`, `autocommit=1`, `innodb_lock_wait_timeout=50`, `innodb_deadlock_detect=1`, `innodb_snapshot_isolation=0`, `check_constraint_checks=1`, `explicit_defaults_for_timestamp=1`, `sql_mode=STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` |

## 2. Schéma relevé sur MariaDB

- `users.administrative_status varchar(16) NOT NULL DEFAULT 'active'`, avec la contrainte `users_administrative_status_check` : `administrative_status in ('active','suspended')`.
- `users.auth_version bigint(20) unsigned NOT NULL DEFAULT 1`.
- `users.suspended_at timestamp NULL` et `users.suspended_by bigint(20) unsigned NULL`.
- `admin_audit_events` et `notification_outbox` sont en InnoDB, avec un index unique sur `event_id` et aucune clé étrangère. Sur MariaDB, `payload` devient `longtext` avec une contrainte CHECK `json_valid`.
- Les clés étrangères existantes sont inchangées (`expenses`/`budgets` → `users` ; tables admin → `admin_identities`).
- **T17**, migration avec des clients existants :
  - les comptes existants passent en `active` / v1 ;
  - les finances sont identiques avant et après ;
  - la contrainte CHECK rejette un statut inconnu ;
  - `down()` supprime le schéma BE-03 sans toucher aux finances ;
  - la réexécution est propre.

## 3. Résultats

| Suite | MySQL 8.4.3 (local, isolé) | MariaDB 10.11.19 (Docker) |
|---|---|---|
| PHPUnit complet (`php artisan test`) | 364 réussis, 0 échec, 2 ignorés, 2 avertissements (2 738 assertions) | 364 réussis, 0 échec, 2 ignorés, 2 avertissements (2 740 assertions) |
| Banc BE-03 (`tests/AdminSuspension/run.php run`) | 137/137, 3 exécutions | **137/137 à chacune des 3 exécutions**, chaque fois sur une base recréée et un runtime vierge |

Les 2 tests ignorés et les 2 avertissements (`WorkerLockTest`, `QueueWorkerCommandTest`) sont identiques sur `1a3141c`.

Le banc couvre :
- T01–T17 ;
- les courses entre processus PHP réels : écriture gagnante, suspension gagnante, login concurrent, doubles transitions simultanées, réactivation contre suspension ;
- l'injection de pannes d'audit et d'outbox (rollback total, aucun SQL ni motif divulgué) ;
- la confidentialité : motif absent des réponses et des journaux, aucune donnée financière côté admin ;
- les routes mises en cache.

## 4. Non-régression BE-01 / BE-02 / BE-06

Les bancs d'origine tournent en SQLite, conformément à leur conception. Leur mode MySQL est câblé sur une instance locale fixe et n'est pas compatible avec le conteneur sans les modifier. La base et BE-03 ont été comparées dans deux copies fraîches, chacune avec son propre `vendor`, et des commandes identiques.

| Banc | `1a3141c` | BE-03 |
|---|---|---|
| BE-01 `tests/AdminFoundation` | 241 / 240 contrôles, 0 échec (non mis en cache / mis en cache) | 241 / 240, 0 échec |
| BE-02 `tests/AdminMfa` | 74 réussis, 1 échec (non mis en cache et mis en cache) | 74 réussis, 1 échec (identique) |
| BE-06 `tests/AdminUsersHttp` | 80/80 | 80/80 |

**Échec BE-02.** Le contrôle `only auth endpoints no business routes` attend exactement les 9 routes MFA. Il échoue déjà sur `1a3141c`, parce que BE-06 a ajouté `users` et `users/{id}`. BE-03 y ajoute ses 2 routes. Dans les deux cas, ce banc échoue aussi ensuite à écrire son fichier de preuves (dossier `evidence` non créé par `prepare.php`).

**Correction d'un rapport antérieur.** Une première comparaison partageait `vendor` par jonction. L'autoload Composer chargeait alors `App\` depuis le worktree BE-03, si bien que la « base » exécutait en réalité le code BE-03. Les échecs de login client attribués à `1a3141c` dans ce rapport étaient donc des artefacts de mesure. Les chiffres ci-dessus remplacent ces résultats.

## 5. Nettoyage

- Supprimés uniquement : le conteneur `fm-be03-mariadb-qual-*` et le réseau `fm-be03-net-*`, tous deux marqués `fm.mission=be03-qual`.
- Aucun volume créé : 5 volumes avant comme après.
- Le conteneur préexistant `fluxmoneva-mariadb-test` et son image `sha256:d769e379…` sont conservés. Le `pull` autorisé a déplacé l'étiquette `mariadb:10.11` vers la nouvelle image, mais aucune image n'a été supprimée.
- Aucun `docker system prune` ni `docker volume prune`.
- Docker Desktop a été remis à l'arrêt, son état initial.

## 6. Réserves

- **Mutations financières en vol :** sérialisées par le verrou `users`, démontré sur MySQL 8.4 et MariaDB 10.11. La configuration réelle LWS (moteur, driver de session) reste à qualifier avant activation (OPS-01/02).
- **T13 :** l'envoi SMTP et ses relances relèvent de BE-05 ; seule l'intention durable est prouvée.
- **Banc BE-02 :** l'échec de surface de routes préexistant n'est pas corrigé ici (hors périmètre BE-03).
