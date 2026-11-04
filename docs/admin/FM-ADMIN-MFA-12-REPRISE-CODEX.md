# FM-ADMIN-MFA-12 — reprise après interruption
Date : 2026-10-28. Auteur de cette inspection et conception : Codex.

## Verdict
LOT_2_MFA_BLOQUÉ

Prérequis bloquant : aucune bibliothèque TOTP identifiable dans composer.json, composer.lock, vendor/composer/installed.json ni dans les sources PHP installées examinées. Aucun paquet installé, aucune implémentation cryptographique maison, aucun facteur simulé ajouté. La mission exige explicitement de signaler ce blocage. Il faut une autorisation distincte d'acquisition d'une dépendance, ou un artefact local approuvé, puis une qualification de sa version exacte avant développement.

## 1. État repris et provenance
Copie existante conservée : C:\PROJETS\GestionDepense\development\FM-ADMIN-MFA-12.
Branche feature/fm-admin-mfa-12 ; HEAD dfb37e164512dba6e46b8cda77d337f2f838cc55.
Source INTEGRATION-11 : même HEAD, branche feature/fm-admin-foundations-integration-11, working tree propre.
À l'arrivée : aucun fichier modifié/non suivi ; seul vendor/ ignoré par Git. Aucun rapport, test ou code MFA propre au lot 2 retrouvé dans la copie. storage/app ne contient que public ; logs et bootstrap/cache ne contiennent que leur .gitignore.
.git est un répertoire propre à la copie, sans objects/info/alternates ni lien de réanalyse retrouvé. Aucun reclonage ni changement de branche effectué.

Travail préexistant constaté : préparation de la copie, branche et dépendances. Son attribution personnelle à Antigravity repose sur le contexte utilisateur, pas sur une trace nominative. Les fondations et documents DEV-07/HARDEN-09 appartiennent au commit approuvé : ne pas les attribuer à une nouvelle implémentation MFA.
Terminé avant reprise : environnement Git préparé. Non commencé dans les fichiers disponibles : provisionnement, enrôlement, challenge, TOTP, récupération, sessions MFA et tests du lot 2.
Réalisé par Codex : inspection, sauvegarde complète vérifiée, conception ci-dessous et rapport de blocage. Aucune source applicative modifiée.

## 2. Préservation
Sauvegarde hors dépôts protégés : C:\PROJETS\GestionDepense\backups\FM-ADMIN-MFA-12-resume-20261028-131153\copy.
Inclut .git, fichiers cachés, vendor et tous les fichiers présents, suivis ou non. Manifest SHA-256 dans le dossier parent.
Deux instantanés SHA-256 consécutifs identiques avant copie ; comparaison de la source après copie et de la sauvegarde avant toute écriture documentaire. Aucun changement concurrent observé sur ces fenêtres. Des processus Claude/PHP existent sur la machine ; leur seule présence ne démontre pas une écriture dans cette copie. Cette observation n'est pas un verrou exclusif permanent.
Aucun .env réel, fichier .key/.pem ou base .sqlite identifié dans la copie à sauvegarder. Aucune configuration de production ni donnée métier lue.

## 3. Fondations effectivement examinées
- app/Models/AdminIdentity.php : identité séparée, attributs protégés, password hashed, auth_version ; aucun PAT ni mécanisme de reset client.
- config/auth.php : provider admins et guard admin de type session.
- database/migrations/2026_10_27_000001_create_admin_foundations.php : singleton id=1 protégé par CHECK, FK admin_sessions.user_id ; contraintes de version SQL.
- app/Providers/AdminFoundationServiceProvider.php : priorité SelectAdminContext ; aucune route enregistrée.
- app/Http/Kernel.php : groupe admin.foundation avec sélection du contexte, cookies, session, CSRF, principal puis verrou MFA.
- SelectAdminContext.php : refus avant résolution auth/session tardive, origine HTTPS exacte, refus Bearer, table/cookie isolés, Secure/HttpOnly/host-only/Strict, Path=/api/admin.
- AdminCsrf.php : X-CSRF-TOKEN lié à la session, aucun cookie XSRF partagé.
- RequireAdminIdentity.php et AdminMfaNotDelivered.php : principal explicite puis refus inconditionnel. Verrou conservé.
- Aucune route admin dans routes/ à l'inspection.
- composer.json déclare PHP ^8.1 mais Laravel verrouillé/installé 13.33.0 exige ^8.3 ; vendor/composer/platform_check.php exige >=8.3. Runtime CLI vérifié avec -n : PHP 8.3.30.
- Laravel fournit Encrypter::encryptString/decryptString, Session Store::invalidate/regenerate/migrate/regenerateToken et RateLimiter::tooManyAttempts/hit. Leur présence ne constitue pas une bibliothèque TOTP.

## 4. Conception proposée — NON implémentée
Conserver l'identité et le singleton. Ajouter une migration distincte, sans réécrire celle du lot 1 : état pending_enrollment/active/recovery_required, secret TOTP chiffré, compteur temporel du dernier code accepté, tables de challenges et codes de récupération. Aucun secret dans les traces, arguments CLI ou réponses après enrôlement.

Provisionnement : commande locale contrôlée, mot de passe saisi de manière masquée, aucune inscription HTTP. INSERT id=1 sous contrainte singleton ; une concurrence produit un seul succès. Identité pending_enrollment, jamais de privilège par la seule création. Ne créer aucun compte réel dans ce lot.

HTTP : routes explicitement déclarées sous /api/admin/* dans un groupe administratif de session/CSRF, sans groupe api Sanctum client. Pré-authentification autorise seulement CSRF initial, mot de passe et étapes de challenge dédiées. Ne pas retirer globalement RequireAdminIdentity pour ouvrir le login. Aucun endpoint métier ajouté.
Mot de passe correct : challenge opaque aléatoire lié côté serveur à la session, identité, auth_version, étape et échéance courte ; aucune connexion privilégiée par Auth::login à cette étape. Renouveler l'identifiant de session à l'entrée du challenge et après réussite complète, invalider les anciens identifiants.

TOTP : adaptateur autour d'une bibliothèque approuvée et versionnée, génération CSPRNG et chiffrement Laravel. Fenêtre proposée : pas de 30 secondes, tolérance maximale ±1 pas, à fixer dans les tests. Acceptation et progression du compteur atomiques sous verrou de ligne afin d'interdire le rejeu entre sessions concurrentes. Pas de code de démonstration applicatif. Qualification par vecteurs publiés et tests de la bibliothèque avant usage.

Récupération : codes CSPRNG stockés sous condensats adaptés à des secrets de forte entropie, comparaison constante et consommation conditionnelle atomique. Mot de passe + code valide donnent seulement un challenge de réenrôlement ; incrémenter auth_version et révoquer les sessions, puis exiger confirmation du nouveau TOTP avant nouvelle session complète. Régénération des codes après réauthentification récente et facteur valide ; anciens codes invalidés dans la transaction.

Sessions complètes : enregistrement serveur lié au principal, auth_version, date MFA, dernière activité et début absolu ; ne pas faire confiance à une marque booléenne isolée. Limites proposées à qualifier : inactivité 15 minutes, absolue 8 heures, réauthentification récente 5 minutes. À chaque requête, vérifier identité AdminIdentity, état actif, version, échéances et preuve serveur. Une révocation versionnée interdit le retour d'une ancienne session réécrite par une requête concurrente ; une requête déjà admise peut néanmoins terminer. Les futures mutations sensibles devront revérifier la version dans leur transaction. Aucune lecture financière cliente n'est modifiée.
Déconnexion : invalidation de la session administrative et renouvellement CSRF, sans toucher la session cliente.
Limitation des tentatives : budgets par identité normalisée et IP effectivement déterminée par le proxy déjà approuvé ; stockage partagé et opérations atomiques à qualifier. Erreurs génériques, journalisation d'événements sans mot de passe, TOTP, secret, code ou identifiant de session.

Activation : conserver AdminMfaNotDelivered jusqu'à qualification indépendante du nouveau contrôle ; toute future activation opérationnelle demeure désactivée par défaut. Une option d'activation ne doit jamais dispenser du MFA réel.
Perte totale des facteurs : BLOQUÉE POUR ACTIVATION jusqu'à décision du propriétaire sur vérification d'identité et opération hors application. Même identité, révocation totale et réenrôlement ; aucun second admin, reset client/email seul ou accès de secours permanent.

## 5. Matrice cible (non livrée)
| État | Accès maximal |
|---|---|
| Anonyme | CSRF et soumission de mot de passe, limités |
| Challenge mot de passe vérifié | Enrôlement ou second facteur selon état, durée limitée |
| Récupération validée | Réenrôlement seulement |
| MFA complet, actif, version/échéances valides | Contrôle de session et logout ; aucune API métier dans ce lot |
| Révoqué, expiré, mauvais principal, Bearer | Refus |
L'état réellement livré reste celui du lot 1 : aucune route admin opérationnelle et verrou inconditionnel.

## 6. Tests et preuves
Contrôles exécutés : Git HEAD/branche/status/diff/ignored, inspection statique des composants et dépendances, version CLI PHP sans php.ini, inventaire des artefacts, comparaison SHA-256 de sauvegarde.
Aucune migration, aucun test fonctionnel MFA, aucun build ou serveur lancé ; aucun compte créé.
Tous les cas obligatoires du §10 du prompt sont BLOCKED pour le lot 2 : mots de passe, challenges expirés, MFA absent/incorrect/expiré/rejoué, throttling, succès/rotation, CSRF, logout, révocation, récupération/concurrence, singleton concurrent, croisements Bearer/principaux/IDs, alternance client/admin, caches, routes métier et non-régression.
Les suites lot 1 n'ont pas été relancées : aucun code changé et replay.ps1 refuse explicitement tout chemin autre que development\FM-ADMIN-DEV-07. Une adaptation de confinement sera nécessaire dans MFA-12, sans affaiblir les assertions. Les chiffres historiques des documents hérités ne sont pas des résultats MFA-12.
Les tests de concurrence devront employer des connexions/processus réellement distincts ; un succès SQLite séquentiel ne démontre pas le comportement MySQL.

## 7. Reprise nécessaire
1. Autoriser l'acquisition d'une bibliothèque TOTP ou fournir un artefact approuvé. Vérifier provenance, version, licence, compatibilité PHP 8.3/Laravel 13, dépendances, API de validation et maintenance. Aucune bibliothèque sélectionnée à ce stade.
2. Adapter le banc uniquement à la copie MFA-12, isoler données fictives, caches, mail et connexions locales.
3. Implémenter les composants et migrations ci-dessus derrière le verrou, puis tous les cas obligatoires.
4. Revue indépendante avant substitution opérationnelle du verrou et avant tout commit.
5. Décision propriétaire distincte pour la récupération exceptionnelle et toute activation.

Aucune production, LWS, installation réseau, modification Laravel/MySQL en exploitation, commit, merge, push ou déploiement. Seuls ce rapport et les preuves documentaires sont ajoutés dans MFA-12.

## 8. Intégrité finale
Inventaire initial conservé dans FM-ADMIN-MFA-12-GIT-INITIAL.json : 26 dépôts/copies Git détectés aux deux niveaux du workspace. Pour les 25 autres que MFA-12, branches, HEAD et états porcelain finaux identiques à l'inventaire initial. Les SHA-256 des 38 fichiers préexistants modifiés/non suivis de ces dépôts sont également inchangés entre les deux relevés. Ce contrôle ne prétend pas vérifier les données ignorées ou les secrets des dépôts protégés.
MFA-12 : même branche et même HEAD ; git diff --exit-code et git diff --check réussissent. Seuls les deux documents FM-ADMIN-MFA-12-REPRISE-CODEX.md et FM-ADMIN-MFA-12-GIT-INITIAL.json sont non suivis. Aucun fichier applicatif ni dépendance changé.
public/.htaccess du frontend original conserve SHA-256 AD1D4AFA470DD227B516160FA0EB07B7476DA820BAFCD6877BEC736EA0353827.
Sauvegarde : 10 082 fichiers vérifiés. Aucun test fonctionnel présenté comme PASS.

NEXT_SYNC=owner
