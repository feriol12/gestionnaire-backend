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
- MariaDB 10.11.19 : **qualifié le 23/11/2026** sur `e0db55f` (Docker, image épinglée par digest), voir §5.
- PostgreSQL : implémenté (fonction plpgsql et triggers), **non qualifié** (aucune instance de test autorisée).

## 2. Ce que les triggers garantissent, et ce qu'ils ne garantissent pas

**Garanti (prouvé sur MySQL 8.4.3 et MariaDB 10.11.19, et sur SQLite pour la partie base) :**
- tout `UPDATE` ou `DELETE` d'une ligne de `admin_audit_events` échoue et la ligne reste identique. Un trigger ne dépend pas du compte SQL qui exécute l'instruction ; c'est **attesté** avec le compte `root` sur MySQL 8.4.3 (banc) et avec un compte non-root ayant tous les droits sur la base sur MariaDB 10.11.19 (banc et instance avec journalisation binaire). Les autres comptes n'ont pas été testés individuellement ;
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
| Compte **applicatif** (exécution) | **`INSERT` seulement** sur `admin_audit_events` (OpenCode A-02). BE-04 ne lit jamais le journal (test A09) | `SELECT`, **tant que BE-07 n'a pas défini ses besoins et son rôle de lecture** ; `DROP` (qui couvre aussi `TRUNCATE` sur MySQL et MariaDB) ; `ALTER` ; `TRIGGER`. `UPDATE` et `DELETE` sont inutiles (refusés de toute façon) |

- Avec un compte applicatif ainsi restreint, `TRUNCATE`, `DROP` et `DROP TRIGGER` deviennent impossibles à l'exécution. Seul un compte d'administration de la base pourrait encore altérer le journal.
- **Journalisation binaire (mesuré sur MariaDB 10.11.19, `log_bin=1`, compte non-root ayant tous les droits sur la base) :**
  - avec `log_bin_trust_function_creators=0` et sans `SUPER`, `CREATE TRIGGER` **échoue** (`1419 You do not have the SUPER privilege and binary logging is enabled`). La migration BE-04 n'est alors pas enregistrée et l'état reste cohérent : les tables BE-03 sont créées, aucun trigger, migration rejouable ;
  - **`DROP TRIGGER` exige le même privilège** : sans `SUPER` (ou `log_bin_trust_function_creators=1`), le rollback BE-04 échoue aussi et les triggers restent en place ;
  - deux remèdes mesurés : `log_bin_trust_function_creators=1` (réglage global du serveur, posé par un administrateur) ou `SUPER` accordé au compte de migration ; création, rollback et réapplication réussissent alors ;
  - une fois installés, les triggers refusent UPDATE et DELETE de la même façon (`1644 (45000) admin_audit_events is append-only`).
  Sur LWS, l'activation de la journalisation binaire et ces privilèges restent à qualifier en **OPS-02**.
- **LWS :** sur un hébergement mutualisé, un seul compte disposant de tous les droits sur la base est courant. Dans ce cas, les triggers protègent contre les modifications ordinaires et les erreurs applicatives, **pas** contre un détenteur du compte qui exécuterait `TRUNCATE`, `DROP` ou `DROP TRIGGER`. Cette limite reste à arbitrer en OPS-02 : comptes séparés si LWS le permet, sinon procédures et sauvegardes.

### 3.1 `DEFINER` des triggers (OpenCode A-04)

- Sur MySQL et MariaDB, chaque trigger est créé avec un `DEFINER` égal au compte qui exécute la migration. La migration ne précise pas de `DEFINER` ; elle hérite donc du compte de migration.
- **Exigence d'exploitation :** ce compte doit rester **valide et stable** (même nom et même hôte), avec ses privilèges, tant que les triggers existent. Contrôle : `SELECT trigger_name, definer FROM information_schema.triggers WHERE event_object_table = 'admin_audit_events';`.
- **Risque :** si ce compte est supprimé ou renommé, le déclenchement d'un trigger peut échouer avec l'erreur **1449** (« The user specified as a definer does not exist »). Comme les triggers ne portent que sur UPDATE et DELETE, l'INSERT de l'application ne devrait pas être concerné, mais le diagnostic devient trompeur. **Non mesuré ici.**
- **Remède supervisé :** recréer les deux triggers avec un `DEFINER` valide, dans une fenêtre de maintenance, par un administrateur. Cela suppose une procédure distincte, à arbitrer, la migration BE-04 n'étant pas modifiée.
- La qualification réelle (compte de migration sur LWS, effet d'une suppression du `DEFINER`) relève d'**OPS-02**.

## 4. Déploiement et rollback (non destructif, D4)

- **Ordre :** la migration BE-04 suit les migrations BE-03 (runbook BE-03, §2). Le code BE-04 n'a pas besoin des triggers pour fonctionner, et le code BE-03 (`6b02e4d`), qui n'écrit qu'en INSERT, reste compatible avec les triggers.
- **Rollback de la migration BE-04 :** `php artisan migrate:rollback --step=1`, à condition que BE-04 soit la dernière migration appliquée. Il supprime **uniquement les deux triggers** ; aucune ligne d'audit n'est touchée (prouvé, A12 : empreinte du journal identique avant et après rollback puis réapplication). Après un rollback, le journal redevient modifiable : à éviter hors incident.
- **Interdit (Q8, D4) :** revenir en arrière sur `2026_11_22_000002_create_admin_audit_events_table` (sa méthode `down()` supprime la table). Aucune purge automatique n'existe, ni BE-03 ni BE-04.
- **Rollback du code seul :** possible vers `6b02e4d`, sans effet sur le journal.

### 4.1 DDL partiellement appliquée : un seul trigger installé (OpenCode A-03)

`up()` crée les deux triggers l'un après l'autre. Sur MySQL et MariaDB, chaque `CREATE TRIGGER` est validé implicitement : si le second échoue (privilège, journalisation binaire, coupure), le premier reste en place. La migration n'est **pas** enregistrée dans `migrations`, et un simple `migrate` rejoué échouera sur le trigger déjà présent (« Trigger already exists »). **Scénario documenté, non reproduit en test.**

1. **Diagnostic, sans rien modifier :**
   - `SELECT trigger_name, event_manipulation, definer FROM information_schema.triggers WHERE event_object_schema = DATABASE() AND event_object_table = 'admin_audit_events';` : attendu 0 ou 2 lignes ; 1 ligne signifie un état partiel ;
   - `SELECT migration, batch FROM migrations WHERE migration = '2026_11_23_000001_protect_admin_audit_events';` : absente en cas d'échec ;
   - le journal du déploiement (code d'erreur, par exemple `1419`).
2. **Ne pas** supprimer le trigger déjà installé pour « repartir propre » : cela ouvrirait une fenêtre sans protection. La migration ne fait volontairement aucun `DROP TRIGGER` préventif.
3. **Reprise supervisée** (administrateur de la base, fenêtre de maintenance, sauvegarde préalable) :
   - corriger la cause (privilège, réglage de journalisation binaire, connexion) ;
   - créer **uniquement le trigger manquant** avec l'instruction exacte de la migration ;
   - vérifier que les 2 triggers sont présents et que la protection est effective (un UPDATE de test est refusé ; à faire sur une copie si possible) ;
   - enregistrer la migration dans `migrations`, au lot suivant, pour aligner l'état Laravel.
4. Toute évolution du mécanisme de migration (idempotence, `DROP` préventif, transaction de DDL) exige un **arbitrage séparé** ; BE-04 ne la change pas.

## 5. Validation

| Suite | Résultat | SHA |
|---|---|---|
| PHPUnit complet, MySQL 8.4.3 isolé | 382 réussis, 0 échec, 2 ignorés, 2 avertissements | `3b1fb21` |
| Banc `tests/AdminSuspension`, MySQL 8.4.3 isolé | 178/178 (166 BE-03 + 12 BE-04) | `3b1fb21` |
| Non-régression BE-01 / BE-02 / BE-06 (SQLite, copies isolées, `vendor` physique par copie) | Identique à `6b02e4d` : BE-01 0 échec ; BE-02 74 réussis / 1 échec (préexistant) ; BE-06 81/81 | `6b02e4d` contre `3b1fb21` |
| Triggers SQLite (base jetable d'un banc) | INSERT accepté, UPDATE et DELETE refusés, ligne conservée | `3b1fb21` |
| MariaDB 10.11.19 (Docker `mariadb@sha256:e5fe75b2…`), PHPUnit complet | 382 réussis, 0 échec, 2 ignorés, 2 avertissements | `e0db55f` |
| MariaDB 10.11.19, banc `tests/AdminSuspension` | **178/178 aux 3 exécutions**, chacune sur une base recréée | `e0db55f` |
| MariaDB 10.11.19 avec journalisation binaire (instance jetable séparée) | Mesures S1 à S5 ci-dessus (§3) | `e0db55f` |
| PostgreSQL | **NON EXÉCUTÉ** (aucune instance autorisée) | — |

Les preuves sont dans `docs/admin/FM-ADMIN-BE-04-evidence/` :
- `SUMMARY.txt` : exécution sur `3b1fb21`, annotée (A-01) ;
- `MARIADB-SUMMARY.txt` : MariaDB et journalisation binaire ;
- un JSON par exécution du banc, chacun avec son SHA ;
- `raw/` : journaux bruts d'époque, archivés pour A-05 et A-06.

**Niveaux de preuve** (détail dans `raw/README.txt`) :
- **attesté par un artefact lié au SHA** : les JSON du banc ;
- **attesté par un journal brut d'époque** : les sorties PHPUnit complètes (MySQL 8.4.3 et MariaDB 10.11.19), les sorties du banc et la sonde SQLite ;
- **extrait seulement** : la non-régression BE-01/BE-02/BE-06 ; les journaux complets de ces bancs n'ont pas été conservés et ne sont pas reconstitués ;
- **déclaré avec la sortie capturée** : les mesures S1 à S5 de journalisation binaire ;
- **non exécuté** : PostgreSQL et LWS.

Rien de tout cela n'a été rejoué par un relecteur ni confirmé par la CI GitHub (aucune publication).

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
