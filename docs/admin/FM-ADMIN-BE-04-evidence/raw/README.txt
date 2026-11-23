BE-04 — journaux bruts archivés (OpenCode REVIEW-01, A-05 / A-06)

Ces fichiers sont les sorties ORIGINALES des exécutions du 23/11/2026. Ils ont été
copiés depuis le répertoire de travail temporaire de la session lors de la mission
FM-ADMIN-BE-04-REVIEW-01-RESPONSE.
Aucune exécution n'a été rejouée pour les produire. Aucun relecteur ne les a
reproduits. Aucune CI GitHub ne les a confirmés (aucune publication).

Transformations appliquées, et uniquement celles-ci :
- chemins temporaires du poste (C:\Users\<compte>\AppData\Local\Temp\) remplacés
  par <TEMP>/ : 2 lignes dans chaque journal PHPUnit, dans des noms de tests du
  verrou de worker ;
- fins de ligne CRLF converties en LF, séquences de couleur ANSI retirées.
Aucune ligne de résultat n'a été modifiée, ajoutée ni retirée.

Fichier                                            SHA exécuté  Moteur            Contenu
phpunit-mysql84-3b1fb21.txt                        3b1fb21      MySQL 8.4.3       sortie complète de `php artisan test`
phpunit-mariadb-10.11.19-e0db55f.txt               e0db55f      MariaDB 10.11.19  sortie complète de `php artisan test`
harness-mysql84-3b1fb21.txt                        3b1fb21      MySQL 8.4.3       sortie complète du banc (1 exécution)
harness-mariadb-10.11.19-e0db55f-run{1,2,3}.txt    e0db55f      MariaDB 10.11.19  sortie complète du banc (3 exécutions)
nonregression-extract-6b02e4d-vs-3b1fb21.txt       6b02e4d / 3b1fb21  SQLite      EXTRAIT filtré (voir ci-dessous)
sqlite-trigger-probe-3b1fb21.txt                   3b1fb21      SQLite            sortie complète de la sonde
docker-state-before/after-mariadb.txt              —            —                 état Docker avant et après qualification

e0db55f et 3b1fb21 portent le même code et les mêmes tests ; seuls la documentation
et les preuves diffèrent entre eux.

NIVEAUX DE PREUVE
A. Attesté par un artefact machine lié au SHA : les fichiers JSON du dossier parent
   (un par exécution du banc). Ils contiennent git_head, git_dirty=false, le moteur,
   le digest d'image et chaque contrôle. Ils couvrent 1 exécution MySQL 8.4.3 et
   3 exécutions MariaDB 10.11.19, chacune à 178/178.
B. Attesté par le journal brut d'époque (ce dossier) : PHPUnit 382 réussis sur
   MySQL 8.4.3 (3b1fb21) et sur MariaDB 10.11.19 (e0db55f), sorties complètes ;
   journaux du banc ; sonde SQLite.
C. Seulement un EXTRAIT, filtré au moment de l'exécution : non-régression
   BE-01 / BE-02 / BE-06. Seules les lignes TOTAL, FAIL et PASS/FAIL ont été
   conservées par la commande d'exécution ; les journaux complets de ces bancs
   n'ont pas été conservés. Ils ne sont ni reconstitués ni rejoués.
D. Déclaré dans un résumé, avec la sortie capturée dans MARIADB-SUMMARY.txt :
   mesures de journalisation binaire S1 à S5 (instance jetable supprimée depuis).
E. Non exécuté : PostgreSQL ; qualification réelle des privilèges sur LWS (OPS-02).
