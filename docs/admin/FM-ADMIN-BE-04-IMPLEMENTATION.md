# FluxMoneva Admin V1 — BE-04 : audit administratif append-only

**Mission :** `FM-ADMIN-BE-04-IMPLEMENTATION-01`, développement local uniquement.
**Base :** `integration/security-auth-v3` @ `6b02e4d9ef6d56e696e3fdf6a38243ad91ec7e5c` (merge BE-03). Branche : `feature/fm-admin-be-04-audit`.
**Décisions appliquées :** D1 à D5, D7 et D8 du préflight `FM-ADMIN-BE-04-PREFLIGHT-01`. D6 (relecteur) est laissée au propriétaire.

## 1. Ce que BE-04 ajoute

| Élément | Fichier | Rôle |
|---|---|---|
| Migration additive | `database/migrations/2026_11_23_000001_protect_admin_audit_events.php` | Triggers `admin_audit_events_no_update` et `admin_audit_events_no_delete` (avant chaque UPDATE ou DELETE de ligne), qui refusent avec le message « admin_audit_events is append-only » |
| Écrivain unique | `app/Services/Admin/AdminAuditLog.php` | Seul code applicatif qui touche la table : INSERT uniquement |
| Délégation | `app/Services/Admin/ClientAccountTransitions.php` | Appelle `AdminAuditLog::record()` au même endroit, dans la même transaction et dans le même ordre de verrous qu'avant |

**Règles de `AdminAuditLog` :**
- seuls `succeeded` et `refused_redundant` sont acceptés (D2) ;
- un refus ne porte jamais de motif ;
- l'écriture est refusée hors transaction, pour garantir l'atomicité statut + audit + outbox (A3) ;
- aucun champ IP, User-Agent ni identifiant de requête (D3).

**Inchangés :** le schéma BE-03 (aucune colonne ajoutée), les migrations BE-03, `admin_security_events` (D5), les routes (consultation : BE-07) et l'envoi des notifications (BE-05).

**Moteurs :**
- MySQL 8.4.3 : qualifié.
- SQLite : triggers `RAISE(ABORT)`, vérifiés sur les bancs.
- MariaDB 10.11 : même syntaxe `SIGNAL` que MySQL, **qualification non exécutée pour BE-04** (§5).
- PostgreSQL : implémenté (fonction plpgsql et triggers), **non qualifié** (aucune instance de test autorisée).

## 2. Ce que les triggers garantissent, et ce qu'ils ne garantissent pas

**Garanti (prouvé sur MySQL 8.4.3, et sur SQLite pour la partie base) :**
- tout `UPDATE` ou `DELETE` d'une ligne de `admin_audit_events` échoue, **quel que soit le compte SQL** (root compris), et la ligne reste identique ;
- l'`INSERT` par l'écrivain unique fonctionne ;
- le **rollback** d'une transition annulée retire bien sa ligne d'audit (un rollback InnoDB n'exécute pas de `DELETE`) ; l'atomicité BE-03 est intacte.

**Non garanti, par nature des triggers de ligne :**
- `TRUNCATE TABLE` (constaté : il **réussit** et vide la table, test A13) ;
- `DROP TABLE`, `ALTER TABLE`, `RENAME TABLE` ;
- `DROP TRIGGER` (suppression de la protection elle-même) ;
- toute restauration de sauvegarde antérieure.

Ces opérations ne peuvent être empêchées que par les **privilèges SQL**.

## 3. Droits SQL et séparation des privilèges

| Usage | Privilèges nécessaires | Privilèges à NE PAS accorder |
|---|---|---|
| Compte de **migration** (déploiement) | DDL sur la base, dont `TRIGGER` sur `admin_audit_events` (création et suppression des triggers) | — (compte à n'utiliser que pendant le déploiement) |
| Compte **applicatif** (exécution) | `INSERT`, `SELECT` sur `admin_audit_events` | `DROP` (qui couvre aussi `TRUNCATE` sur MySQL et MariaDB), `ALTER`, `TRIGGER`. `UPDATE` et `DELETE` sont inutiles (refusés de toute façon) |

- Avec un compte applicatif ainsi restreint, `TRUNCATE`, `DROP` et `DROP TRIGGER` deviennent impossibles à l'exécution. Seul un compte d'administration de la base pourrait encore altérer le journal.
- **Journalisation binaire :** sur MySQL et MariaDB, lorsque la journalisation binaire est active, `CREATE TRIGGER` peut exiger un privilège étendu (par exemple `SUPER`), sauf si `log_bin_trust_function_creators=1`. **Non mesuré** ici (Docker indisponible, §5). À qualifier dans **OPS-02**, hors périmètre de ce GO.
- **LWS :** sur un hébergement mutualisé, un seul compte disposant de tous les droits sur la base est courant. Dans ce cas, les triggers protègent contre les modifications ordinaires et les erreurs applicatives, **pas** contre un détenteur du compte qui exécuterait `TRUNCATE`, `DROP` ou `DROP TRIGGER`. Cette limite reste à arbitrer en OPS-02 : comptes séparés si LWS le permet, sinon procédures et sauvegardes.

## 4. Déploiement et rollback (non destructif, D4)

- **Ordre :** la migration BE-04 suit les migrations BE-03 (runbook BE-03, §2). Le code BE-04 n'a pas besoin des triggers pour fonctionner, et le code BE-03 (`6b02e4d`), qui n'écrit qu'en INSERT, reste compatible avec les triggers.
- **Rollback de la migration BE-04 :** `php artisan migrate:rollback --step=1`, à condition que BE-04 soit la dernière migration appliquée. Il supprime **uniquement les deux triggers** ; aucune ligne d'audit n'est touchée (prouvé, A12 : empreinte du journal identique avant et après rollback puis réapplication). Après un rollback, le journal redevient modifiable : à éviter hors incident.
- **Interdit (Q8, D4) :** revenir en arrière sur `2026_11_22_000002_create_admin_audit_events_table` (sa méthode `down()` supprime la table). Aucune purge automatique n'existe, ni BE-03 ni BE-04.
- **Rollback du code seul :** possible vers `6b02e4d`, sans effet sur le journal.

## 5. Validation

| Suite | Résultat | SHA |
|---|---|---|
| PHPUnit complet, MySQL 8.4.3 isolé | 382 réussis, 0 échec, 2 ignorés, 2 avertissements | `3b1fb21` |
| Banc `tests/AdminSuspension`, MySQL 8.4.3 isolé | 178/178 (166 BE-03 + 12 BE-04) | `3b1fb21` |
| Non-régression BE-01 / BE-02 / BE-06 (SQLite, copies isolées, `vendor` physique par copie) | Identique à `6b02e4d` : BE-01 0 échec ; BE-02 74 réussis / 1 échec (préexistant) ; BE-06 81/81 | `6b02e4d` contre `3b1fb21` |
| Triggers SQLite (base jetable d'un banc) | INSERT accepté, UPDATE et DELETE refusés, ligne conservée | `3b1fb21` |
| **MariaDB 10.11.19 (Docker)** | **NON EXÉCUTÉ.** Docker Desktop ne démarre pas (sockets orphelins dans `%LOCALAPPDATA%\Docker\run`) ; l'état interne de Docker n'a pas été modifié | — |
| PostgreSQL | **NON EXÉCUTÉ** (aucune instance autorisée) | — |

Les preuves brutes sont dans `docs/admin/FM-ADMIN-BE-04-evidence/`.

Correspondance avec la matrice du préflight :

| Scénario | Couverture |
|---|---|
| A01, A02 | PHPUnit et banc |
| A03 | Banc T04 et PHPUnit |
| A04, A05 | PHPUnit et banc T12 |
| A06 | Banc T05 |
| A07 | Sans objet : D2 restreint la journalisation à `succeeded` et `refused_redundant` |
| A08 | Banc T10 et T11 |
| A09, A11 | PHPUnit (frontières) |
| A10 | Banc S16 et T04 |
| A12 | Banc |
| A13 | Banc (constat de limite) |
| A14 | Banc S20 et A12 |
| A15 | Non-régression |
