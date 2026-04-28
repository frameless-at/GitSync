# SiteSync – Konzept

ProcessWire-Modul für branch-basiertes Deployment ganzer `/site/`-Verzeichnisse via GitHub. Schwestermodul zu [GitSync](https://github.com/frameless-at/gitsync), aber eigenständig.

---

## Vision

Entwickler ist unterwegs, sagt Claude Code per Smartphone „Ändere die Button-Farbe auf #aa22c7". Claude editiert `/site/templates/styles/main.css`, committet und pusht **auf einen Feature-Branch** (`claude/buttons-purple`). **Niemals direkt auf den deployten Branch.**

Der neue Commit erscheint im SiteSync-Admin in der Branches-Liste mit „updates available". Der Entwickler prüft auf GitHub den Diff. Wenn passt: ein Klick „Switch to claude/buttons-purple" → SiteSync deployt diesen Branch atomar ins `/site/`. Nächster Page-Refresh zeigt die neue Farbe.

Wenn etwas kaputt ist: zurück auf `live-stable` switchen. Site läuft sofort wieder.

**Kernidee: Push ≠ Deploy.** Claude erzeugt Vorschläge. Der Branch-Switch ist der bewusste Deploy-Akt. Branch-Protection auf `live-stable`/`main` (in GitHub konfiguriert) verhindert Direct-Push auf die deployte Linie.

---

## Scope

### Was das Modul tut
- Mappt **eine PW-Installation** auf **ein GitHub-Repo** (1:1, im Gegensatz zu GitSync n:n).
- Listet alle Branches des Repos im Admin – mit Status (active / updates available / behind).
- **Branch-Switch** = atomarer Deploy: aktuell aktiver Branch wird durch gewählten Branch ersetzt.
- Differenzialer Sync via Git-Blob-SHA-Vergleich (von GitSync übernommen).
- Webhook für Auto-Deploy bei Push auf den **aktiven** Branch.
- Pre/Post-Deploy-Hooks (Cache leeren, Modules-Refresh, optional Migrations).

### Was das Modul NICHT tut
- Keine DB-Migrationen. Felder/Templates-Definitionen liegen in der DB, nicht im Filesystem. Empfehlung: [RockMigrations](https://www.baumrock.com/en/processwire/modules/rockmigrations/) parallel nutzen, Migrations-Datei mitsynchen, Post-Hook führt sie aus.
- Kein Sync von `site/assets/` (Uploads, Cache, Sessions, Logs) – per Default ausgeschlossen.
- Kein Sync von `site/config.php` – Live-DB-Credentials bleiben unangetastet.
- Kein Git-Binary auf dem Server. Reine GitHub-REST-API (wie GitSync).

---

## Branch-Disziplin (Pflicht)

- **Aktiv deployter Branch** (z. B. `live-stable`) ist eine Schutzzone. Nur durch bewussten Switch oder Merge-via-PR änderbar.
- **Claude und andere Auto-Tools pushen IMMER auf Feature-Branches** (`claude/*`, `feature/*`, `staging`).
- **GitHub-Branch-Protection** auf `live-stable` und `main` ist Voraussetzung – kein Direct-Push, PR + Review erforderlich. SiteSync vertraut darauf.

### Empfohlene Repo-Struktur
```
main           ← kanonischer Entwicklungsbranch (PR-Target)
live-stable    ← aktuell deployt, geschützt
staging        ← Pre-Prod-Testbranch (optional eigenes SiteSync-Setup)
claude/*       ← Claude-generierte Vorschläge
feature/*      ← manuelle Feature-Branches
```

### Rollback-Strategie
- Jeder erfolgreich deployte Branch+Commit wird in der Switch-Historie protokolliert.
- „Rollback to previous" = One-Click-Switch auf vorherigen aktiven Branch+Commit.
- Optional: Snapshot-Backup vor jedem Switch (tar.gz von `/site/templates/` etc.) für Disaster-Recovery.

---

## Architektur

### Eigenständig, keine harte Dependency auf GitSync
- `'requires' => 'ProcessWire>=3.0.0'` – kein GitSync.
- Beide Module nebeneinander installierbar, ohne Konflikte.
- Eigener Webhook-Endpoint (`/sitesync-webhook/`), eigene Permission (`sitesync`), eigene Konfig.

### GitHub-Client wiederverwenden
**Strategie: Option A (Datei kopieren, umbenennen).**

`GitSyncGitHub.php` aus dem GitSync-Repo wird kopiert nach `SiteSync/SiteSyncGitHub.php`, Klasse umbenannt zu `SiteSyncGitHub`. ~5 Min Arbeit, dafür null Klassen-Kollision.

Begründung: Pragmatisch. Klasse ist ~750 Zeilen reiner REST-Client, ändert sich selten. Echte Library-Extraction (drittes Modul) wäre Overkill.

### UX/UI 1:1 von GitSync übernehmen
**Die UX und das UI von GitSync sind erprobt und perfekt – sie werden 1:1 für SiteSync übernommen.** Konkret:

- **Admin-Layout**: gleiche `MarkupAdminDataTable`-Struktur, gleiche Spalten-Pattern, gleiche Action-Links.
- **Branches-View**: identisch zu `executeBranches()` in GitSync (`GitSync.module.php:939-1051`) – Branch-Name, Last-Commit-SHA als Link, Datum, Status-Badge, Action-Button.
- **Status-Badges**: gleiche Farben (`#4CAF50` für „active/up to date", `#FF9800` für „updates available", `#1565C0` mit Bolt-Icon für Webhook-aktiv).
- **Webhook-Credentials-Modal**: gleiche UIkit-Modal-Struktur (`GitSync.module.php:486-493`), gleiche Felder (Payload URL + Secret), gleiches Copy-on-Click-Pattern.
- **Form-Pattern**: `InputfieldForm` + `InputfieldFieldset`, kollabierbar, gleiche Submit-Button-Stilistik mit fa-Icons.
- **Notice-Pattern**: `$this->message()` / `$this->warning()` / `$this->error()` mit `Notice::allowMarkup` wo nötig.
- **fa-Icons**: gleiches Vokabular (`fa-github`, `fa-code-fork`, `fa-download`, `fa-bolt`, `fa-check`, `fa-chain-broken`).
- **CSRF + Permission**: identisches Pattern (CSRF-Token in versteckten Forms, dedizierte Permission, Markdown-Notices für fehlende Konfig).
- **Rate-Limit-Anzeige** unten auf jeder API-Seite.
- **Setup-Page** unter „Setup > SiteSync" (statt „Setup > GitSync").

Die einzigen UI-Änderungen gegenüber GitSync sind die Konzept-Anpassungen:
- Hauptseite zeigt **eine Repo-Verbindung** statt einer Tabelle mit n Mappings.
- Action-Spalte enthält **„Switch"** statt „Sync" (semantisch korrekter für Branch-Wechsel).
- Zusätzliche **Switch-Historie** als zweite Tabelle unten („Last 10 deploys").
- Konfig-Sektion „Path Filters" mit Allowlist/Denylist-Editor.

---

## Datei-Struktur

```
SiteSync/
├── SiteSync.module.php        Process-Modul (Admin-UI + Switch-Logik)
├── SiteSyncConfig.php         Token, Webhook-Secret, Repo, Pfad-Filter, Hooks
├── SiteSyncGitHub.php         GitHub-REST-Client (Kopie aus GitSync, umbenannt)
├── SiteSyncDeploy.php         Atomic-Deploy-Engine (Staging-Dir, Lock, Snapshot)
├── SiteSync.js                Optional, falls AJAX-Branch-Refresh gebraucht wird
├── LICENSE                    MIT (wie GitSync)
└── README.md
```

---

## Konfig-Felder (`SiteSyncConfig.php`)

| Feld | Typ | Default | Zweck |
|---|---|---|---|
| `github_token` | text | `''` | GitHub Personal Access Token (fine-grained, read Contents). |
| `repo_url` | text | `''` | GitHub-Repo-URL (`https://github.com/owner/repo`). |
| `active_branch` | text | `''` | Aktuell deployter Branch. Wird durch Switch-Aktion gesetzt. |
| `webhook_secret` | text | `''` | HMAC-Secret für Webhook (auto-generiert beim Install). |
| `auto_deploy_on_push` | bool | `true` | Wenn `false`, ist Webhook nur Notification, jeder Deploy ist manueller Switch. |
| `protected_branches` | textarea | `live-stable\nmain\nproduction` | Branches, deren Switch eine Extra-Bestätigung braucht. |
| `path_allowlist` | textarea | (siehe unten) | Glob-Patterns relativ zu `/site/`. Nur diese werden synchronisiert. |
| `path_denylist` | textarea | (siehe unten) | Glob-Patterns. Werden IMMER ausgeschlossen, auch wenn allowlist matched. |
| `pre_deploy_hook` | text | `''` | Optional: PHP-File-Pfad (relativ zu `/site/`), wird vor Deploy ausgeführt. |
| `post_deploy_hook` | text | `''` | Optional: PHP-File-Pfad, wird nach Deploy ausgeführt (z. B. Migrations). |
| `enable_snapshots` | bool | `true` | Erstellt tar.gz von Templates/Modules vor jedem Switch. |
| `snapshot_retention` | int | `5` | Anzahl behaltener Snapshots. |

### Default-Allowlist
```
templates/**
modules/**
classes/**
ready.php
init.php
finished.php
*.php
```

### Default-Denylist (immer wirksam)
```
assets/**
config.php
config-*.php
install.php
.htaccess
sessions/**
logs/**
cache/**
backups/**
```

---

## Sync-Engine (`SiteSyncDeploy.php`)

### Atomic-Deploy-Algorithmus

```
1. Lock erwerben (file lock auf /site/assets/cache/SiteSync/.lock)
   → wenn vergeben: exit "deploy in progress"
2. Branch-Info + Tree-API → remote file map (path → blob SHA)
3. Path-Filter anwenden (allowlist + denylist)
4. Local file map bauen (gleicher SHA-Algo wie GitSync)
5. Diff bestimmen: toUpdate, toDelete
6. Optional Snapshot: tar.gz von /site/templates/, /site/modules/, /site/classes/
7. Pre-Deploy-Hook ausführen (falls konfiguriert)
8. Atomic write:
   a) Geänderte Dateien zuerst nach /site/<path>.sitesync-tmp schreiben
   b) Wenn alle ok: rename() jedes tmp → endgültig (ist atomic auf POSIX)
   c) Bei Fehler: alle .sitesync-tmp löschen, abort
9. Löschungen ausführen (nur was in allowlist UND nicht in denylist)
10. Empty dirs aufräumen
11. PW caches: $modules->refresh(), $cache->maintenance()
12. Post-Deploy-Hook ausführen
13. Switch-Historie aktualisieren (vorheriger Branch+Commit als rollback-target)
14. active_branch + last_commit_sha persistieren
15. Lock freigeben
```

### Differenzialer Sync (übernommen aus GitSync)
Identische Engine wie `GitSync::performSync()` (`GitSync.module.php:1127-1257`), aber:
- Operiert auf `/site/` statt `/site/modules/X/`.
- Pfad-Filter VOR dem Diff anwenden – nicht erlaubte Pfade landen weder in toUpdate noch in toDelete.
- Löschungen nur innerhalb der Allowlist (nie außerhalb des Sync-Scope).

### Rollback
- Switch-Historie speichert pro Deploy: timestamp, branch, commit-sha, snapshot-pfad.
- „Rollback" = Switch zurück auf vorherigen Branch+Commit (regulärer Deploy mit anderen Targets).
- „Restore from snapshot" (Notfall): tar.gz extrahieren, dann normaler Deploy als Sync-Punkt.

---

## Webhook-Flow

### Endpoint: `/sitesync-webhook/`
Kopiert aus GitSync (`GitSync.module.php:194-319`), aber:
- Reagiert nur auf Pushes des **konfigurierten Repos**.
- **Auto-Deploy nur**, wenn `ref` == `active_branch` UND `auto_deploy_on_push == true`.
- Pushes auf andere Branches: HTTP 200 + Log + Notification (kein Deploy).

### Sicherheit
- HMAC-SHA256-Verifikation via `X-Hub-Signature-256` (identisch GitSync).
- HTTP 403 bei fehlender/ungültiger Signatur.
- Rate-Limit auf Modul-Ebene: max 1 Deploy / 30s pro Branch (verhindert Push-Storms).

---

## Sicherheit

### Übernommen aus GitSync
- HMAC-Webhook-Verifikation.
- CSRF-Schutz auf allen state-changing Admin-Aktionen.
- Path-Traversal-Check (`..` und führendes `/` blockieren).
- Dedizierte Permission `sitesync`.
- Token in PW-Modul-Config (DB), nicht im Filesystem.

### Zusätzlich für SiteSync
- **Path-Allowlist/Denylist** – verhindert versehentliches Überschreiben von `config.php`, `assets/`, etc.
- **Lock-File** gegen parallele Webhook-Syncs.
- **Atomic Writes** via tmp+rename – kein Halbzustand bei laufenden Requests.
- **Snapshot vor Switch** – Disaster-Recovery wenn Sync kaputt geht.
- **Active-Branch-Schutz** – Push auf `protected_branches` triggert Warnung in Admin (auch wenn Switch initial konfiguriert wurde, signalisiert es ungewöhnliche Aktivität).

---

## Branch-Switch-Flow (Kernfeature)

### UI (1:1 GitSync-Branches-View)
Tabelle mit allen Branches, Spalten:
| Branch | Last Commit | Date | Status | Action |
|---|---|---|---|---|
| **live-stable** ✓ | `a3f2b1c` | 2026-04-28 14:32 | 🟢 active | (current) |
| claude/buttons-purple | `e8d9c0f` | 2026-04-28 16:01 | 🟠 not deployed | **Switch** |
| feature/footer-redesign | `b1a2c3d` | 2026-04-26 09:14 | 🟠 not deployed | **Switch** |

### Switch-Aktion
1. User klickt „Switch" → Confirm-Dialog mit Diff-Stats („27 files updated, 3 files deleted; snapshot will be created").
2. Bei `protected_branches`: zusätzliche Bestätigung („This is a protected branch. Continue?").
3. POST an `/admin/setup/sitesync/switch/` (CSRF-validiert).
4. `SiteSyncDeploy::deploy(targetBranch)` läuft.
5. Bei Erfolg: Redirect zu Branches-View, `$this->message("Switched to {branch}. Snapshot: {path}")`.
6. Bei Fehler: `$this->error()` mit Stack-Trace im Log.

### Switch-Historie (zweite Tabelle auf Hauptseite)
| Zeitpunkt | Von | Auf | Commit | Snapshot | Aktion |
|---|---|---|---|---|---|
| vor 2 Min | live-stable | claude/buttons-purple | `e8d9c0f` | snap-2026-04-28-1601.tar.gz | **Rollback** |

---

## Bekannte Limitierungen

1. **Datei ≠ Datenbank.** PW-Felder, Templates-Definitionen, Seitenbaum sind in der DB. SiteSync syncht nur Filesystem. Lösung: RockMigrations (oder ähnliches) parallel, Migration als File mitsyncen, Post-Hook führt sie aus.
2. **Tree-API-Limit** (100k Files). Bei normalen Sites kein Problem.
3. **Konkurrierende Pushes** während laufendem Deploy werden gequeued (Lock-File). Im Extremfall Race-Condition, akzeptabel.
4. **`assets/` divergiert** zwischen Dev und Prod (Uploads). Per Design ausgeschlossen.
5. **Self-Update** (SiteSync syncht sich selbst, falls in `site/modules/` liegt) funktioniert, kann aber Page-Reload erfordern (wie bei GitSync).

---

## MVP-Roadmap

### Phase 1 – Core (2 Tage)
- [ ] Modul-Skeleton mit `getModuleInfo`, Permission, Setup-Page-Mount.
- [ ] `SiteSyncGitHub.php` aus GitSync kopieren + umbenennen.
- [ ] Konfig-Page mit Token, Repo-URL, Webhook-Secret-Generierung.
- [ ] Hauptseite mit Repo-Status (1:1 GitSync-Stil).
- [ ] Branches-View (1:1 GitSync, Action: „Switch" statt „Sync").
- [ ] `SiteSyncDeploy::deploy()` ohne Atomic-Writes (erst mal direkt schreiben).
- [ ] Path-Allowlist/Denylist implementieren.

### Phase 2 – Safety (1–2 Tage)
- [ ] Atomic Writes via tmp+rename.
- [ ] Lock-File.
- [ ] Snapshot vor Switch + Retention.
- [ ] Switch-Historie + Rollback-Action.

### Phase 3 – Webhook (1 Tag)
- [ ] Webhook-Endpoint aus GitSync portieren (`/sitesync-webhook/`).
- [ ] `auto_deploy_on_push` Logik.
- [ ] Webhook-Credentials-Modal (1:1 GitSync).

### Phase 4 – Hooks & Polish (1 Tag)
- [ ] Pre/Post-Deploy-Hooks.
- [ ] Rate-Limit-Anzeige.
- [ ] README, Screenshots.

**Gesamt: ~5–6 Tage MVP.** Engine-Logik und UI-Pattern aus GitSync sparen ~60% der Arbeit.

---

## Kickoff-Prompt für neue Session

Wenn du in einer neuen Session in diesem Repo loslegst, gib Claude folgenden Prompt:

```
Wir bauen SiteSync, ein ProcessWire-Modul für branch-basiertes Deployment
ganzer /site/-Verzeichnisse via GitHub. Konzept liegt in SITESYNC_CONCEPT.md.
Das GitSync-Modul (Schwesterprojekt) ist in diesem Repo geklont und dient
als Vorlage für UX/UI und als Quelle für SiteSyncGitHub.php.

WICHTIG:
- UX/UI 1:1 von GitSync übernehmen. Sie ist erprobt und perfekt.
  Layouts, Tabellen, Modals, Badges, fa-Icons, Form-Patterns – alles wie
  GitSync, nur Action „Switch" statt „Sync" und ein Repo statt n Module.
- Pushes von Auto-Tools (Claude) gehen IMMER auf Feature-Branches
  (claude/<task> oder feature/<name>), NIEMALS direkt auf den aktiv
  deployten Branch (live-stable/main).
- Branch-Protection auf live-stable/main ist Voraussetzung.
- Path-Denylist (assets/, config.php, etc.) ist NICHT optional.

Starte mit Phase 1 der Roadmap: Modul-Skeleton, GitHub-Client kopieren,
Konfig-Page, Hauptseite + Branches-View nach GitSync-Vorbild.
Für UI-Patterns: GitSync.module.php als Referenz.
```

---

## Referenz-Dateien aus GitSync (als Vorlage)

| Datei | Zeilen | Was übernehmen |
|---|---|---|
| `GitSyncGitHub.php` | komplett | Kopieren, Klasse umbenennen zu `SiteSyncGitHub` |
| `GitSync.module.php:186-204` | Webhook URL Hook | Endpoint anpassen auf `/sitesync-webhook/` |
| `GitSync.module.php:209-319` | Webhook Handler | Repo-Match-Logik vereinfachen (nur 1 Repo) |
| `GitSync.module.php:363-506` | Hauptseite (UI) | Auf 1-Repo-Layout umbauen, Switch-Historie ergänzen |
| `GitSync.module.php:939-1051` | Branches-View | 1:1 übernehmen, Sync-Button → Switch-Button |
| `GitSync.module.php:1127-1257` | performSync | Als Basis für `SiteSyncDeploy::deploy()`, Atomic-Layer ergänzen |
| `GitSync.module.php:1273-1295` | buildLocalFileMap | 1:1, mit Path-Filter erweitert |
| `GitSync.module.php:1359-1427` | Permission-Checks | 1:1 übernehmen |
| `GitSyncConfig.php` | komplett | Als Vorlage, Felder gemäß Konfig-Tabelle erweitern |

---

## Lizenz

MIT – konsistent mit GitSync.
