# Worker de file unique — runbook LWS (LWS-BE-06C)

> **Activation de la file en production : BLOQUÉE.** Ce document décrit le
> mécanisme livré par LWS-BE-06C. Les valeurs finales (paramètres du worker,
> fréquence Cron, attente du drain, `retry_after`, `MAIL_TIMEOUT`) et
> l'installation de la tâche Cron relèvent de la répétition **LWS-BE-06D**,
> puis d'une autorisation explicite d'activation.

## 1. Principe

- **Un seul worker actif**, garanti par un **verrou noyau** (`flock(2)` via
  PHP, acquisition non bloquante) sur un fichier stable et privé.
- `ops:queue-worker` ouvre le verrou, puis **sous le verrou** lit la garde de
  pause et le mode maintenance, puis exécute `queue:work` **dans le même
  processus PHP**. Le verrou n'est rendu qu'après le retour de `queue:work`,
  ou par le noyau à la mort du processus : le worker ne peut pas survivre au
  verrou.
- Il n'y a **ni `flock(1)`, ni `ps`, ni `/proc`, ni fichier PID** sur LWS :
  rien de tout cela n'est utilisé. L'existence du fichier de verrou ne veut
  rien dire ; seul le verrou noyau compte.
- **État de pause indéterminé = file en pause** : seule une absence
  positivement établie de la garde autorise le worker. Si le dossier de la
  garde ne peut pas être listé, ou si les métadonnées de la garde sont
  inaccessibles, le worker s'abstient (74). Le drain ne prouve rien (74). La
  reprise ne se déclare jamais réussie (74).
- Le panneau LWS n'affiche ni sortie ni code de retour : le **journal
  structuré** fait foi.

## 2. Arborescence privée (hors application, hors racines publiques)

```
/home/fluxmoneva-ops/                      0700   racine privée (QUEUE_WORKER_OPS_ROOT)
/home/fluxmoneva-ops/queue/                0700   CONTRÔLE : verrou et garde seulement
/home/fluxmoneva-ops/queue/worker.lock     verrou (créé par PHP s'il manque ; JAMAIS supprimé ni tronqué)
/home/fluxmoneva-ops/queue/paused          garde de pause (présente = file en pause)
/home/fluxmoneva-ops/logs/                 0700   OBSERVABILITÉ : journaux seulement
/home/fluxmoneva-ops/logs/queue-worker-AAAA-MM-JJ.log   événements JSON (0600, 14 jours)
/home/fluxmoneva-ops/logs/cron-output.log  capture Cron (normalement vide)
```

**Séparation imposée par le code :**
- Le verrou et la garde ne partagent **jamais** le dossier du journal ni celui
  de la capture Cron.
- La rotation quotidienne n'écrit et ne supprime que des fichiers
  `queue-worker-AAAA-MM-JJ.log` du dossier `logs/` : elle ne peut ni écrire
  dans le verrou ou la garde, ni les supprimer.
- La capture Cron ne peut pas porter un nom de fichier quotidien du journal.

**Rien** de ce mécanisme ne va dans une racine publique
(`/home/htdocs/api.fluxmoneva.com`, `/home/htdocs/fluxmoneva.com`, ni
ailleurs sous `/home/htdocs`). Le code refuse tout chemin opérationnel situé
dans une racine publique déclarée (alias symboliques résolus), hors de la
racine privée, ou dans l'application.

Les commandes **ne créent pas** les dossiers : un dossier manquant donne
`CONFIG_INVALID` (code 2), jamais un second domaine d'exclusion. Une
configuration de chemins invalide n'écrit **rien** sur disque (pas de
journal, pas de verrou, pas de garde) ; seule une ligne de repli part sur
STDERR.

## 3. Configuration (`/home/fluxmoneva-api/.env`)

```
QUEUE_WORKER_OPS_ROOT=/home/fluxmoneva-ops
QUEUE_WORKER_PUBLIC_ROOTS=/home/htdocs
QUEUE_WORKER_LOCK_PATH=/home/fluxmoneva-ops/queue/worker.lock
QUEUE_WORKER_PAUSE_PATH=/home/fluxmoneva-ops/queue/paused
QUEUE_WORKER_LOG_PATH=/home/fluxmoneva-ops/logs/queue-worker.log
QUEUE_WORKER_CRON_OUTPUT_PATH=/home/fluxmoneva-ops/logs/cron-output.log
# Valeurs fixées par LWS-BE-06D (aucune valeur par défaut) :
QUEUE_WORKER_MAX_TIME=
QUEUE_WORKER_TIMEOUT=
QUEUE_WORKER_SLEEP=
QUEUE_WORKER_MEMORY=
QUEUE_WORKER_RUNTIME_BUDGET=
```

`QUEUE_WORKER_PUBLIC_ROOTS` est une liste séparée par des virgules, non vide.
Sur LWS, `/home/htdocs` est un lien vers `/htdocs` : les deux formes sont
couvertes, car la forme canonique est résolue automatiquement.

Validation au démarrage, sinon code 2, aucune écriture et aucun worker :
- **racine privée** : absolue, existante, canonique, dédiée (ni dans ni
  contenant l'application ou une racine publique) ;
- **racines publiques** : au moins une, chemins absolus ;
- **chemins opérationnels** :
  - absolus, sans segment relatif, nom de fichier simple `[A-Za-z0-9._-]` ;
  - dans la racine privée, hors de l'application et de toute racine publique ;
  - dossier parent existant et canonique (aucun lien symbolique) ;
  - fichier non lien symbolique ;
  - tous distincts ;
  - verrou et garde hors des dossiers du journal et de la capture Cron ;
  - capture Cron sans nom de fichier quotidien du journal ;
- **paramètres** : entiers de 1 à 86 400 (garde de représentabilité, pas un
  réglage) ;
- `TIMEOUT < retry_after` (valeur courante de la connexion `database`) ;
- `MAX_TIME + TIMEOUT <= RUNTIME_BUDGET`. C'est un budget de configuration,
  pas une borne d'horloge stricte : démarrage, pauses, base de données et
  journal s'y ajoutent.

Après toute modification de `.env` :
1. `/usr/bin/php83 /home/fluxmoneva-api/artisan config:cache`
2. `/usr/bin/php83 /home/fluxmoneva-api/artisan ops:queue-status`

`ops:queue-status` rapporte séparément `config` (les chemins) et
`worker_config` (chemins et paramètres).

## 4. Commandes

Toujours avec l'interpréteur et des chemins absolus : les scripts ne sont pas
exécutables directement sous `/home`, le `PATH` Cron est réduit, et `php`
désigne la version 8.5.

| Commande | Rôle | Codes |
|---|---|---|
| `/usr/bin/php83 /home/fluxmoneva-api/artisan ops:queue-worker --context=cron` | worker (Cron) | statut du worker (0, 12 mémoire, 1 erreur) ; 75 ignoré (verrou occupé, pause, maintenance) ; 74 erreur verrou/garde (y compris état de pause indéterminé) ; 2 configuration |
| `/usr/bin/php83 /home/fluxmoneva-api/artisan ops:queue-worker --context=manual` | une exécution manuelle bornée | idem |
| `/usr/bin/php83 /home/fluxmoneva-api/artisan ops:queue-drain --wait=<n>` | pause + preuve de quiescence (au plus `n` sondes espacées d'1 s) | 0 au repos ; 75 `DRAIN_TIMEOUT` ; 74 ; 2 |
| `/usr/bin/php83 /home/fluxmoneva-api/artisan ops:queue-resume` | reprise explicite | 0 (y compris garde absente, absence établie) ; 74 retrait impossible ou état indéterminé ; 2 |
| `/usr/bin/php83 /home/fluxmoneva-api/artisan ops:queue-status [--json] [--no-lock-probe]` | diagnostic | 0 ; 74 ; 2 |

**Commande Cron future** (NON installée ; installation en 06D) :

```
/usr/bin/php83 /home/fluxmoneva-api/artisan ops:queue-worker --context=cron >> /home/fluxmoneva-ops/logs/cron-output.log 2>&1
```

La redirection capture tout ce que la commande écrit sur STDOUT/STDERR,
notamment :
- les échecs **antérieurs au démarrage de Laravel** (autoload, fichier
  corrompu, bootstrap) ;
- les lignes de repli `queue-ops-log-failure event=<E> run_id=<id>` émises
  quand le journal structuré ne peut pas être écrit (voir §8).

Les exécutions normales n'y écrivent rien.

## 5. Opérations manuelles

- Le Web Terminal LWS **peut fermer la session en cours de commande** : aucune
  procédure ne repose sur une session longue.
- `ops:queue-worker --context=manual` est une exécution **bornée** par
  `MAX_TIME` (plus une tâche, plus les délais de démarrage et d'infrastructure).
  - Ce n'est pas un moyen fiable de vider un arriéré : c'est le rôle du Cron.
  - Si la session se ferme pendant l'exécution, le processus peut mourir : le
    noyau libère le verrou, et la tâche réservée revient après `retry_after`.
- Le vidage long manuel d'un arriéré **n'est pas pris en charge** sur cet
  hébergement.

## 6. Déploiement : mise au repos et reprise

Ordre obligatoire :

0. *(Recommandé pour un déploiement risqué : vendor, schéma, chemins.)*
   Désactiver la tâche Cron du worker dans le panneau LWS. C'est une mesure
   **complémentaire** de contrôle des lancements, **jamais** le mécanisme
   d'exclusion, et elle ne prouve pas qu'un worker en cours est terminé.
1. `ops:queue-drain --wait=<n>`
   - **0** : quiescence prouvée, on peut déployer.
   - **75** (`DRAIN_TIMEOUT`) : un worker tient encore le verrou. La garde
     reste posée ; **STOP : relancer le drain jusqu'à 0. Ne JAMAIS poursuivre
     le déploiement après 75.**
   - **74 / 2** : **STOP**, corriger avant toute suite.
2. Déployer (fichiers, `composer install`, `artisan migrate --force`, caches).
   Les requêtes HTTP peuvent continuer à mettre des tâches en file : elles
   attendent dans `jobs`.
3. Vérifier : `ops:queue-status`, contrôles de fumée.
4. `ops:queue-resume`, puis réactiver la tâche Cron si l'étape 0 a été faite.
   La reprise est la **dernière** étape, unique et sérialisée : pas de reprise
   pendant un drain ou un déploiement en cours.

Un déploiement échoué ou incomplet **reste en pause** : rien ne reprend
automatiquement (ni exception, ni migration échouée, ni drain expiré).

**Ce que la garde garantit, exactement :**
- Elle n'empêche **pas** Laravel de démarrer.
- Elle empêche l'**entrée dans `queue:work`** après un démarrage réussi et la
  lecture de la garde sous le verrou.
- Un démarrage qui échoue (fichiers à moitié remplacés) ne produit aucun
  worker. L'erreur va dans `cron-output.log`.

**Attente du drain :** un worker actif dure environ `MAX_TIME` plus une tâche
(`TIMEOUT`). Ce n'est pas une borne stricte : démarrage, pauses, base de
données et journal s'y ajoutent. Un premier drain plus court peut donc
expirer (75) : c'est attendu et sûr. Relancer, ou dimensionner
`--wait >= MAX_TIME + TIMEOUT + marge` (valeur fixée en 06D). `--wait` compte
des sondes espacées d'une seconde, pas une échéance d'horloge stricte.

## 7. Invariants permanents (revue indépendante, note N-1)

**Texte approuvé, reproduit mot pour mot** depuis
`reports/LWS-BE-06C-DESIGN-REVIEW-CLINE.md`, §6 :

> - **N-1 — Deployment runbook constraints must be made explicit** (derived from §4.7 of this review):
>   - (a) never deploy a code version lacking the under-lock gate check while the Cron task exists — promote the §16 first-activation ordering (drain-before-Cron-exists) to a standing invariant;
>   - (b) never change `QUEUE_WORKER_LOCK_PATH` / `QUEUE_WORKER_PAUSE_PATH` / `QUEUE_WORKER_LOG_PATH` values without full quiescence (flag set + Panel Cron disabled) — a mid-flight path change would split the lock domain;
>   - (c) never proceed with a deploy after `DRAIN_TIMEOUT` (75) — re-run drain until 0;
>   - (d) elevate Panel Cron disable from "optional" to **recommended for risky deploys** (vendor/schema/path-heavy) and **mandatory as the manual kill-switch when `ops:queue-drain` itself cannot boot** (broken-code recovery: the app may fail bootstrap for drain while the Panel toggle still removes launches outright).

**Traduction (informative ; le texte anglais ci-dessus fait foi) :**

- **(a)** Ne jamais déployer une version du code **dépourvue de la garde
  vérifiée sous le verrou** tant que la tâche Cron existe. La première
  activation pose la garde (`ops:queue-drain`) **avant** que la tâche Cron
  n'existe.
- **(b)** Ne jamais modifier `QUEUE_WORKER_LOCK_PATH`,
  `QUEUE_WORKER_PAUSE_PATH` ou `QUEUE_WORKER_LOG_PATH` sans quiescence
  complète (garde posée **et** tâche Cron désactivée). Un changement en vol
  scinderait le domaine d'exclusion.
- **(c)** Ne jamais poursuivre un déploiement après `DRAIN_TIMEOUT` (75) :
  relancer le drain jusqu'à 0.
- **(d)** Désactiver la tâche Cron dans le panneau est **recommandé** pour les
  déploiements risqués (vendor, schéma, chemins). Cela devient **obligatoire,
  comme coupe-circuit manuel**, quand `ops:queue-drain` lui-même ne peut pas
  démarrer (code cassé) : désactiver la tâche Cron **avant** tout travail de
  réparation.

**Compléments de LWS-BE-06C V2** (ajoutés, ils ne font pas partie du texte
approuvé) :
- La règle (b) s'applique aussi à `QUEUE_WORKER_OPS_ROOT`,
  `QUEUE_WORKER_PUBLIC_ROOTS` et `QUEUE_WORKER_CRON_OUTPUT_PATH`.
- Ne jamais supprimer ni tronquer `worker.lock` : le supprimer pendant qu'il
  est tenu créerait un second inode, donc un second domaine.
- Ne jamais déplacer le verrou ou la garde dans le dossier des journaux.

## 8. Journal et détection d'arrêt non propre

Une ligne JSON par événement (`context.event`) :

| Groupe | Événements |
|---|---|
| Invocation | `INVOCATION_START{lock_path}`, `CONFIG_INVALID`, `INVOCATION_END{exit_code, elapsed_ms, lock_released}` |
| Verrou | `LOCK_ACQUIRED`, `LOCK_BUSY`, `LOCK_ERROR{stage}` |
| Garde / maintenance | `SKIPPED{reason: paused\|maintenance}` |
| Worker | `WORKER_START`, `WORKER_END{worker_status, stop_reason, jobs_processed}`, `WORKER_FAILURE`, `WORKER_KILLED` |
| Tâches | `JOB_PROCESSED`, `JOB_RELEASED`, `JOB_FAILED{exception_class}`, `JOB_TIMED_OUT` |
| Drain / reprise | `PAUSE_SET`, `PAUSE_ALREADY_SET`, `DRAIN_WAITING`, `DRAIN_QUIESCED`, `DRAIN_TIMEOUT`, `PAUSE_CLEARED`, `PAUSE_NOT_SET` |

**Périmètre de la garantie de confidentialité :** elle couvre le seul canal
`queue_ops` (ce journal).
- Les champs y sont limités à une liste blanche, appliquée par le code.
- Le nom de tâche n'est journalisé que s'il a la forme d'un nom de classe ;
  sinon il est remplacé par `unrecognized`.
- `paused_since` n'est repris que s'il a la forme d'un horodatage.
- Ce canal ne contient ni charge utile, ni message d'exception, ni email, ni
  jeton, ni mot de passe, ni environnement.

**Hors périmètre** (non assainis par ce mécanisme) :
- le journal applicatif de Laravel (`storage/logs`, où `report()` consigne
  les exceptions avec leur message) ;
- la table `failed_jobs` ;
- `cron-output.log` ;
- les journaux du framework ou de tiers.

**Journal dégradé :** si le journal structuré ne peut pas être écrit
(dossier, droits, disque), chaque événement produit une ligne
`queue-ops-log-failure event=<E> run_id=<id>` sur STDERR, donc dans
`cron-output.log` sous Cron.
- L'exclusion et le traitement continuent normalement.
- `ops:queue-status` signale les fichiers manquants ou illisibles : un
  compteur à 0 n'est alors pas une preuve de bonne santé.
- Une écriture du journal peut attendre son propre verrou de fichier.

**Mort du processus** (SIGKILL externe, erreur fatale, mémoire du système,
ou délai dépassé, où Laravel s'envoie SIGKILL à lui-même) :
- aucune ligne finale ne peut être écrite ;
- le noyau libère le verrou ;
- pour un délai dépassé, `JOB_TIMED_OUT` puis `WORKER_KILLED` sont écrits
  juste avant, **si le journal est disponible** (ordre à confirmer sur LWS en
  06D).

La trace est donc un `INVOCATION_START` **sans** `INVOCATION_END`.
`ops:queue-status` liste ces exécutions (aujourd'hui et hier).
- C'est une détection **passive**, et non une preuve : arrêt non propre
  **ou** exécution encore en cours.
- À recouper avec la sonde du verrou, `WORKER_KILLED` et `cron-output.log`.

**Contrôle de routine (revue N-3) :** lancer `ops:queue-status` après chaque
déploiement, après tout incident, et régulièrement. Vérifier :
- `paused` (une garde oubliée bloque la remise des emails) ;
- `unmatched_worker_runs` (et son état `files`) ;
- `jobs_pending` / `oldest_pending_available_at` ;
- `failed_jobs` ;
- `cron_output_bytes`.

## 9. Fichier de capture Cron (revue N-2)

`cron-output.log` n'a **ni rotation ni plafond** : un échec persistant avant
Laravel, ou un journal structuré durablement indisponible, y ajoute des
lignes à chaque minute.
- `ops:queue-status` signale toute taille non nulle, et une **ALERTE**
  au-delà de 1 Mio.
- Procédure : lire le contenu, corriger la cause, puis le vider
  (`: > /home/fluxmoneva-ops/logs/cron-output.log`).
- Ce fichier-là peut être tronqué ; **jamais** `worker.lock`.

## 10. Reste à faire en LWS-BE-06D

- Créer l'arborescence privée et vérifier que la validation canonique des
  chemins passe dans l'environnement LWS (`realpath` dans la vue restreinte,
  alias `/home/htdocs` vers `/htdocs`).
- Vérifier le refus de l'état de pause indéterminé avec de vrais droits Linux.
- Fixer les paramètres : `MAX_TIME`, `TIMEOUT`, `SLEEP`, `MEMORY`,
  `RUNTIME_BUDGET`, la fréquence, `--wait`.
- Valider `retry_after` (90 actuellement, provisoire) et `MAIL_TIMEOUT` (20).
- Installer et répéter la commande Cron exacte.
- Chevauchement réel, et `durée du verrou >= durée du worker` sous Cron.
- Mort du détenteur : délai dépassé, `kill` externe si possible.
- SIGTERM gracieux, libération normale.
- Drain, déploiement simulé et reprise.
- Création, droits et rotation des journaux.
- Limites CLI sous Cron.
- Nettoyage complet.
