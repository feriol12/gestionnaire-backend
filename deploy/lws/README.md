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
ni `.git`. `index.php` charge l'application depuis `/home/fluxmoneva-api`
(chemin déduit de son propre emplacement : `dirname(__DIR__, 2)`).

## Application privée

- Garder le dossier `public/` du dépôt dans `/home/fluxmoneva-api/` : dompdf
  utilise `base_path('public')` et lève une exception s'il n'existe pas.
- `public/index.php` reste le point d'entrée local (développement, tests) ;
  il n'est pas servi par LWS.
