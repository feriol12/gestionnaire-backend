# FM-ADMIN-MFA-12 — acquisition et implémentation locale

Date : 2026-10-28. Auteur : Codex. Ce rapport succède au constat de blocage REPRISE-CODEX, conservé comme historique.

**MFA_12_DÉBLOQUÉ_DÉVELOPPEMENT_REPRIS**

**LOT_2_MFA_IMPLÉMENTÉ_PRÊT_POUR_REVIEW**

Activation opérationnelle et déploiement : interdits. Aucun commit effectué. La récupération exceptionnelle demeure **BLOQUÉE POUR ACTIVATION**, faute de procédure approuvée par le propriétaire.

## 1. Reprise et périmètre

Copie conservée : `C:\PROJETS\GestionDepense\development\FM-ADMIN-MFA-12`.
Branche : `feature/fm-admin-mfa-12`. HEAD initial et final : `dfb37e164512dba6e46b8cda77d337f2f838cc55`.
À l'entrée de cette mission : aucun changement suivi ; deux documents non suivis issus de la reprise Codex (`FM-ADMIN-MFA-12-GIT-INITIAL.json`, `FM-ADMIN-MFA-12-REPRISE-CODEX.md`). Aucun développement MFA préexistant retrouvé.

La préparation initiale de la copie et de la branche était déjà faite au retour d'Antigravity ; aucune trace ne permet d'attribuer davantage de travail MFA à cet agent. L'acquisition, le code MFA, les nouveaux tests et ce rapport sont réalisés par Codex. Les fondations sont celles du commit approuvé, pas une réimplémentation.

La sauvegarde complète précédente reste intacte dans `backups/FM-ADMIN-MFA-12-resume-20261028-131153` : 10 082 fichiers vérifiés lors de la reprise. Une copie des deux fichiers Composer avant acquisition est conservée dans `storage/app/admin-mfa-12/before`. Tous les changements de cette mission se trouvent dans MFA-12.

## 2. Qualification de la dépendance

Runtime exécuté : PHP CLI 8.3.30, sans php.ini (`-n`). Laravel installé/verrouillé : 13.33.0. Le projet déclare PHP ^8.1 mais le framework et le contrôle Composer requièrent effectivement PHP >=8.3 ; aucun abaissement de ce prérequis.

| Paquet installé | Version | Référence source/dist | Licence |
|---|---|---|---|
| pragmarx/google2fa | v9.1.0 | f00bc788c555adfb6765c437ff3538e59cd88af1 | MIT |
| paragonie/constant_time_encoding | v3.1.3 | d5b01a39b3415c2cd581d3bd3a3575c1ebbd8e77 | MIT |

Google2FA est autonome : pas de bridge Laravel, fournisseur de services tiers, bibliothèque QR ou dépendances de développement du paquet ajoutés. Ses contraintes PHP acceptent 8.3 ; constant_time_encoding 3.1.3 exige PHP ^8. La génération aléatoire utilise `random_int` dans la bibliothèque. Les algorithmes OTP et l'encodage Base32 ne sont pas réimplémentés dans l'application.

Provenance et métadonnées consultées : [distribution officielle Google2FA](https://packagist.org/packages/pragmarx/google2fa), [composer.json du tag v9.1.0](https://raw.githubusercontent.com/antonioribeiro/google2fa/v9.1.0/composer.json), [dépendance ParagonIE](https://packagist.org/packages/paragonie/constant_time_encoding), [avis du dépôt Google2FA](https://github.com/antonioribeiro/google2fa/security/advisories). Licences également lues dans les archives installées. Aucun avis signalé pour les deux paquets dans les sources consultées ; cela ne prouve pas l'absence de vulnérabilité inconnue.

Composer 2.10.2 lancé par chemin PHP/phar explicite, avec COMPOSER_HOME et cache propres à cette copie ; aucune configuration utilisateur ou authentification Composer reprise. Commande effective :

```text
php -n <extensions locales> composer.phar require pragmarx/google2fa:9.1.0 --no-scripts --no-plugins --no-interaction
php -n <extensions locales> composer.phar audit --locked --format=json --no-plugins --no-scripts --no-interaction
```

Une simulation a précédé l'acquisition. Le premier essai réseau sandbox a échoué (curl 7), sans changement persistant ; l'accès réseau autorisé a permis simulation et installation. Aucun contournement TLS, avis de sécurité ignoré, option ignore-platform, mise à jour générale ou script d'installation exécuté. Les lignes PowerShell `NativeCommandError` dans les journaux entourent du stderr normal de Composer : les sorties réussies ont bien un code processus 0.

**Audit Composer : code 0 ; advisories=[], abandoned=[], filter=[].** Composer émet uniquement les avertissements PSR-4 déjà liés aux quatre classes de fixtures du lot 1 dans un même fichier ; elles sont chargées explicitement par le banc. Aucun renommage de ces fixtures.

Le contrôle `tests/AdminMfa/dependencies.php` compare chaque entrée du lock : **121 paquets existants strictement inchangés**, deux ajouts, aucune suppression. Les références source/dist correspondent aux métadonnées officielles. Les archives ne disposent pas ici d'une preuve indépendante de signature d'éditeur : ne pas présenter la vérification des références et le transport TLS comme une telle signature.

Diff exact : `storage/app/admin-mfa-12/evidence/dependencies.diff`.
`composer.json` ajoute uniquement `require pragmarx/google2fa:9.1.0`. `composer.lock` ajoute les deux métadonnées de paquets et change le content-hash (134 lignes ajoutées, une remplacée). SHA-256 du lock final : `f313389aca3bf7e8864fa291862b39e7973e48ea8be7f016197d268f94c34e91`.

## 3. Architecture réellement implémentée

- `AdminIdentity`, provider `admins`, guard `admin`, absence de PAT et singleton id=1 conservés.
- `routes/admin.php` contient seulement neuf endpoints d'authentification sous `/api/admin/*`, hors middleware API/Sanctum client.
- Activation désactivée par défaut (`admin.mfa_enabled=false`) et origine HTTPS non configurée. Même avec un cache de routes activées, `AdminMfaEnabled` refuse à l'exécution si l'activation est retirée. L'option ne dispense jamais de la vérification MFA.
- Cookie et contexte du lot 1 conservés : `fluxmoneva_admin_session`, table `admin_sessions`, Path `/api/admin`, Secure, HttpOnly, host-only, SameSite Strict ; CSRF `X-CSRF-TOKEN`, absence de cookie XSRF administratif et refus Bearer.
- Le verrou `AdminMfaNotDelivered` et le groupe `admin.foundation` sont inchangés. Les nouveaux endpoints protégés utilisent `RequireAdminMfa`, qui exige un principal `AdminIdentity` et une preuve aléatoire correspondante en base, liée à l'identifiant de session et à auth_version. Un booléen de session ne suffit pas.
- Les transactions administratives se sérialisent sur l'identité singleton. MySQL : verrou de ligne ; SQLite : réservation d'écriture avant lecture et contrôle conditionnel. Aucune lecture financière cliente n'est verrouillée ou modifiée.

La migration additionnelle `2026_10_28_000001_create_admin_mfa.php` ajoute à l'identité l'état MFA, le secret chiffré et le dernier compteur accepté ; elle crée challenges, preuves de session, condensats de récupération, compteurs de tentative et événements de sécurité. La migration approuvée du lot 1 n'est pas modifiée. Appliquer seulement dans un environnement autorisé après revue ; aucun schéma de production n'a été touché. Le comportement DDL MySQL n'est pas transactionnel : une erreur de migration nécessite inspection avant reprise, pas une relance aveugle.

## 4. Parcours et garanties

### Provisionnement

`admin:provision` est une commande interactive sans argument secret. Saisie masquée sans fallback visible ; refus si non interactive ou stdin non terminal. Mot de passe de 16 à 128 caractères, hash Laravel. INSERT id=1 et contrainte SQL empêchent deux identités même en concurrence. État initial pending_enrollment, aucune session privilégiée. Tests : identités fictives uniquement ; aucun compte réel créé. La saisie masquée interactive n'a pas été utilisée pour créer un compte ; son refus non interactif a été exécuté.

### Connexion et enrôlement

GET csrf → POST login avec CSRF et mot de passe → challenge serveur limité à 300 secondes, lié à la session et à auth_version. Pas de connexion au guard admin à ce stade. Pour pending_enrollment : POST enrollment génère un secret de 32 caractères Base32 chiffré via Laravel. Il n'est retourné qu'une fois pour ce challenge ; pas de QR externalisé. POST mfa confirme le TOTP ; seul ce succès ouvre la session complète, renouvelle l'identifiant de session et le token CSRF.

TOTP applicatif : SHA-1, six chiffres, pas de 30 secondes, fenêtre explicitement ±1 pas ; acceptation atomique uniquement si compteur supérieur au dernier accepté. Les timestamps de l'API de la bibliothèque sont des compteurs, pas des secondes Unix. La bibliothèque a été vérifiée avec les 18 vecteurs SHA-1/256/512 de [RFC 6238, annexe B](https://www.rfc-editor.org/rfc/rfc6238).

Limitation persistante et atomique : 5 tentatives par identité/catégorie et 20 par IP, par fenêtre fixe de 300 secondes. Les étapes MFA partagent le budget de l'identité singleton. Les compteurs sont validés hors transaction de vérification pour ne pas disparaître lors d'un rejet. L'IP repose sur le mécanisme de proxy déjà existant ; aucun nouvel en-tête proxy n'est promu comme source fiable.

### Récupération et renouvellement

Dix codes aléatoires de 256 bits sont retournés une seule fois après enrôlement ; stockage SHA-256 de secrets à forte entropie, pas de mots de passe faibles. Mot de passe valide puis code valide : consommation atomique, invalidation de tous les anciens codes/facteurs/preuves, incrément auth_version et suppression des sessions de cette identité. Résultat : challenge de réenrôlement seulement. Aucune session complète avant le nouveau TOTP.

Pendant recovery_required, une nouvelle connexion par mot de passe seul est refusée : elle ne peut pas voler le réenrôlement d'une autre session. La perte ou l'expiration du challenge de récupération rend donc nécessaire la procédure exceptionnelle du propriétaire ; aucune porte de secours n'est ajoutée. Ce compromis de fermeture sûre doit être accepté avant activation.

`reauthenticate` exige mot de passe + nouveau TOTP non rejoué. `factor/change` exige une preuve complète et une authentification de moins de cinq minutes ; révoque tout et passe au réenrôlement. Même identité conservée. Aucun reset par email, broker client ou second admin temporaire.

### Sessions et concurrence

Preuve enregistrée en base avec auth_version, empreinte de session, début, dernière activité et dernière authentification. Inactivité maximale 900 s, durée absolue 28 800 s ; aucune utilisation de User.last_activity pour l'admin. Vérification à chaque requête protégée ; logout invalide la session et sa preuve, sans déconnecter le client.

Une session ancienne réécrite par une requête concurrente ne retrouve pas de privilège : preuve supprimée/version obsolète. Une requête déjà admise peut terminer ; aucune promesse d'annulation rétroactive. Les futures mutations métier sensibles devront revérifier version et autorisation dans leur transaction. Aucune mutation métier ajoutée ici.

Événements SQL à contenu fixe : provisionnement, mot de passe accepté/refusé, throttling, MFA réussi, récupération, réauthentification, renouvellement et logout. Aucun mot de passe, TOTP, secret, code de récupération ou token de session dans ces événements. Les champs OTP/récupération sont aussi exclus du flash des erreurs de validation.

## 5. Matrice d'autorisation

| État | Autorisé | Refusé |
|---|---|---|
| Anonyme | csrf, soumission login, logout CSRF | Session complète, facteurs sans challenge, métier |
| Mot de passe validé | Étapes correspondant au challenge et à son échéance | Session complète, API financières |
| Récupération validée | Enrôlement dans la session autorisée | Connexion complète sans nouveau TOTP, reprise par mot de passe seul |
| MFA complet valide | session, logout, reauthenticate ; factor/change si récent | Toute API métier admin (absente), toute API financière cliente |
| Session révoquée/expirée/preuve forgée | Nouvelle authentification selon état | Accès privilégié |
| Identité ou Bearer client | Parcours clients existants | Identité admin |

## 6. Fichiers et différence avec le lot 1

Fichiers suivis modifiés (six) : `composer.json`, `composer.lock`, `config/admin.php`, `app/Providers/AdminFoundationServiceProvider.php`, `app/Exceptions/Handler.php`, `tests/AdminFoundation/replay.ps1`.
Le dernier changement autorise uniquement le chemin de copie MFA-12 en plus de DEV-07 ; aucune assertion du lot 1 affaiblie.

Huit nouveaux fichiers applicatifs : `app/Services/Admin/AdminMfa.php`, `AdminTotp.php`, `app/Http/Controllers/Admin/AdminAuthController.php`, `app/Http/Middleware/AdminMfaEnabled.php`, `RequireAdminMfa.php`, `app/Console/Commands/ProvisionAdmin.php`, `routes/admin.php`, et la migration MFA.
Nouveau banc dans `tests/AdminMfa/` : bootstrap et préparation isolés, entrée console/cache, vecteurs standards, comparaison des dépendances, tests HTTP, replay et deux processus concurrents, bootstrap des tests historiques. Documents de reprise préservés.

Inchangés : modèle AdminIdentity, migration singleton, SelectAdminContext, AdminCsrf, RequireAdminIdentity, RequireClientIdentity, AdminMfaNotDelivered, Kernel, routes métier et contrôleurs clients. Aucune UI administrative ni fonctionnalité financière ajoutée.

## 7. Résultats vérifiés

Toutes les données sont fictives. Bootstraps MFA sans .env, mail array, files/caches locaux, connexions épinglées. Aucun service de production contacté.

| Vérification | Résultat | Preuve dans storage/app/admin-mfa-12/evidence |
|---|---|---|
| RFC 6238 | 18/18 | totp-vectors.json |
| Dépendances | 121 entrées existantes identiques ; deux ajouts | dependency-verification.json, dependencies.diff |
| Audit Composer | 0 avis, 0 abandon ; exit 0 | composer-audit.json |
| HTTP SQLite sans caches | 75/75 | mfa-sqlite-uncached.json |
| HTTP SQLite config + routes cachées | 75/75 | mfa-sqlite-cached.json, mfa-replay-final.txt |
| HTTP MySQL 8.4.3 | 75/75 | mfa-mysql-uncached.json, mfa-mysql.txt |
| Courses SQLite | 5 assertions ; un seul gagnant dans chacune des trois courses | concurrency-sqlite.json |
| Courses MySQL | 5 assertions ; mêmes résultats | concurrency-mysql.json |
| AdminFoundation sans/avec caches | 241/240 assertions, 55 requêtes par mode, zéro échec | foundation-replay.txt |
| Unit historiques | 48 PASS, 1 SKIP, 361 assertions | historical-unit.txt |
| Feature historiques exécutés | 196 PASS, 1 643 assertions | historical-feature.txt |
| Provisionnement non interactif | refus attendu, exit 1 | provision-no-interaction.txt |
| Syntaxe PHP et git diff --check | PASS | contrôle local des fichiers ajoutés/modifiés |

Les 75 contrôles couvrent mauvais mot de passe, identité inconnue, CSRF absent/incorrect, challenge expiré, MFA absent/incorrect/expiré/rejoué, compteurs de tentative, succès, rotation, preuve copiée dans une autre session, logout, récupération puis réenrôlement, ancienne version et ancien code, réauthentification récente, inactivité/durée absolue, principal/Bearer croisés, collision id=1, alternance client/admin, absence de routes métier et verrou lot 1.

Les courses utilisent deux vrais processus PHP indépendants prêts à une barrière commune : provisionnement = [200,409] ; TOTP = [200,401] ; récupération = [200,401]. Vérification de l'unicité et des anciennes preuves révoquées. Pas une simple répétition séquentielle.

MySQL : instance créée dans `storage/app/admin-mfa-12/mysql-data`, bind 127.0.0.1:33412, aucun fichier de configuration existant chargé. Bootstraps vérifient port et datadir avant écriture. Bases neuves `admin_mfa12_test` et `gestionnaire_test`. Instance arrêtée ; aucun processus de test serveur laissé en service.

Limites des tests historiques : unitaire de lien symbolique ignoré faute de privilèges Windows. Quatre classes d'exploitation (QueueDrainResumeTest, QueueStatusCommandTest, QueueWorkerCommandTest, QueueWorkerProcessIntegrationTest), 96 cas, non exécutées ici : incompatibilité connue entre confinement des chemins temporaires dans la copie et garde-fou des chemins d'exploitation. **BLOCKED dans ce banc**, aucune assertion désactivée dans ces classes et aucun succès de suite Feature intégrale annoncé. Aucun test navigateur réel de nouvelle interface (aucune UI livrée), Apache ou LWS. MariaDB/PostgreSQL non qualifiés ici.

Rejeu SQLite : `powershell -NoProfile -ExecutionPolicy Bypass -File tests/AdminMfa/replay.ps1`. Le banc de concurrence refuse d'écraser un répertoire de course existant : préserver les preuves et préparer un nouveau runtime de test avant répétition. Les essais MySQL nécessitent l'instance jetable documentée et MFA_TEST_MYSQL=1, jamais un serveur existant. Le replay caches fourni cible SQLite ; ne pas extrapoler à une configuration MySQL cachée non testée.

## 8. Risques et conditions avant activation

- Revue indépendante obligatoire avant commit et activation. Le flag seul ne constitue pas cette autorisation.
- Choisir/qualifier l'origine réelle HTTPS, le proxy, CSRF/cookies et le moteur/version SQL sur environnement autorisé ; aucun sous-domaine présumé disponible. Warm containers/Octane restent exclus par le contrat du lot 1.
- La connexion admin/session doit être la connexion par défaut de cette application (ou null) ; une connexion distincte est refusée plutôt que de fractionner les transactions.
- Approuver une procédure propriétaire hors application pour perte totale et perte du challenge de récupération. Aucun mécanisme exceptionnel permanent livré.
- Préparer la conservation sûre des codes et la gestion de la clé Laravel qui chiffre les facteurs. Aucun secret réel manipulé pendant cette mission.
- Planifier la rétention des événements et le nettoyage borné des challenges/preuves/counters expirés. Aucun worker ni tâche de purge de production supposé existant ; pas de suppression automatique d'audit ajoutée.
- La session strictement liée à une origine ne protège pas d'une XSS sur cette même origine. Les renforcements CSP/HTTPS d'autres missions ne sont ni intégrés ni réouverts.

## 9. Intégrité des dépôts protégés

25 autres dépôts/copies Git inventoriés : HEAD, branches et états porcelain identiques entre début et fin. Les SHA-256 des 38 fichiers préexistants modifiés/non suivis des copies protégées restent identiques aux relevés conservés. Cela ne prétend pas couvrir le contenu ignoré ou les secrets, qui n'ont pas été lus.
Le frontend original conserve son `.htaccess` modifié préexistant, SHA-256 `AD1D4AFA470DD227B516160FA0EB07B7476DA820BAFCD6877BEC736EA0353827`.
INTEGRATION-11 reste propre au commit approuvé. Aucun checkout, commit, merge, push, transfert ou déploiement ; aucune modification distante GitHub/LWS/production. Seuls les téléchargements publics de qualification/acquisition ont été effectués.

## 10. Décision

L'acquisition est qualifiée et le MFA local est implémenté et testé, au-delà de la seule installation. Prêt pour revue indépendante ; pas pour activation opérationnelle automatique. Les réserves d'exploitation et la récupération exceptionnelle doivent rester explicites.

MFA_12_DÉBLOQUÉ_DÉVELOPPEMENT_REPRIS

LOT_2_MFA_IMPLÉMENTÉ_PRÊT_POUR_REVIEW

NEXT_SYNC=owner
