# BE-03 — Traitement des réserves OpenCode `FM-ADMIN-BE-03-REVIEW-01`

**Base de correction :** `b623c6d533a622ff79ebf48e8552a60433e7c536`. **SHA exécuté pour les preuves :** `7e9fc4613d291640315f69fce80314a649055c44`, arbre propre. Seuls des documents ont été ajoutés depuis.

**Source des constats :** version condensée publiée le 22/11/2026 sur la carte Trello BE-03. Le rapport intégral n'était pas disponible localement.

**Source GOV-05 :** aucun document « V2.1 » n'est disponible localement. Les documents canoniques consultés sont le contrat consolidé `GOV_05_CONTRAT_CONSOLIDE_ALIGNE_A1_V1_1` (§3) et le brouillon OpenAPI 0.1.0, tirés de `FluxMoneva_GOV05_SEC01_Revue_Independante_Paquet_V1.zip`.

## 1. Constats et traitement

| Constat | Décision | Fichiers | Changement minimal | Test / résultat |
|---|---|---|---|---|
| **F-01** (MEDIUM) Migrer avant le code, runbook et rollback | Documenté | `docs/admin/FM-ADMIN-BE-03-RUNBOOK.md` | Séquence imposée, algorithmes `ALTER` **mesurés** (colonnes `INSTANT` ; contrainte CHECK en `COPY` obligatoire sur MySQL 8.4.3 et MariaDB 10.11.19), risques, prérequis d'activation, conditions de rollback (code seul de préférence ; jamais `down()` avec un audit non vide, Q8) | Mesures SQL directes sur les deux moteurs |
| **F-02** (LOW) `suspended_at` dans la liste | **Corrigé.** GOV-05 V1.1 §3 : la liste n'a pas `suspended_at`, la fiche l'a. Le contrat est inchangé | `AdminUserResource.php`, `AdminUserController.php` | `suspended_at` n'est émis que pour une ressource marquée `detail()`, ce qu'utilise `show` | Test `test_list_row_never_emits_suspended_at_once_be03_columns_exist`, tests de fiche via `detail()`, banc BE-06 (liste sans le champ, fiche avec), banc BE-03 `F-02 …` : réussis |
| **F-03** (LOW) Pas de limitation de débit sur les POST | **Corrigé.** Le 429 figure dans les codes GOV-05 de ces routes | `RouteServiceProvider.php`, `routes/admin.php` | Limiteur `admin-user-transition` : 10/min/IP, appliqué après le grant MFA | Banc : 10 × 422 puis 429, 429 avec no-store, aucun effet, fenêtre réinitialisée : réussis |
| **F-04** (LOW) no-store sur les exceptions admin en amont | **Corrigé.** Mesure préalable : les erreurs de la pile admin avaient déjà `no-store`, mais **les 404 sans route et les 405 répondaient `no-cache, private`** | `app/Exceptions/Handler.php` | `render()` impose `no-store, private` pour `api/admin` et `api/admin/*` | Banc : 401 ×2, 403 ×2, 404 ×2, 405, 409, 419, 422 et 429 portent tous `no-store, private` : réussis |
| **F-05** (LOW) Format 404 BE-06 et BE-03 divergent | **Non modifié : arbitrage requis** (§2) | — | — | — |
| **F-06** (LOW) La purge ignorait `SESSION_CONNECTION` | **Corrigé.** La barrière logique `auth_version` n'est pas touchée | `config/admin.php` (`client_session`), `ClientSessionPurger.php`, `ClientAccountTransitions.php` | Driver ≠ `database` : aucune requête. Connexion par défaut : purge dans la transaction. Autre connexion : purge après commit, best-effort, journal expurgé | `ClientSessionPurgerTest` (4 tests : aucune requête, rollback, bonne connexion après commit, rien si rollback) ; banc avec requêtes admin vues en driver `database` : réussis |
| **F-07** (LOW) Append-only seulement côté application | Conservé pour **BE-04** | — | — | — |
| **F-08** (LOW) `.env.example` non documenté | **Corrigé**, en commentaires uniquement (la CI copie ce fichier) | `.env.example` | `SESSION_CONNECTION`, `ADMIN_MFA_ENABLED`, `ADMIN_ORIGIN`, `ADMIN_SESSION_CONNECTION` documentés | PHPUnit inchangé |
| **F-09** (INFO) Borne de 300 s stricte | **Non modifié.** Passer à `≤ 300 s` affaiblirait d'une seconde la protection BE-02 existante, ce que le GO interdit. SEC-01 S04 (240 s accepté, 360 s refusé) est satisfait | — | — | Banc T02 / T04 |
| **F-10** (INFO) Docblocks obsolètes | **Corrigé** | `AdminUserResource.php`, `AdminUserController.php` | Texte à jour | — |
| **F-11** (INFO) morphMap futur | **Non modifié.** La révocation filtre déjà par `getMorphClass()`, qui suit un futur morphMap | — | — | `test_pat_revocation_targets_exact_morph_type_and_id` |
| **F-12** (INFO) Preuves écrasées | **Corrigé** | `tests/AdminSuspension/run.php` | Un JSON par exécution (horodaté, nom unique) avec `git_head`, `git_dirty`, digest d'image, version PHP et horaires | `docs/admin/evidence/be03-review01/` |
| **F-13** (INFO) `SendVerificationEmailJob` ignore le statut | **Non modifié.** Aucun accès métier n'en résulte ; le changer modifierait un flux public hors contrat BE-03. À arbitrer si souhaité | — | — | — |
| **F-14** (INFO) T10a tautologique | **Corrigé** | `tests/AdminSuspension/run.php`, `lib.php` | Le contrôle compare les horodatages réels : la suspension démarre avant la fin de l'écriture, et l'écriture se termine avant la suspension | Banc : réussi ×4 |
| **F-15** (INFO) Commentaire résiduel | **Corrigé** | `AuthController.php` | `// 🔥 AJOUTEZ CETTE LIGNE` remplacé | — |

**Découverte pendant ce traitement (banc).** Rendre le driver de session explicite à chaque requête a mis au jour un effet réel de la purge transactionnelle. En sessions `database`, le `DELETE … WHERE user_id` pose des verrous de plage sur l'index `sessions.user_id`, et une création de session concurrente attend le commit de la transition. La sécurité est inchangée (login concurrent : 401, aucune session). L'effet est couvert par le nouveau scénario T10d et documenté dans le runbook.

## 2. Arbitrage demandé : format du 404 (F-05)

- **BE-06** (`GET /api/admin/users/{id}`) renvoie `{"code":"USER_NOT_FOUND"}`.
- **BE-03** (`POST …/suspend|reactivate`) renvoie `{"message":"…","code":"USER_NOT_FOUND"}`, conforme au schéma `Error` du brouillon OpenAPI 0.1.0 (`message` requis).
- Le contrat consolidé V1.1 ne fixe pas le corps d'erreur, et l'OpenAPI est marqué « non ratifié ». Il n'existe donc pas de base ratifiée pour modifier BE-06 ni BE-03.
- Les deux formats portent le même `code`, sur lequel un client peut s'appuyer.
- **Question :** faut-il (a) aligner BE-06 sur `{message, code}`, comme l'OpenAPI 0.1.0, ou (b) réduire BE-03 à `{code}` ?

## 3. Exécutions (SHA `7e9fc46`, arbre propre)

| Suite | MySQL 8.4.3 (instance locale isolée) | MariaDB 10.11.19 (Docker, digest `sha256:e5fe75b2…`) |
|---|---|---|
| PHPUnit complet | 369 réussis, 0 échec, 2 ignorés, 2 avertissements | 369 réussis, 0 échec, 2 ignorés, 2 avertissements |
| Banc BE-03 (161 contrôles) | 161/161 (1 exécution) | 161/161 aux 3 exécutions, chacune sur une base recréée |
| BE-01 (SQLite) | 0 échec, identique à `1a3141c` | — |
| BE-02 (SQLite) | 74 réussis / 1 échec, identique à `1a3141c` | — |
| BE-06 (SQLite) | 80/80, identique à `1a3141c` | — |

L'échec BE-02 est le contrôle `only auth endpoints no business routes`, dont la liste attendue date d'avant BE-06.

Les preuves brutes sont dans `docs/admin/evidence/be03-review01/` : quatre JSON (un par exécution du banc) et `SUMMARY.txt`, qui reprend les lignes PHPUnit et la non-régression telles qu'imprimées.

**Traçabilité des preuves antérieures.** Pour la qualification du 22/11 sur `b623c6d`, seul le JSON de la 3e exécution MariaDB avait été conservé ; les deux autres ne sont attestées que par leurs journaux texte. Ces exécutions anciennes ne sont **pas** présentées ici comme reproduites ; les résultats ci-dessus proviennent d'exécutions nouvelles sur `7e9fc46`.

## 4. Réserves maintenues

- **BE-04 :** immuabilité de l'audit au niveau base (F-07), consultation et rétention (Q8).
- **BE-05 :** envoi, relances et déduplication des notifications (T13).
- **GOV-05 :** format du corps d'erreur (F-05). Écart BE-06 `nom`/`q` contre `name`/`search`, préexistant.
- **OPS-01/02/03, GOV-06 :** prérequis d'activation (runbook §4). Durée réelle de la reconstruction `COPY` de `users` à mesurer sur une copie de taille réelle.
- **Revue humaine :** un relecteur humain distinct de feriol12 reste requis avant toute PR.
