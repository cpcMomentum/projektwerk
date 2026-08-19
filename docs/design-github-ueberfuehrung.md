# Design: GitHub-Überführung (einseitig) — Stufe 1

> Issue #12 · Post-MVP 1 · Produktbeschreibung §6 Punkt 1
> Entwurf, Design-Phase 2026-08-19. Noch kein Plan, noch kein Code.

## Problem Statement

Ein Vorgang aus einem Software-Board soll sich per Knopf **als GitHub-Issue anlegen**
lassen. Nummer und Link werden am Vorgang gespeichert, damit sichtbar ist, dass er
überführt wurde und wohin. **Kein Inhalts- oder Kommentar-Sync, keine Rückkopplung** —
die Rückmeldung „Issue geschlossen" ist bewusst Stufe 2a/2b und **nicht** Teil dieses
Designs. Die Grenze aus §10 bleibt: kein Feld-Merge, kein Zwei-Wege-Sync.

## Gewählter Ansatz: A — Stufe 1 schlank, Stufe 2 nur im Datenmodell mitgedacht

Einseitig **ProjektWerk → GitHub**, synchron beim Klick. Der Token liegt pro Person
verschlüsselt in Nextclouds `ICredentialsManager`; das Ziel-Repo hängt pro Board. Kein
Webhook-Endpunkt und keine Idempotenz-Tabelle in dieser Stufe — beides gehört zur
eingehenden Rückkopplung (Stufe 2) und würde hier nur toten Code erzeugen.

### Fundament, das schon liegt (aus der Ur-Migration `Version000001Date20260808000000`)

- `boards.github_enabled` (SMALLINT, default 0) — Entity + `toArray()` + TS-Typ kennen es,
  ist aber **noch nirgends verdrahtet** (kein Schalter, keine Nutzung).
- `tickets.github_issue_number` (BIGINT, nullable) + `tickets.github_issue_url` (STRING 4000,
  nullable) — im Entity, in `toArray()`, im TS-Typ `Ticket`.

### Was neu dazukommt

1. **Neue Board-Spalte `github_repo`** (STRING, nullable) — Ziel-Repo als `owner/repo`.
   Neue additive Migration. Entity + `toArray()` + TS-Typ `Board` + `settings#updateBoard`-
   Whitelist erweitern.
2. **Token-Ablage** über `OCP\Security\ICredentialsManager` — kein DB-Feld, keine Migration.
   NC verschlüsselt den Wert selbst. Identifier-Konstante, z. B. `github-token`.
3. **`GithubService`** (`lib/Service/GithubService.php`) — kapselt Token + API-Aufruf.
4. **Überführungs-Aktion** am Vorgang + Endpunkt + UI (Token-Feld, Board-Schalter/Repo).

### Architektur

**Backend**

- `lib/Service/GithubService.php` (neu)
  - `storeToken(string $userId, string $token): void` → `credentials->store($userId, self::TOKEN_ID, $token)`
  - `hasToken(string $userId): bool` / `deleteToken(string $userId): void`
  - `createIssue(string $userId, string $repo, string $title, string $body): array`
    → `IClientService::newClient()->post("https://api.github.com/repos/$repo/issues", …)`
    mit `Authorization: Bearer <token>`, `Accept: application/vnd.github+json`,
    JSON-Body `{title, body}`. Rückgabe `['number' => int, 'url' => string]` aus der Antwort.
    Fehler (401/404/422/Netz) werden als **eigene, sprechende Exception** hochgereicht
    (`GithubTransferException`), nie geschluckt.
- `TicketService::transferToGithub(ViewerContext $viewer, int $boardId, int $ticketId): Ticket`
  - Board laden, prüfen: `githubEnabled === 1` **und** `github_repo` gesetzt, sonst 400.
  - Vorgang über die **bestehende, leak-matrix-geprüfte Zugriffsschicht** laden (kein neuer
    Mapper-Query, der an der Sichtbarkeit vorbeiliest). Nur interne Mitglieder erreichen den
    Endpunkt ohnehin (Externe sehen die Aktion nicht und werden serverseitig abgewiesen).
  - **Doppel-Überführung sperren:** ist `githubIssueNumber` schon gesetzt → 409/no-op mit
    vorhandenem Link (die UI zeigt dann nur den Link, keine Aktion).
  - Body bauen: Beschreibung + Leerzeile + Rücklink
    `urls->linkToRouteAbsolute('projektwerk.deepLink.ticket', ['ticketId' => $id])`.
  - `GithubService::createIssue(...)` rufen. **Fail-closed:** Nummer + URL werden **erst
    nach HTTP 201** am Vorgang gespeichert; scheitert der Aufruf, bleibt der Vorgang
    unverändert und der Fehler geht als Meldung an die UI. `changeSeq`/`version` wie bei
    anderen Schreibvorgängen hochziehen.

**Controller / Routen**

- `TicketController::transferToGithub(int $boardId, int $ticketId)` →
  `POST /api/v1/boards/{boardId}/tickets/{ticketId}/github` (`#[NoAdminRequired]`).
- Per-User-Token, **board-unabhängig** — analog `PrivateFolderController`/`NotifyPrefController`,
  Grenze ist die Kennung aus der Sitzung:
  - `GET  /api/v1/my/github-token` → `{ present: bool }` (nie der Token selbst).
  - `PUT  /api/v1/my/github-token` (`{ token }`) → speichert, `{ present: true }`.
  - `DELETE /api/v1/my/github-token` → entfernt, `{ present: false }`.
  - Eigener `GithubTokenController` (neu) oder Erweiterung eines bestehenden per-User-Controllers.
- Repo + Schalter laufen über den **vorhandenen** `settings#updateBoard` (Whitelist um
  `githubEnabled`, `githubRepo` erweitern) — keine neue Board-Route nötig.

**Frontend**

- `src/services/github.ts` (neu) — `getTokenStatus()`, `setToken(t)`, `clearToken()`,
  `transferTicket(boardId, ticketId)`.
- `src/views/MySettingsView.vue` — neuer Block **„GitHub-Token"**: Passwort-Feld,
  Speichern/Entfernen, Status „hinterlegt". Kurzer Hilfetext: *fine-grained PAT mit
  Issues: read/write auf die Ziel-Repos*. Wir prüfen die Scopes nicht selbst; GitHub
  meldet fehlende Rechte beim Aufruf.
- Board-Einstellungen — **Schalter „GitHub-Anbindung"** + Feld **„Ziel-Repo (owner/repo)"**,
  nur sichtbar/relevant, wenn der Schalter an ist.
- Vorgangs-Detail — Aktion **„Nach GitHub überführen"** (im •••-Menü). Sichtbar nur wenn
  `board.githubEnabled && board.githubRepo && !ticket.githubIssueNumber` und der Betrachter
  ein internes Mitglied ist. Nach Überführung: **Link zum Issue** statt Aktion. Fehlt der
  Token, führt die Aktion zu einer klaren Meldung mit Verweis auf „Meine Einstellungen".

### Datenmodell

| Ort | Feld | Status |
|-----|------|--------|
| `boards.github_enabled` SMALLINT | An/Aus pro Board | **liegt schon** |
| `boards.github_repo` STRING nullable | `owner/repo` | **neu (Migration)** |
| `tickets.github_issue_number` BIGINT nullable | Rückverweis-Nummer | **liegt schon** |
| `tickets.github_issue_url` STRING nullable | Rückverweis-Link | **liegt schon** |
| `ICredentialsManager[userId, "github-token"]` | Token, verschlüsselt | **neu (kein DB-Feld)** |

### API-Design (GitHub, ausgehend)

`POST https://api.github.com/repos/{owner}/{repo}/issues`
Header: `Authorization: Bearer <PAT>`, `Accept: application/vnd.github+json`,
`X-GitHub-Api-Version: 2022-11-28`.
Body: `{ "title": "<Vorgangstitel>", "body": "<Beschreibung>\n\n<Rücklink>" }`.
Erfolg: `201` → `number`, `html_url` aus der Antwort am Vorgang speichern.

## Entscheidungen (aus der Design-Phase bestätigt)

- **Wer/Was:** jedes **interne** Mitglied, **jeder** Vorgang unabhängig von der Sichtbarkeit
  — das Ziel-Repo ist instanz-intern, kein Kundenzugriff. Externe Gäste sehen die Aktion nie.
- **Issue-Inhalt:** Titel + Beschreibung + Rücklink zum Vorgang.
- **Prüfzeitpunkt:** Token/Repo werden blind gespeichert; ein Fehler (401/404) fällt erst
  beim Überführen auf und wird als Meldung gezeigt. Weniger Code, weniger API-Aufrufe.
- **Token-Typ:** fine-grained PAT, Hinweis in der UI. Scopes prüfen wir nicht selbst.
- **Doppel-Überführung:** gesperrt; die UI zeigt dann nur den Link.
- **Fail-closed:** Rückverweis wird erst nach HTTP 201 persistiert.

## Verworfene Alternativen

- **B — Webhook-Fundament jetzt mitbauen** (HMAC-Endpunkt + `pwerk_gh_deliveries`): bereitet
  Stufe 2 vor, erzeugt aber ungenutzten Code und öffnet Scope-Creep. Kommt mit Stufe 2a.
- **C — ein App-weiter Token (appconfig) statt per-User:** widerspricht der Produktbeschreibung
  („eigener, verschlüsselt abgelegter Token") und verschleiert, wer das Issue angelegt hat.
- **Zwei-Wege-Feld-Sync (§10):** bleibt ausdrücklich verworfen — Konfliktfälle, deren Auflösung
  mehr Arbeit macht als das Problem.

## Offene Fragen

- Board-Einstellungen: Gibt es dafür schon eine View/Komponente, in die Schalter + Repo-Feld
  einziehen, oder muss der Ort erst benannt werden? (Im Plan zu klären, vor dem Bau.)
- Token-Endpunkt: eigener `GithubTokenController` vs. Anhängen an einen bestehenden per-User-
  Controller — Feinschliff im Plan.
- Schnitt in PRs: sinnvoll drei Häppchen — (1) Board-Repo-Feld + Migration + Settings-UI,
  (2) Token-Ablage + „Meine Einstellungen", (3) Überführungs-Aktion + Endpunkt + Detail-UI.

## Akzeptanzkriterien

- [ ] Internes Mitglied mit hinterlegtem Token kann einen Vorgang eines aktivierten Boards
      nach GitHub überführen; das Issue erscheint im hinterlegten Repo mit Titel, Beschreibung
      und funktionierendem Rücklink.
- [ ] Nummer + `html_url` stehen danach am Vorgang; die UI zeigt statt der Aktion den Link.
- [ ] Zweiter Überführungsversuch desselben Vorgangs wird abgewiesen (kein zweites Issue).
- [ ] Fehlender/ungültiger Token oder falsches Repo: klare Meldung, **kein** Rückverweis am
      Vorgang gespeichert, Vorgang unverändert.
- [ ] Externe Gäste sehen die Aktion nicht und werden serverseitig abgewiesen.
- [ ] Die Aktion fehlt, wenn das Board `githubEnabled=false` hat oder kein Repo gesetzt ist.
- [ ] Token wird nie im Klartext zurückgegeben; `GET .../my/github-token` liefert nur
      `{ present: bool }`.
- [ ] Leak-Matrix: die Überführung nutzt die bestehende, sichtbarkeitsgeprüfte Ticket-
      Zugriffsschicht; kein neuer ungefilterter Mapper-Query.
