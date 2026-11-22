# FluxMoneva Admin V1 — BE-03 : suspension, réactivation, révocation globale

**Statut :** développement local terminé, en attente de revue indépendante. Rien n'a été poussé, mergé ni déployé, et aucune route n'est activée en production.
**Base :** `integration/security-auth-v3` @ `1a3141c6fb6ff5f50f45fd6abf8af54a9a663c13`. Branche : `feature/fm-admin-be-03-suspension-v2`.
**Références :** contrat BE-03 Q1–Q8, GOV-05 (contrat aligné A1 V1.1 et brouillon OpenAPI 0.1.0), SEC-01 (matrice V1, I01–I09, S01–S20), DM-001..007, arbitrages A1–A4.

## 1. Schéma commun minimal BE-03 / BE-04 / BE-05

| Table / colonne | Propriétaire | Rôle dans BE-03 | Laissé à la suite |
|---|---|---|---|
| `users.administrative_status` `varchar(16)`, défaut `active`, contrainte CHECK `active\|suspended` | BE-03 | Barrière de statut (Q2) | — |
| `users.auth_version` `bigint unsigned`, défaut 1 | BE-03 | Version d'accès monotone ; la session y est liée au login | — |
| `users.suspended_at`, `users.suspended_by` (sans FK) | BE-03 | Métadonnées de la suspension courante, remises à NULL à la réactivation | — |
| `admin_audit_events` (append-only, sans FK, sans `updated_at`) | **BE-04** | Une ligne par transition, écrite dans la même transaction ; refus redondants tracés sans motif | Consultation restreinte, rétention (Q8), extension à d'autres actions, éventuels déclencheurs anti-UPDATE/DELETE |
| `notification_outbox` | **BE-05** | Une intention neutre par transition, dans la même transaction | Worker, bail, retries, déduplication d'envoi, SMTP, suivi des erreurs |

Le motif est conservé **en clair uniquement** dans `admin_audit_events.reason` (Q3). Il n'apparaît dans aucune réponse, aucune intention d'outbox, aucun flash de session ni aucun journal.

## 2. Transition (`ClientAccountTransitions`)

La transition se fait en une seule transaction, avec des verrous pris dans un ordre global stable :

1. Ligne singleton admin : `AdminMfa::requireRecentAuth` revérifie le grant MFA complet **et** une preuve de moins de 300 s.
2. Ligne `users` ciblée, prise en `FOR UPDATE`.
3. Contrôle `confirm_email` exact (avant l'état, pour qu'un 422 ne révèle pas le statut).
4. CAS sur statut et version, qui doit toucher exactement 1 ligne.
5. Révocation :
   - suppression des PAT filtrée sur `tokenable_type` **et** `tokenable_id` ;
   - purge physique complémentaire des sessions client (`ClientSessionPurger`) : uniquement si `SESSION_DRIVER=database` ; dans la transaction sur la connexion par défaut, après commit en best-effort si `SESSION_CONNECTION` est distincte (F-06) ;
   - rotation du `remember_token`.
6. Écriture de l'audit.
7. Insertion de l'intention dans l'outbox.

Tout échec entraîne un rollback complet. Aucune table financière n'est lue ni écrite, et la ligne `users` n'est jamais supprimée.

## 3. Barrières client

- **`client.active`** (`EnsureClientAccountActive`) : placé après `auth:sanctum` et `client.identity`, avant `activity.check`, sur toutes les routes authentifiées sauf `/api/logout` (volontairement, pour qu'une fermeture reste toujours possible).
  - Un statut différent de `active` donne `403 CLIENT_SUSPENDED` ; l'accès courant est alors fermé (session invalidée, PAT supprimé).
  - Une session dont la version est absente ou différente donne `401`, que le driver de session soit `file`, `database` ou autre.
- **`client.write`** (`SerializeClientWrites`) : toute requête non sûre (création, modification, suppression) s'exécute dans une transaction qui verrouille d'abord la ligne `users`, relit le statut et la version, puis écrit. Si l'écriture obtient le verrou en premier, elle commit avant la suspension. Si la suspension l'obtient en premier, l'écriture est refusée sans effet. Les lectures ne prennent pas de verrou.
- **Login** : un compte suspendu reçoit le même 401 générique qu'un mauvais mot de passe (A1), avant même le contrôle de vérification d'email. La décision finale est relue sous le verrou `users`, puis la version est liée à la session.
- **Reset** : aucun jeton n'est émis pour un compte suspendu. Un jeton valide n'a aucun effet (mot de passe inchangé, jeton consommé) et produit la même réponse 422 qu'un lien invalide (Q5).
- **Cookie « remember »** : la session qu'il reconstitue n'a pas de version liée et est donc refusée (fail-closed). L'application n'émet aucun cookie de ce type.

## 4. Codes HTTP retenus (GOV-05 §4, OpenAPI `Error {message, code?, errors?}`)

| Cas | Réponse |
|---|---|
| Succès | `200 {user_id, administrative_status, changed_at, event_id}` |
| Admin absent ou pré-MFA | `401` (code existant d'AdminMfa) |
| Bearer sur une route admin | `401 ADMIN_BEARER_REFUSED` (existant) |
| Origine refusée | `403 ADMIN_ORIGIN_REFUSED` (existant) |
| Preuve MFA de plus de 300 s | `403 ADMIN_RECENT_AUTH_REQUIRED` (code existant de BE-02, désormais factorisé) |
| CSRF | `419` |
| `reason` ou `confirm_email` invalide | `422 {message, errors}` sans écho des valeurs |
| Cible inconnue | `404 {message, code: USER_NOT_FOUND}` (BE-06 renvoie `{code}` seul : écart soumis à arbitrage GOV-05, voir la réponse à la revue) |
| Transition redondante | `409 ALREADY_SUSPENDED` / `409 ALREADY_ACTIVE` |
| CAS perdu | `409 STATE_CONFLICT` |
| Plus de 10 transitions par minute et par IP (après le grant MFA) | `429` (limiteur `admin-user-transition`, F-03) |
| Échec d'audit, d'outbox ou SQL | `500 TRANSITION_FAILED`, sans SQL ni motif ; le journal serveur est expurgé |
| Client suspendu encore authentifié | `403 CLIENT_SUSPENDED` |
| Session ou PAT révoqué | `401` |

Toute réponse sous `/api/admin/*`, erreurs comprises (route absente, mauvais verbe, 429), porte `Cache-Control: no-store, private` (F-04). Dans BE-06, `suspended_at` n'apparaît que dans la fiche, jamais dans la liste (GOV-05 V1.1 §3, F-02).

Ces codes nommés suivent le contrat BE-03 §5.D et GOV-05 (`STATE_CONFLICT`, `CLIENT_SUSPENDED`). L'OpenAPI 0.1.0 n'est pas encore ratifié et pourra les renommer.

## 5. Validation (données fictives, instances jetables)

- **PHPUnit** : `php artisan test`. Suite complète sur la base `gestionnaire_test`, dont `tests/Feature/ClientSuspensionBarrierTest.php`.
- **Banc de bout en bout** : `php tests/AdminSuspension/run.php run`. Il exige `BE03_DB_*` et vérifie le port et le `datadir` de l'instance avant tout `migrate:fresh`. Il couvre :
  - T01 à T17 ;
  - les courses entre processus réels : écriture gagnante, suspension gagnante, login concurrent, transitions simultanées ;
  - l'injection de pannes d'audit et d'outbox ;
  - les routes mises en cache.
- **Non-régression** : bancs `tests/AdminFoundation` (BE-01), `tests/AdminMfa` (BE-02) et `tests/AdminUsersHttp` (BE-06). Leurs listes de migrations figées incluent désormais les trois migrations BE-03.

## 6. Limites connues

- **T13 :** l'envoi, les retries et la déduplication SMTP relèvent de BE-05. BE-03 prouve seulement que l'intention est durable, unique et neutre.
- **Activation :** le driver de session LWS réel, MariaDB sur la cible, OPS-01/02/03 et la procédure GOV-06 restent à qualifier avant toute activation.
- **Séquencement et rollback :** voir `FM-ADMIN-BE-03-RUNBOOK.md` (migrations obligatoirement avant le code).
- **Écart BE-06 / GOV-05 :** BE-06 expose `nom` et `q`, alors que GOV-05 prévoit `name` et `search`. Cet écart est préexistant et hors périmètre BE-03.
- **Déploiement :** toutes les sessions navigateur antérieures au déploiement n'ont pas de version liée et seront refusées une fois (nouveau login requis). La migration doit précéder le code.
