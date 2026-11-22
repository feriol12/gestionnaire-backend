# GOV-05 — Addenda du 22/11/2026 : format des erreurs JSON de l'API administrative V1

**Rattachement :** GOV-05, contrat HTTP/API Admin V1 (Trello [QSaI6yyt](https://trello.com/c/QSaI6yyt)). Suivi BE-03 [QzJYa8FO](https://trello.com/c/QzJYa8FO), BE-06 [RY1BlUQy](https://trello.com/c/RY1BlUQy).
**Décision propriétaire (22/11/2026, ratifiée) :** les erreurs JSON de l'API administrative V1 incluent `message` **et** `code`.
**Constat traité :** OpenCode `FM-ADMIN-BE-03-REVIEW-01`, F-05.

## 1. Schéma commun

```json
{ "message": "Texte générique, sans donnée de compte, de motif ni de finance", "code": "IDENTIFIANT_STABLE" }
```

- `code` est l'identifiant stable sur lequel s'appuient les clients ; un code existant n'est jamais renommé.
- `message` est un texte générique, qui ne révèle ni l'état d'un compte, ni un motif, ni une donnée financière.
- Les statuts HTTP et l'en-tête `Cache-Control: no-store, private` restent inchangés.

## 2. Compatibilité avec les réponses de validation (422)

- Le champ `errors` (détail par champ, format Laravel) **est conservé** tel quel à côté de `message`. L'addenda n'en retire rien.
- Les 422 actuelles ont la forme `{message, errors}`, **sans `code`**. Ajouter un `code` exige de nommer un identifiant (proposition : `VALIDATION_FAILED`). Ce point n'est **pas** tranché par la décision du 22/11 : il est soumis à arbitrage (§4) et n'est pas appliqué ici.

## 3. Portée de la correction F-05 (appliquée)

| Route | Avant | Après |
|---|---|---|
| `GET /api/admin/users/{id}` (BE-06), utilisateur absent | `404 {"code":"USER_NOT_FOUND"}` | `404 {"message":"Utilisateur introuvable","code":"USER_NOT_FOUND"}` |
| `POST /api/admin/users/{id}/suspend` et `/reactivate` (BE-03), utilisateur absent | `404 {"message":"Utilisateur introuvable.","code":"USER_NOT_FOUND"}` | Inchangé (déjà conforme ; préservé) |

Inchangés : statut 404, code `USER_NOT_FOUND`, `no-store`, absence de `csrf` sur le refus, et nécessité du grant MFA (un admin anonyme reçoit 401, sans indication d'existence).

**Note de cohérence :** les deux messages 404 diffèrent d'un point final (« Utilisateur introuvable » pour BE-06, comme le demande la décision, et « Utilisateur introuvable. » pour BE-03, préservé). Les deux sont conformes. L'harmonisation est laissée au propriétaire.

## 4. Écarts restants : inventaire mesuré, décision requise, non modifiés

Ces écarts ont été mesurés le 22/11/2026 sur `ce4021e`, avec une instance locale isolée et des données fictives.

| Famille | Exemples mesurés (statut, corps) | Composant | Alignement proposé | Pourquoi non appliqué |
|---|---|---|---|---|
| A. `code` sans `message` | 401 `{"code":"ADMIN_BEARER_REFUSED"}` ; 403 `ADMIN_ORIGIN_REFUSED` ; 503 `ADMIN_UNAVAILABLE` ; 401 `ADMIN_AUTH_REQUIRED` ; 403 `ADMIN_MFA_NOT_DELIVERED` | BE-01 (middlewares `SelectAdminContext`, `AdminMfaEnabled`, `RequireAdminIdentity`, `AdminMfaNotDelivered`) | Ajouter un `message` générique ; codes inchangés | Hors périmètre F-05 : modifie des composants de sécurité BE-01 |
| B. `message` qui contient un code, sans clé `code` | 401 `{"message":"ADMIN_AUTH_FAILED"}`, `ADMIN_CHALLENGE_REQUIRED`, `ADMIN_FACTOR_REFUSED` ; 403 `ADMIN_RECENT_AUTH_REQUIRED` (y compris sur les routes BE-03) ; 429 `ADMIN_AUTH_THROTTLED` ; 409 `ADMIN_ENROLLMENT_ALREADY_ISSUED` | BE-02 (`AdminMfa`, via `abort()`) | Ajouter `code` égal à l'identifiant actuel, puis décider si `message` devient un texte humain (le frontend lit aujourd'hui `message`) | Contrat BE-02 et compatibilité frontend |
| C. Aucun identifiant | 401 `{"message":""}` (`abort(401)` nu de `AdminMfa`) ; 419 `CSRF token mismatch.` ; 429 `Too Many Attempts.` (dont le limiteur BE-03) ; 404/405 sans route (le message cite le chemin) ; toutes les 422 (`{message, errors}`, y compris BE-03) | BE-02, framework (`Handler`), BE-03 | Nommer de nouveaux codes, par exemple `ADMIN_SESSION_INVALID`, `CSRF_TOKEN_MISMATCH`, `RATE_LIMITED`, `ROUTE_NOT_FOUND`, `METHOD_NOT_ALLOWED`, `VALIDATION_FAILED` | Création d'identifiants : décision de contrat |

Aucune de ces réponses ne divulgue de motif, de donnée financière ni d'état de compte. Les 404 et 405 sans route citent seulement le chemin demandé par l'appelant.
