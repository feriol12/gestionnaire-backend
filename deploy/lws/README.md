# Déploiement LWS — racine web de l'API (LWS-BE-03)

Deux dossiers physiques distincts, sans lien symbolique (LWS renvoie 403) :

| Rôle | Chemin LWS | Contenu |
|---|---|---|
| Application Laravel privée | `/home/fluxmoneva-api/` | le dépôt complet (`app/`, `bootstrap/`, `config/`, `vendor/`, `storage/`, `.env`, `public/`…) |
| Racine web de l'API | `/home/htdocs/api.fluxmoneva.com/` | **uniquement** le contenu de `deploy/lws/api-webroot/` |

## Racine web : fichiers à copier

| Source dans le dépôt | Destination |
|---|---|
| `deploy/lws/api-webroot/index.php` | `/home/htdocs/api.fluxmoneva.com/index.php` |
| `deploy/lws/api-webroot/.htaccess` | `/home/htdocs/api.fluxmoneva.com/.htaccess` |

Rien d'autre : ni `.env`, ni `vendor/`, ni `storage/`, ni `config/`, ni
`routes/`, ni `database/`, ni `app/`, ni fichiers Composer, ni `artisan`,
ni `.git`. `index.php` charge l'application depuis `/home/fluxmoneva-api`,
écrit en chemin absolu fixe (`$laravelRoot = '/home/fluxmoneva-api';`).

### Pourquoi un chemin absolu (LWS-BE-08-F1)

Sur LWS, `/home/htdocs` est exposé au PHP HTTP sous son nom canonique
`/htdocs` : le `__DIR__` de `index.php` vaut `/htdocs/api.fluxmoneva.com`,
pas `/home/htdocs/api.fluxmoneva.com`. L'ancienne déduction
`dirname(__DIR__, 2).'/fluxmoneva-api'` donnait donc `//fluxmoneva-api`
(inexistant) et chaque requête finissait en 500. `/home` reste lisible depuis
le PHP HTTP : la racine privée est ancrée sur le chemin LWS approuvé, sans
dépendre de l'emplacement du fichier, de la requête, de l'environnement, de
`DOCUMENT_ROOT` ni du répertoire courant. Si l'application privée change un
jour d'emplacement, ce littéral unique est la seule ligne à modifier.

## Application privée

- Garder le dossier `public/` du dépôt dans `/home/fluxmoneva-api/` : dompdf
  utilise `base_path('public')` et lève une exception s'il n'existe pas.
- `public/index.php` reste le point d'entrée local (développement, tests) ;
  il n'est pas servi par LWS.

## Worker de file (LWS-BE-06C)

Procédures du worker unique (chemins privés `/home/fluxmoneva-ops`, verrou,
pause/drain/reprise, journal, contraintes de déploiement) :
[QUEUE-WORKER-RUNBOOK.md](QUEUE-WORKER-RUNBOOK.md). L'activation de la file
en production reste bloquée jusqu'à LWS-BE-06D et autorisation explicite.
