# BE-03 — Runbook de séquencement, prérequis d'activation et rollback

**Statut :** document préparatoire (OpenCode F-01). **Aucun déploiement, aucune migration serveur, aucune activation ne sont autorisés par ce document.** Chaque étape exige son propre GO propriétaire.

## 1. Pourquoi l'ordre est obligatoire

Le code BE-03 est *fail-closed* : la barrière `client.active` et le login refusent tout compte dont `users.administrative_status` n'est pas `active`.

- **Code avant migration :** la colonne n'existe pas, donc l'attribut vaut `null`. **Tous les logins clients échouent (401)** et toutes les requêtes authentifiées sont refusées. C'est une indisponibilité totale côté client.
- **Migration avant code :** sans risque fonctionnel. Le code 1a3141c ignore les nouvelles colonnes, et les comptes existants reçoivent `active` / version 1.

**Ordre imposé : migrations → vérification → déploiement du code.** Jamais l'inverse, jamais en parallèle.

## 2. Séquence

1. **Préalables** :
   - sauvegarde vérifiée (et restaurable) de la base cible ;
   - fenêtre de maintenance annoncée ;
   - GO propriétaire ;
   - SHA exact à déployer, revu.
2. **Migrations** (`2026_11_22_000001` à `000003`), exécutées seules, avant tout changement de code.
3. **Vérification SQL** sur la cible :
   - les colonnes `users.administrative_status`, `auth_version`, `suspended_at`, `suspended_by` sont présentes ;
   - la contrainte `users_administrative_status_check` est présente ;
   - les tables `admin_audit_events` et `notification_outbox` existent ;
   - `SELECT COUNT(*) FROM users WHERE administrative_status <> 'active'` vaut 0 ;
   - les volumes de `expenses` et `budgets` sont inchangés.
4. **Déploiement du code**, puis vidage des caches (`config`, `route`), si le cache est utilisé.
5. **Contrôles post-déploiement** : login client réel avec un compte de test, `/api/user`, une lecture et une écriture financières de test.
6. **Activation admin (séparée)** : uniquement après les prérequis du §4.

## 3. Risques de disponibilité

| Risque | Effet | Mesure |
|---|---|---|
| Code déployé avant la migration | 401 pour tous les clients | Ordre du §2 ; vérifier le §2.3 avant de déployer |
| Ajout de la contrainte CHECK sur `users` | MySQL 8.4 : colonnes en `ALGORITHM=INSTANT`, mais **la contrainte CHECK exige `ALGORITHM=COPY`**, soit une reconstruction de `users` qui bloque ses écritures (logins, `last_activity`) le temps de la copie. MariaDB 10.11 : voir le rapport de qualification (mesure) | Fenêtre de maintenance ; mesurer la durée sur une copie de taille réelle |
| Sessions navigateur existantes | Aucune n'a de version liée : **toutes sont refusées une fois** au premier appel après déploiement (401), d'où un nouveau login obligatoire | À annoncer aux utilisateurs ; effet unique |
| Cookies « remember » | Toujours refusés (pas de version liée) ; l'application n'en émet pas | Aucune |
| Driver de session `database` | Pendant une transition, la purge `DELETE … WHERE user_id` pose des verrous de plage sur `sessions.user_id` : une création ou écriture de session concurrente peut attendre le commit (quelques millisecondes en temps normal) | Transitions rares et courtes ; observé et borné par le banc (T10d) |
| `SESSION_CONNECTION` distincte | La purge physique a lieu après commit, en best-effort ; un échec est journalisé sans motif et la révocation logique reste effective | Aucune action requise |
| Intentions d'outbox sans BE-05 | Les lignes `notification_outbox` restent `pending` (aucun envoi) | Normal tant que BE-05 n'est pas livré |

## 4. Prérequis d'activation des routes admin BE-03

- OPS-01 : origine admin HTTPS exacte (`ADMIN_ORIGIN`), DNS, TLS, proxy et cookies qualifiés.
- OPS-02 : moteur, verrous et driver de session de la cible qualifiés.
- Singleton Super Admin provisionné et enrôlé (BE-02), procédure GOV-06 disponible.
- `ADMIN_MFA_ENABLED=true` seulement après les points précédents (GO distinct).
- BE-05 (envoi des notifications) et BE-04 (consultation et rétention de l'audit) : non requis pour la barrière, mais requis pour livrer DM-007 et Q8.

## 5. Rollback

- **Rollback du code seul (préféré)** : revenir au SHA précédent ; les colonnes et tables BE-03 peuvent rester, car l'ancien code les ignore.
  - ⚠ L'ancien code **ne connaît pas la suspension**. Tout compte suspendu redevient utilisable (login, PAT créés ensuite). Avant un tel rollback, réactiver ou traiter explicitement les comptes suspendus, et l'acter.
- **Rollback des migrations (à éviter)** : `down()` supprime `admin_audit_events` et `notification_outbox`, ce qui **détruirait le journal d'audit**, contraire à Q8 (aucune purge avant politique de rétention). À n'envisager que si aucune transition réelle n'a eu lieu (audit vide, vérifié), et après une sauvegarde.
- **Ordre si les deux sont nécessaires** : code d'abord, migrations ensuite, jamais l'inverse (sinon on retombe sur le cas « code BE-03 sans colonnes »).

## 6. Hors périmètre

- Immuabilité de l'audit au niveau base (déclencheurs, droits) : **BE-04** (OpenCode F-07).
- Envoi SMTP, relances et déduplication : **BE-05**.
