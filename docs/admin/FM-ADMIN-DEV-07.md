# FM-ADMIN-DEV-07 — Lot 1, fondations uniquement

## Périmètre et état de livraison

Référence backend : `8701eecaa9a0325f75b1a0336b040d0999ac0c67`.
Travail effectué uniquement dans une copie Git indépendante, branche
`feature/fm-admin-dev-07-foundations`. Aucun commit, merge ou déploiement.

Ce lot ne propose ni login administratif, ni endpoint métier, ni MFA.
Aucun provider ne charge de routes administratives. Le groupe
`admin.foundation` est disponible pour la suite, mais son dernier middleware
`AdminMfaNotDelivered` refuse inconditionnellement toute poursuite.
Une variable d'environnement ou une valeur `mfa_complete` de session ne peut pas
lever ce refus. Le futur lot MFA devra remplacer ce verrou après revue.

## Identité et schéma

`AdminIdentity` implémente seulement le contrat d'authentification nécessaire au
guard de session. Il n'hérite pas du contrat de reset client, ne possède ni PAT,
ni relations financières. Mass assignment interdit ; sérialisation à liste blanche.
Provider `admins`, guard `admin`. Le provider et broker `users` restent clients.
La configuration Sanctum n'est pas modifiée.

La migration crée `admin_identities` et `admin_sessions`, sans provisionnement.
La clé primaire + CHECK `id = 1` limite la table à une ligne, y compris lors
d'insertions concurrentes. Ce n'est pas une simple vérification Eloquent.
SQLite est testé. MySQL >= 8.0.16 et MariaDB >= 10.2.1 sont exigés avant toute
création pour garantir un moteur appliquant CHECK ; PostgreSQL est prévu.
Les branches MySQL/MariaDB/PostgreSQL restent à qualifier hors production.
La contrainte fait partie du CREATE TABLE : aucune fenêtre entre création et
ajout du CHECK. Les sessions administratives ont une FK vers cette seule identité.
La migration ne doit pas être exécutée en production dans ce lot.

`auth_version` est une fondation de schéma, pas une révocation globale déjà livrée.
Les futurs parcours MFA/récupération devront l'utiliser et révoquer les sessions.

## Contexte HTTP

Sélection par groupe de routes déclaré côté serveur, jamais par choix d'un header.
La priorité est enregistrée par `AdminFoundationServiceProvider` avant
EncryptCookies et StartSession. Une résolution préalable d'auth/session provoque
un échec fermé, sans purge ou reconstruction du SessionManager.

Cookie administratif : `fluxmoneva_admin_session`, host-only, Secure, HttpOnly,
SameSite Strict, chemin `/admin`, fermeture navigateur, durée serveur 15 minutes.
Stockage database `admin_sessions`, connexion configurable séparément.
`ADMIN_ORIGIN` reste absent par défaut. Il doit être une origine HTTPS exacte,
sans chemin, query, fragment, credentials ou wildcard. L'hôte de la requête et
l'Origin lorsqu'il existe sont vérifiés contre cette configuration serveur.
Cela n'active aucune route et ne crée aucun sous-domaine.

CSRF admin : jeton de la session administrative dans `X-CSRF-TOKEN`, pas de cookie
XSRF partagé ; aucun raccourci Sec-Fetch-Site. Bearer refusé avant ouverture de
session admin. Les réponses du groupe sont no-store.

Le cycle supporté est une application Laravel neuve par requête HTTP.
Octane et les conteneurs applicatifs persistants ne sont pas qualifiés.
Sur une même origine, du JavaScript client peut utiliser une session admin :
Path et HttpOnly ne constituent pas une frontière XSS. Une origine distincte
reste à qualifier avant activation, avec une politique CORS ciblée si nécessaire.

## Adaptation cliente strictement nécessaire

`RequireClientIdentity` est placé après `auth:sanctum`, avant l'activité et le
métier. Il vérifie le type du principal, celui de Sanctum et le guard ambiant
utilisé par les scopes existants `auth()->id()`. Il est aussi appliqué aux routes
client de compte et logout. Aucun contrôleur/modèle financier n'est modifié.
L'exception de throttle du logout est conservée.

## Tests isolés

Les tests de fondations sont dans `tests/AdminFoundation` et ne sont enregistrés
par aucun composant de production. Leurs routes de fixture servent uniquement
à injecter des sessions pour tester l'infrastructure ; elles ne simulent pas un
MFA et ne prouvent pas un MFA réel. Une session avec une fausse marque MFA reste
refusée par le groupe protégé.

Le banc charge des configurations temporaires dans `storage/app/admin-dev-07`.
Il impose SQLite local, retire les connexions par défaut ajoutées par Laravel
avant le boot des providers et vérifie le chemin effectif avant toute migration.
PHP est lancé avec `-n` et seulement pdo_sqlite pour la base : aucun driver réseau
MySQL/PostgreSQL. Aucun `.env` réel n'est lu. Mail array, jobs database locaux,
aucun worker et aucun email envoyé.

Les migrations compatibles nécessaires aux parcours testés sont exécutées telles
qu'elles sont versionnées. La migration financière de catégories SQL spécifique
MySQL/PostgreSQL n'est pas exécutée sous SQLite. Le garde-fou historique
`tests/Support/TestDatabaseSafety` et `phpunit.xml` ne sont pas affaiblis.
La suite Feature historique intégrale reste donc non exécutée ; elle requiert
une base autorisée mysql/pgsql et des migrations compatibles.

### Protocole cache reproductible

Depuis cette copie :

```powershell
& ./tests/AdminFoundation/replay.ps1
```

Le script utilise le PHP 8.3.30 local, prépare les configurations, efface les caches
de test, exécute le mode uncached, compile config puis routes, exécute le mode
cached, et efface les deux caches dans finally. Tout code de sortie non nul
interrompt le parcours. Chaque requête vérifie l'état réel des deux caches.

Les commandes Laravel natives recréent une application pour compiler. Le banc
leur fournit un bootstrap de test explicite : la seconde application charge donc
les mêmes configurations et fixtures. Aucune modification du framework ni des
commandes de cache. Aucun cache n'est écrit dans les dépôts originaux.

Preuves : `storage/app/admin-dev-07/evidence/uncached.json`, `cached.json`,
`replay.txt`, `baseline-unit.txt`, `final-unit.txt`.
Le test d'absence de routes admin de production est effectué sans provider de
fixture et sans cache de routes. Les routes présentes dans le cache du banc sont
explicitement des fixtures de test et ne doivent jamais être déployées.

## Suite requise avant activation

- Choix et qualification de la bibliothèque MFA compatible avec le lock.
- Provisionnement singleton, enrôlement, anti-rejeu, limitation des tentatives.
- Secrets MFA chiffrés, codes de récupération hachés à usage unique.
- Récupération exceptionnelle contrôlée, sans second administrateur opérationnel.
- Échéance absolue, réauthentification et révocation/version des sessions.
- Revue des routes publiques de préauthentification et du remplacement du verrou.
- Qualification du moteur SQL cible, des origines, du proxy et du cache LWS.

Aucun de ces éléments n'est implicitement validé par le lot 1.
