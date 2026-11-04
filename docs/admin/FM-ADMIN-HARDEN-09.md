# FM-ADMIN-HARDEN-09 — stabilisation du lot 1

Ce document complète et remplace les choix de chemin HTTP du document DEV-07,
conservé comme historique. Aucune route métier ou authentification admin réelle
n'est livrée. Aucun commit, merge ou déploiement.

## Inventaire et préservation

Au départ : 21 fichiers de livraison (4 modifiés et 17 non suivis), exactement
ceux annoncés dans DEV-07. Leur contenu initial est archivé dans
`storage/app/admin-harden-09/before/`. Les preuves DEV-07 restent dans
`storage/app/admin-dev-07/evidence/`, avec contrôle de leurs empreintes.
Le nouveau banc utilise un runtime séparé `storage/app/admin-harden-09`.

## S-01 — contrat unique

Tous les futurs endpoints HTTP admin, y compris le CSRF initial, le login,
l'enrôlement/validation MFA, le reset administratif éventuel et le logout, doivent
être sous `/api/admin/*`. Ils ne doivent pas être imbriqués dans le groupe `api`
Sanctum client : le préfixe d'URL ne détermine pas le groupe middleware.

Cookie : `fluxmoneva_admin_session`, Path `/api/admin`, host-only, Secure,
HttpOnly, SameSite Strict. Pas de préfixe `__Host-`, incompatible avec ce Path.
Path `/` n'est pas nécessaire pour les parcours retenus.
Une future interface sur une autre origine utilisera des appels vers ces URL,
avec une qualification CORS/CSRF distincte. Aucun sous-domaine n'est activé ici.

Le test Chromium montre l'envoi sur `/api/admin`, `/api/admin/` et leurs
sous-chemins ; l'absence d'envoi sur `/api/administer`, `/api/user` et l'ancien
`/admin/fixture/who`. Il couvre CSRF, injection de session de test, rotation,
endpoint MFA bloqué, logout et coexistence du cookie client.
Le transport est une interception CDP vers PHP local : ce n'est pas un test TLS,
Apache ou LWS. Le Path d'un cookie n'est pas une protection contre une XSS de même origine.

## S-02 — aucune correction de RequireClientIdentity nécessaire

Les observations du banc précèdent l'appel à la vraie classe, sans modifier sa
décision. `auth:sanctum` sélectionne le guard ambiant `sanctum` pour le parcours
protégé ; request->user(), le guard Sanctum et Auth::user() donnent User#1 pour
la session SPA comme pour le PAT. Les injections AdminIdentity et le mélange
User Sanctum / guard ambiant admin sont refusés. La classe livrée en DEV-07 reste
identique octet pour octet.

Les traces JSON contiennent `principalAtClientGate`, `guardAfter`,
`principalAfter` et `injections`. Une expiration d'inactivité peut laisser un
principal en mémoire dans la requête tout en retournant 401 ; cela ne signifie
pas qu'une réponse financière est autorisée. Les accès sans authentification
sont refusés avant RequireClientIdentity.

## S-03 — confinement renforcé

Les fixtures sont uniquement dans tests/AdminFoundation et n'ont aucun provider
enregistré dans config/app.php. La capacité PHP `FixtureCapability`, ajoutée
explicitement par le bootstrap du banc, et l'environnement de test exact sont
désormais exigés par le provider ET le constructeur du contrôleur de fixture.
Une simple variable de configuration ne permet pas cette activation. Cette
vérification protège aussi contre l'utilisation accidentelle d'un contrôleur de
fixture issu d'un cache de routes de test dans une application sans cette capacité.

`AdminMfaNotDelivered` reste inchangé et inconditionnel ; la fausse marque de
session utilisée par les tests ne le contourne pas. Aucun MFA simulé n'est
ajouté au code applicatif.

## S-04 — moteurs et erreurs

Migration applicative inchangée. Qualification réelle : SQLite 3.40.0 et MySQL
8.4.3. Tests : création, seconde identité id=1 et id=2 refusée, UPDATE id=2 refusé,
FK, cascade, index user_id/last_activity, down(), transaction DDL et erreur du
second CREATE. MySQL conserve alors la première table avec son CHECK intact ;
SQLite la retire lorsque la migration s'exécute dans une transaction.

Ne pas supposer un rollback DDL automatique sous MySQL/MariaDB. En cas d'échec
réel, constater la présence des deux tables et l'absence d'entrée réussie dans
le journal des migrations avant toute décision de reprise. Ne pas supprimer des
tables contenant des données pour forcer une relance. Le lot reste non déployé.

MariaDB et PostgreSQL : NON VÉRIFIÉS à l'exécution (pas de binaire local identifié).
Revue statique : sélection de BIGINT UNSIGNED pour MySQL/MariaDB, BIGINT pour
PostgreSQL ; CHECK inclus dans CREATE ; FK et index via Schema. Versions minimales
MySQL/MariaDB vérifiées dans le code. Aucun succès SQLite ne certifie ces moteurs.

### Qualification future exacte, hors production

Utiliser uniquement des binaires déjà disponibles ou une installation autorisée.
Le banc `sql.php` accepte `sqlite`, `mysql`, `mariadb` ou `pgsql` et vérifie le
port ET le data_directory effectifs avant de modifier une table.

- MySQL : port 33379, répertoire `storage/app/admin-harden-09/mysql-data`.
- MariaDB : port 33380, NOUVEAU répertoire `storage/app/admin-harden-09/mariadb-data`.
- PostgreSQL : port 33381, NOUVEAU répertoire `storage/app/admin-harden-09/pgsql-data`.

Ne jamais réutiliser le datadir MySQL pour MariaDB. Arrêter si le port est occupé.
Pour MariaDB Windows, initialiser avec le mysql_install_db.exe fourni par la
version choisie, `--datadir=<chemin mariadb-data>` (sans installation de service),
puis démarrer mariadbd.exe avec `--no-defaults --datadir=<chemin> --port=33380
--bind-address=127.0.0.1`, en processus caché. Ne pas lire les defaults Laragon.
Créer `fm_admin_harden09_test` sur cette instance, utilisateur root local du
datadir neuf, puis exécuter le banc avec pdo_mysql :

```text
php -n -d extension_dir=<ext PHP> -d extension=mbstring -d extension=openssl -d extension=fileinfo -d extension=pdo_sqlite -d extension=pdo_mysql tests/AdminFoundation/sql.php mariadb
```

Pour PostgreSQL : `initdb -D <pgsql-data> -U postgres -A trust`, puis
`pg_ctl -D <pgsql-data> -l <journal dans la copie> -o "-h 127.0.0.1 -p 33381" start`.
Créer la base avec `createdb -h 127.0.0.1 -p 33381 -U postgres --no-password
fm_admin_harden09_test`, puis :

```text
php -n -d extension_dir=<ext PHP> -d extension=mbstring -d extension=openssl -d extension=fileinfo -d extension=pdo_sqlite -d extension=pdo_pgsql tests/AdminFoundation/sql.php pgsql
```

Ces configurations sans mot de passe sont réservées aux instances locales
jetables bindées sur loopback, jamais à un serveur existant. Archiver les JSON,
les versions et les résultats ; arrêter ensuite le processus créé pour l'essai.
Résultat attendu : 13 contrôles MySQL/MariaDB, 12 SQLite/PostgreSQL, aucun échec.

## Exécution et résultats

`tests/AdminFoundation/replay.ps1` : prépare le runtime isolé, clear routes/config,
exécute sans caches, compile config puis routes, exécute avec caches, clear dans
finally. Les commandes Laravel natives utilisent le bootstrap de test pour leur
seconde application. Aucun cache du dépôt original n'est touché.

Résultat final : 241 assertions sans caches, 240 avec caches, 55 requêtes par mode.
Navigateur : 19 contrôles. Tests unitaires historiques : 48 réussis, un ignoré
(liens symboliques Windows), 361 assertions.

La suite Feature complète a été tentée sur le MySQL jetable : 292 tests,
51 échecs, 7 erreurs, un ignoré. Une attente SESSION_CONNECTION=null était
contredite par l'override initial du banc ; cet override a été retiré. Les autres
échecs/erreurs concernent les quatre classes d'exploitation QueueDrainResume,
QueueStatusCommand, QueueWorkerCommand et QueueWorkerProcessIntegration : leurs
chemins temporaires sont confinés dans la copie, ce que leur garde-fou interdit
pour les fichiers d'exploitation. Aucun garde-fou ni code worker n'a été modifié.

Après correction du seul override du banc, les 196 tests Feature hors de ces
quatre classes passent (1 643 assertions). Cela ne rend pas la suite complète PASS.

Commande utilisée (PHP 8.3.30, -n, extensions mbstring/openssl/fileinfo/pdo_mysql,
sys_temp_dir sous storage/app/admin-harden-09/tmp) :

```text
vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --bootstrap tests/AdminFoundation/historical-bootstrap.php --cache-directory storage/app/admin-harden-09/phpunit-cache --filter '/^(?!.*(?:QueueDrainResumeTest|QueueStatusCommandTest|QueueWorkerCommandTest|QueueWorkerProcessIntegrationTest)).*$/' --testsuite Feature
```

Le bootstrap historique vérifie l'identité du serveur MySQL (port/datadir) avant
la suite, conserve TestDatabaseSafety inchangé et utilise la base neuve
`gestionnaire_test` de cette seule instance. L'instance a été arrêtée après essais.
Preuves séparées dans `storage/app/admin-harden-09/evidence/`.

## Décision

LOT_1_STABILISÉ_SOUS_RÉSERVES : S-01/S-02/S-03 qualifiés ; S-04 qualifié sur
SQLite/MySQL, restant à démontrer sur le moteur exact de déploiement s'il diffère.
Les tests d'exploitation complets demandent un environnement dont les chemins
autorisés soient compatibles, sans contourner leur garde-fou. Les qualifications
LWS, origine navigateur distincte, MFA réel et révocation restent hors de ce lot.
