# Implementierungsplan: #246 „Mehrere Boards pro Projekt"

*Erstellt: 2026-09-01 · Linse: Risk-first (Panel-Sieger), veredelt mit den besten Ideen der Runner-ups*
*Grundlage: `docs/produktbeschreibung.md`, `CLAUDE.md`, verifizierter Code-Stand (v0.4.9 info.xml / 0.4.4 package.json)*
*Verbindlich vor Umsetzung: `docs/nextcloud-fallstricke.md`*

---

## 1. Übersicht

### Problem

Ein Projekt fasst heute genau **ein** Board. Die Board-Zeile (`pwerk_boards`) trägt in Wahrheit zwei Dinge in einem: die **Kunden-Engagement-Ebene** (Owner, geteilte Ordner, Chat, Zähler, Mitglieder, Archiv) und die **Kanban-Ebene** (Titel, Spalten, Karten). #246 verlangt, dass ein Projekt **mehrere Boards** umfasst — z. B. „Entwicklung", „Marketing" innerhalb desselben Kundenprojekts.

Axel hat den Rahmen bewusst eng gezogen: **Mitglieder UND Sichtbarkeit sind über alle Boards eines Projekts identisch.** Damit ist #246 keine Zugriffs-Neuerfindung, sondern eine Verschiebung der **Herkunft der Rolle** von „pro Board" auf „pro Projekt", plus eine zusätzliche Navigationsebene.

### Das zu schützende Gut

Der gesamte Produktwert hängt an einem Satz aus `CLAUDE.md`: *„Sichtbarkeit ist EINE Bedingung an EINER Stelle."* Diese Stelle ist `TicketScope::apply()` (`lib/Access/TicketScope.php`). Sie ist heute per `INNER JOIN pwerk_members m ON m.board_id = t.board_id AND m.user_id = :uid` gebaut, bewacht durch die Leak-Matrix, den `ArchitectureTest` (nur `TicketScope` + `TicketMapper` dürfen `pwerk_tickets` nennen), den ReadPath-Wächter (`tests/ReadPathRegistry.php`) und die Kein-Admin-Tests.

Ein **Confidentiality-Leak** — die Kundenseite sieht ein internes Ticket — ist der **einzige irreversible** Schaden im ganzen System. Alle anderen Fehler (ein Mitglied sieht sein Board nicht) sind reparierbare Verfügbarkeitsfehler.

### Leitidee (Risk-first)

Die eine gefährliche Änderung (der JOIN-Schlüssel im Kronjuwel) wird **isoliert, verhaltensneutral und bewiesen**, und sie geht live, **bevor** überhaupt ein zweites Board existieren kann. Zum Zeitpunkt der riskanten Änderung ist der Blast-Radius null: jedes Projekt hat noch genau ein Board, also ist die Umstellung von `board_id` auf `project_id` ein reines Refactoring, das auf den Bestands-Fixtures **und** einer synthetischen Zwei-Board-Fixture grün bleiben muss.

### Präzise Sprachregelung für die Vorgabe „TicketScope unverändert"

Unverändert bleibt die **Sichtbarkeits-REGEL** (das `orX()`-Publikum-Prädikat: public / internal+`creator_role = m.role` / private+`creator_user_id`), byte-für-byte. Was sich verschiebt, ist der **Schlüssel des Mitgliedschaftsverbunds**: von `m.board_id = t.board_id` auf `m.project_id = t.project_id`. Genau das heißt „die Rolle kommt jetzt aus der Projekt-Mitgliedschaft". Ein Weg, bei dem der JOIN buchstäblich unangetastet bliebe, existiert nur über Fan-out (siehe Verworfene Alternativen), und der ist unsicherer.

---

## 2. Tech-Stack

Kein neuer Baustein. #246 ist eine Datenmodell-/Migrations- und Navigationsänderung; **keine neue Bibliothek, kein erzwungenes Upgrade, keine neue App-Abhängigkeit** (`<dependencies>` bleibt ohne `<app>`-Element). Alle Versionen unten sind Context7-/npm-registry-verifiziert (Stand 2026-09-01).

> **Fallback-Hinweis:** Context7-MCP war nur teilweise verbunden (`resolve-library-id` verifiziert die Library-IDs, `get-library-docs` war in dieser Umgebung nicht verfügbar). Die verbindlichen aktuellen Versionsstände stammen daher aus der primären autoritativen Quelle **npm registry** (`registry.npmjs.org/<pkg>/latest`).

### Frontend

| Library | Projekt-Range | Verifiziert aktuell (npm) | Empfehlung für #246 |
|---|---|---|---|
| vue | ^3.5.13 | 3.5.42 | **Keep** — nur Patches/Minors |
| vue-router | ^5.2.0 | **5.3.0** | **Keep** — trägt die verschachtelten Routen Überblick→Projekt→Board→Kanban nativ; SPA nutzt `createWebHashHistory()` |
| pinia | ^4.0.2 | 4.0.3 | **Keep** — neuer `projects`-Store additiv |
| @nextcloud/vue | ^9.5.0 | 9.11.0 | **Keep** — bei nächstem `npm install` optional auf 9.11 |
| vite | ^8.2.0 | 8.2.2 | **Keep** |
| vitest | ^4.1.4 | 4.1.11 | **Keep** |
| vue-draggable-plus | 0.6.1 (pin) | 0.6.1 | **Keep** — bewusst gepinnt, DnD-kritisch |
| @playwright/test | ^1.62.1 | 1.62.1 | **Keep** |
| eslint | ^10.8.0 | 10.9.1 | **Keep** |
| typescript | ^5.7.2 | 7.0.2 | **Keep für #246** — TS5→7 ist ein eigenständiger Tooling-Task, darf den Datenmodell-Umbau NICHT mitziehen |

### Backend

| Baustein | Projekt | Empfehlung |
|---|---|---|
| PHP-Floor | `>=8.3` | **Keep** — durch AIO-Produktivinstanz (NC 34 / PHP 8.3.32) erzwungen, nicht auf 8.4 anheben bis AIO liefert |
| nextcloud/ocp | `^33 \|\| ^34` | **Keep** — deckt `min-version="33" max-version="34"` |
| phpunit | ^12.0 | **Keep** |
| OCP Migration API (`SimpleMigrationStep` / `ISchemaWrapper`) | — | **Keep** — trägt `pwerk_projects` + additive `project_id`-Spalten, keine neue Abhängigkeit |
| NC Query Builder | — | **Keep** — projekt-scoped Rollenauflösung bleibt reiner Query Builder |

### Constraint-Check für #246

- **Nur `OCP\*`** — Migration + Query Builder + Mapper decken den gesamten Umbau. ✓
- **`pwerk_`-Präfix / 22-Zeichen-Limit:** `pwerk_projects` (14 Zeichen) unkritisch. ✓
- **Enum ASCII/englisch:** kein neuer Enum durch #246 erzwungen. ✓
- **DB-Boolean = `Types::SMALLINT` (0/1)**, nie `Types::BOOLEAN notnull`; NC bricht bei NOT-NULL-Neuspalten ab. ✓
- **`<database>`-Deklaration** bleibt (Oracle-Namenslimit-Lehre rechnungswerk #118). ✓

---

## 3. Architektur

### 3.1 Feld-Eigentümerschaft: „Projekt besitzt geteilten Kontext, Board besitzt Kanban"

Der Umbau folgt einer einzigen sauberen Trennung. Sie erzeugt die drei Unter-Entscheidungen (Ordner/Nummern/Chat) fast mechanisch.

| Feld (heute auf `pwerk_boards`) | Zielort | Begründung |
|---|---|---|
| `owner_user_id` | **Projekt** | Owner ist die Engagement-Ebene (§8) |
| `org_internal`, `org_external` | **Projekt** | Organisationsnamen der beiden Seiten |
| `folder_public_id` / `folder_public_path` | **Projekt** | ein Kunde = ein geteilter Ordner `90_Austausch` |
| `folder_internal_id` / `folder_internal_path` | **Projekt** | `91_Tickets_intern` je Engagement |
| `chat_url` | **Projekt** | ein Team = ein Talk-Raum |
| `ticket_counter` | **Projekt** | projektweit fortlaufende Nummern (Dateinamen-Namensraum) |
| `archived` | **Projekt** | ein Projekt = eine Engagement (Archiv blendet Projekt aus) |
| Mitgliedschaft (`pwerk_members`) | **Projekt** | Rolle genau einmal je Projekt |
| `title`, `description` | **Board** | jedes Board hat eigenen frei pflegbaren Titel |
| `position` (neu) | **Board** | Reihenfolge der Boards im Projekt |
| `github_enabled`, `github_repo` | **Board** | bleibt pro Board (§6.1) |
| `change_seq` | **Board** | Delta-Poll / `notify_push` hängen je Board daran (§3.8) |
| `created_at`, `updated_at` | **beide** (je eigene Zeile) | — |

Physische/identitätstragende Aggregate, die je Board zählen (`change_seq`), und die GitHub-Kopplung (`github_enabled`/`github_repo`) bleiben bewusst board-scoped. Alles Organisatorische, das für die ganze Engagement gilt, wandert aufs Projekt.

### 3.2 Datenmodell

**Neu — `pwerk_projects`:** `id`, `title`, `description`, `owner_user_id`, `org_internal`, `org_external`, `folder_public_id`, `folder_public_path`, `folder_internal_id`, `folder_internal_path`, `chat_url`, `ticket_counter` (INTEGER), `archived` (SMALLINT 0/1), `created_at`, `updated_at`.

**`pwerk_boards`** (behält/erhält): `id`, `project_id` (neu, INTEGER), `title`, `description`, `position` (neu, INTEGER), `github_enabled` (SMALLINT), `github_repo`, `change_seq` (INTEGER), `created_at`, `updated_at`. Die auf `pwerk_projects` gewanderten Spalten werden nach dem Backfill entfernt.

**`pwerk_members`:** `board_id` → **`project_id`**; eindeutiger Index über (`project_id`, `user_id`). Die Rolle jeder Person steht damit **genau einmal je Projekt** und kann sich nicht per Board widersprechen. Übrige Felder unverändert (`user_id`, `role`, `is_manager`, `display_name`, `added_by`, `added_at`).

**`pwerk_tickets`:** erhält **`project_id`** (INTEGER, **unveränderlich**, beim Insert aus dem Board gesetzt), behält `board_id` / `column_id` / `number`. Der eindeutige Index wandert von (`board_id`, `number`) auf (`project_id`, `number`). Alle sichtbarkeitstragenden Felder (`visibility`, `creator_role`, `creator_user_id`, `deleted_at`) bleiben unverändert.

**`pwerk_columns`** bleibt board-scoped (`board_id`) — Spalten sind die Phasen eines konkreten Boards.

### 3.3 Der Kronjuwel-JOIN (die eine load-bearing Änderung)

`TicketScope::apply()` ändert **genau eine** Zeile — den Verbundschlüssel:

```
// vorher
$qb->expr()->eq($m . '.board_id', $ticketAlias . '.board_id')
// nachher
$qb->expr()->eq($m . '.project_id', $ticketAlias . '.project_id')
```

Das `orX()`-Publikum-Prädikat (`.visibility` public/internal/private, `.creator_role = m.role`, `.creator_user_id = :uid`), `isNull($ticketAlias . '.deleted_at')` und der optionale `$ticketAlias . '.board_id' = :boardId`-Einzelboardfilter bleiben **byte-identisch**. Der JOIN bleibt **einspaltig** — genau deshalb wird `project_id` auf `pwerk_tickets` **denormalisiert** (statt über die Boards-Tabelle zu joinen): das Ticket wechselt kein Board, das Board kein Projekt, also ist der Wert unveränderlich und der Verbund korrelierte-Unterabfrage-tauglich wie heute.

`$boardId` bleibt der reine **View-Filter** für die Kanban-Sicht; für das Projekt-Dashboard kommt ein optionaler `project_id = :projectId`-Filter dazu — beide als **Post-Gate-Filter auf derselben `apply()`**, kein zweiter Sichtbarkeitsentscheid. So teilen sich Kanban, „Meine Aufgaben" und Projekt-Dashboard **einen** Lesepfad.

### 3.4 Rollenauflösung in `BoardAccess`

`BoardAccess::contextFor(userId, boardId)` löst Board→Projekt und Mitgliedschaft in **einer** Abfrage:

```
SELECT b.project_id, m.role, m.is_manager
FROM pwerk_boards b
INNER JOIN pwerk_members m ON m.project_id = b.project_id AND m.user_id = :uid
WHERE b.id = :boardId
```

Ein Nichtmitglied bekommt null Zeilen → `NotAMemberException`, mit identischer leak-sicherer Semantik (die Fehlerform verrät nicht, ob das Board existiert). `BoardMapper::find(int $id)` erscheint weiterhin **nirgends**. `forMember(` bleibt die einzige Tür, weiter nur in `BoardAccess`, weiter vom `ArchitectureTest` bewacht. `ViewerContext` trägt künftig **projectId und boardId** (Rolle projekt-scoped, boardId für die konkret betrachtete Ansicht).

### 3.5 Zähler / Nummernvergabe

`claimTicketNumber` läuft künftig auf `pwerk_projects` statt auf dem Board: atomares `UPDATE pwerk_projects SET ticket_counter = ticket_counter + 1 WHERE id = :projectId`, dann Lesen. Der `change_seq`-Vorschub bleibt getrennt am Board (`UPDATE pwerk_boards SET change_seq = change_seq + 1 WHERE id = :boardId`), weil der Delta-Poll je Board zählt. Der eindeutige Index (`project_id`, `number`) ist die eigentliche Garantie gegen Nummerndubletten; die atomare Vergabe macht den Normalfall schnell.

### 3.6 Betroffene Lesepfade (`lib/Db/`) — alle über `scopedQuery()` → `TicketScope`

Diese Methoden funneln bereits durch `TicketScope::apply()`; mit dem JOIN-Schlüsselwechsel sind sie automatisch projekt-korrekt. Kein zweiter Lesepfad entsteht:

- `TicketMapper::findVisibleInBoard`, `findVisible`, `findVisibleAnywhere` (Deep-Link), `findVisibleAcrossBoards`, `findVisibleAcrossBoardsAll`, `findVisibleWithMyOpenSteps`, `countVisibleInBoard`.
- **Dashboard-Aggregate** `countClosedByBoard`, `countInWindow`, `findTimestampsInWindow`, `countNewByBoard`: nehmen **bereits** ein `boardIds[]`-Array. Für ein Projekt-Dashboard über mehrere Boards genügt es, alle Board-IDs des Projekts zu übergeben — die Aggregation ist schon board-set-förmig gebaut. Keine Signaturänderung der Sichtbarkeitslogik nötig.
- **Ausnahme `findForRestore`** (bewusst am Scope vorbei, #167): bleibt auf **Board + eigene Rolle** eingeschränkt. Restore ist board-lokal; das ändert sich mit #246 nicht.

`BoardMapper::findAllForUser` (INNER JOIN Members) wird auf `m.project_id = b.project_id` umgestellt; neu `BoardMapper::findAllForProjectForViewer()` (Boards-Liste eines Projekts, membership-gated über `project_id`) — **kein** `find(id)`. Die `MemberMapper`-Methoden (`findForBoard`, `findForUserBoards`, `rolesForUser`) wechseln von `board_id` auf `project_id`.

### 3.7 Navigation

Vier Ebenen über verschachtelte `vue-router`-Routen (SPA weiter Hash-Mode `createWebHashHistory()`):

```
overview  → /project/:projectId          (Projekt-Dashboard #227, aggregiert über ALLE Boards)
          → /project/:projectId/board/:boardId   (Kanban)
```

Die heutigen Routen (`/project/:boardId` als Dashboard, `/boards/:boardId` als Board) keyen auf `boardId`, weil heute 1 Board = 1 Projekt. Sie werden auf `projectId`/`boardId` umgestellt; das Gäste-Gate (`gateTarget`, #234) leitet einen rein externen Betrachter bei einem Projekt aufs Board, bei mehreren auf die Projektliste.

**Deep-Link bleibt fragmentfrei:** Die Server-Route `deepLink#ticket` (`/t/{ticketId}`) nutzt die **Ticket-ID** (nicht die Anzeigenummer), löst Ticket → Board → Projekt auf, prüft die (jetzt projekt-scoped) Mitgliedschaft über `findVisibleAnywhere` und legt das Ziel in den Initial State. Weil der Link ID-basiert ist, bricht die projektweite Umnummerierung **keinen** Deep-Link.

`ProjectDashboardView.vue` ist bereits board-agnostisch angelegt; der Store lädt künftig die Boards-Liste des Projekts und vereinigt Tickets/Spalten (die normalisierte `Map<id, Ticket>`-Struktur trägt das ohne Umbau).

### 3.8 Die drei gekoppelten Unter-Entscheidungen

1. **Ordner pro Projekt.** Die CPC-Realstruktur ist projektweise (`90_Austausch` ist DER geteilte Kundenordner der Engagement). `folder_*_id/path` wandern auf `pwerk_projects`. Ein Projekt = ein Kunde = ein geteilter Ordner; pro Board würde den Kundenaustausch fragmentieren und die manuelle Freigabe (§5.19) vervielfachen. **Bedingung:** flache Dateinamen `0042_...` im geteilten Ordner müssen kollisionsfrei sein → verlangt projektweite Nummern (Entscheidung 2). `RelocateAttachments` (bestehender Repair-Step, info.xml) bleibt der Sicherungsanker für abgebrochene Umzüge.

2. **Vorgangsnummern projektweit fortlaufend.** `ticket_counter` auf `pwerk_projects`, Index (`project_id`, `number`). Hält Dateinamen im gemeinsamen Ordner kollisionsfrei; „Vorgang 42" ist projektweit eindeutig. **Invariante:** *Nummer eindeutig = Dateiname/Direktlink eindeutig.* Ehrliche Kosten: die atomare Vergabe serialisiert die Ticket-Anlage über alle Boards eines Projekts (Sperre auf Projekt- statt Board-Zeile); bei CPC-Last vernachlässigbar. Zu §5.11: Nummernlücken spannen jetzt das Projekt statt das Board — da die Mitgliedschaft projektweit identisch ist, ist die Kundenseite ohnehin Mitglied aller Boards und sieht sie. **Kein neuer Existenz-Leak.**

3. **Chat-Link pro Projekt.** `chat_url` auf `pwerk_projects`; der Knopf „Zum Projektchat" erscheint in jeder Board-Kopfzeile und zeigt auf denselben Raum. **Reversibel gehalten:** kommt später ein Bedarf pro Board, lässt sich eine **nullable board-seitige `chat_url`-Übersteuerung** nachrüsten (Board-Wert schlägt Projekt-Wert), ohne Migrationsschmerz. **Doku-Konflikt (ehrlich):** §6.2 der Produktbeschreibung sagt „genau ein Talk-Gespräch je Board" — geschrieben, bevor Multi-Board existierte. Empfehlung: §6.2 auf „ein Gespräch je Projekt" nachziehen (Freigabe nötig, siehe Offene Entscheidungen).

### 3.9 Testarchitektur (die Verteidigung des Kronjuwels)

- **Leak-Matrix (`tests/ReadPathRegistry.php` + Matrix-Test):** um eine **synthetische Zwei-Board-pro-Projekt-Fixture** erweitert, **bevor** die UI ein zweites Board anlegen kann. So laufen die Confidentiality-Tests der Feature voraus, die sie später ausüben würde.
- **ReadPath-Wächter:** neue Mapper-Lesepfade (`BoardMapper::findAllForProjectForViewer`) in Read-Prefix + `MAPPER_PATHS` + LeakMatrix-COVERAGE; **UNIT-Suite via `tests/phpunit.xml` lokal fahren**, nicht nur Integration.
- **ArchitectureTest:** unverändert scharf — `pwerk_tickets` nur in `TicketScope` + `TicketMapper`, `forMember(` nur in `BoardAccess`.
- **Write-Time-Wächter (neu, aus Runner-up-Idee):** der Wächter schlägt an, wenn ein `pwerk_tickets`-INSERT **ohne korrektes `project_id`** erfolgt — fängt den Leak beim Schreiben statt erst beim Lesen. Das schützt die Unveränderlichkeits-Invariante von `tickets.project_id`.
- **Kein-Admin-Test:** unverändert, jetzt projekt-scoped.
- **e2e (`tests/e2e/`, nur in CI):** bei diesem Verhaltens-Change ALLE Specs greppen; leeres HTTP 429 ist die App-Drossel (bekannt).

---

## 4. Phasen-Übersicht

Gestaffelte, einzeln bewiesene PRs. **Dark-Launch bis PR 5**: jede Stufe ist ausschließbar, die App bleibt an jedem Punkt lauffähig und deploybar (Produktivinstanz v0.4.x, nc.cpcmomentum.com). Die riskante Access-Umstellung (PR 2) ist hinter einem vollen Leak-Matrix-Rerun über die synthetischen Multi-Board-Fixtures isoliert.

### PR 0 — Reversibler Spike: `pwerk_projects` + `boards.project_id`
- Migration: neue Tabelle `pwerk_projects` (nullable Neuspalten, im selben Schritt befüllt), `boards.project_id` nullable + befüllt. Backfill: **je Board ein Projekt** (Owner/Ordner/`chat_url`/`ticket_counter`/`org_*`/`archived` kopiert), deterministisch, weil heute 1:1.
- **Kein Lesepfad geändert.** Leak-Matrix per Konstruktion grün.
- Beweist Migration + Backfill auf der Produktivinstanz ohne Sichtbarkeitsrisiko.
- Rücknahme = ungenutzte Spalte fallen lassen.

### PR 1 — `tickets.project_id` (unveränderlich, aus Board befüllt), noch ungenutzt
- Migration: `tickets.project_id` nullable + befüllt aus dem 1:1-Bezug; Index (`project_id`, `number`) additiv anlegen (bleibt eindeutig, weil je Projekt genau ein Board).
- Leak-Matrix um die **synthetische Zwei-Board-Projekt-Fixture** erweitert (UI kann das noch nicht, der Test schon). Write-Time-Wächter für `project_id` beim INSERT scharf schalten.

### PR 2 — Die riskante, isolierte Access-Umstellung
- `pwerk_members.board_id` → `project_id` (Migration inkl. Backfill über den 1:1-Bezug, Index (`project_id`, `user_id`)).
- `TicketScope`-JOIN auf `project_id`; `BoardAccess::contextFor` als **eine** Board→Projekt+Membership-Abfrage; `ViewerContext` trägt `projectId`; `MemberMapper`/`BoardMapper`/Zähler mit.
- **Reines verhaltensneutrales Refactoring** (jedes Projekt hat noch genau ein Board): die Matrix muss auf Bestands-Fixtures **und** der Multi-Board-Fixture grün bleiben. Voller ArchitectureTest + ReadPath-Wächter + Kein-Admin-Test.

### PR 3 — Membership-Schreib-API projekt-scoped
- `MemberService` operiert auf dem Projekt; Routen `/boards/{boardId}/members*` lösen boardId→projectId auf (oder werden auf `/projects/{projectId}/members*` gezogen — Detail in der Umsetzung).
- UI zeigt weiter ein Board je Projekt. **Keine neue Leak-Fläche.**

### PR 4 — `ticket_counter` + Ordner + Chat aufs Projekt ziehen
- `claimTicketNumber` auf `pwerk_projects`; `folder_*`, `chat_url`, `org_*`, `owner_user_id`, `archived` final auf `pwerk_projects`, die alten Board-Spalten entfernen.
- `RelocateAttachments` gegen die projekt-scoped Ordner prüfen.

### PR 5 — Zweites Board sichtbar machen (die einzige nutzersichtbare Änderung)
- `BoardController` + Projekt-Service + Board anlegen/umbenennen/`position`; Navigation Überblick→Projekt→Board→Kanban; Projekt-Dashboard #227 aggregiert über alle Board-IDs des Projekts (Aggregat-Methoden sind bereits board-set-förmig).
- Erst **hier** existiert real ein Projekt mit >1 Board — jede Schicht ist da bereits korrekt.

---

## 5. Entscheidungen (bestätigt von Axel, 2026-09-01)

Alle fünf Punkte sind bestätigt (Axel, 2026-09-01) — die Begründungen bleiben als Festlegung stehen:

1. **§6.2 nachziehen** auf „ein Talk-Gespräch je Projekt" (Konsequenz aus Chat-Entscheidung). Freigabe der Doku-Änderung durch Axel nötig, bevor PR 4 die Board-`chat_url` entfernt.
2. **`archived` auf Projekt-Ebene** (Empfehlung): ein Projekt = eine Engagement, der Überblick blendet archivierte Projekte aus. Ein einzelnes Board innerhalb eines aktiven Projekts stillzulegen ist bewusst nicht abgedeckt — bestätigen lassen.
3. **Wer darf ein Projekt/zweites Board anlegen?** Vermutlich interne Mitglieder mit Verwaltungsrecht analog `MemberService::assertManager`; Owner-/Manager-Regeln (§8) auf Projekt-Ebene bestätigen, da Owner jetzt am Projekt hängt.
4. **Board-Titel-Pflege:** Projekt hat `title` (Kundenprojekt), Board hat `title` (z. B. „Entwicklung", „Marketing"). Bestätigen, dass beide Ebenen einen eigenen frei pflegbaren Titel bekommen.
5. **Phasen-Verteilung im Projekt-Dashboard bei unterschiedlichen Spalten je Board:** Spalten sind board-scoped; „Verteilung über Phasen" lässt sich über Boards nicht direkt vereinigen. Empfehlung: status-basiert aggregieren (neu/offen/wartet/erledigt ist board-unabhängig) plus optional eine Aufschlüsselung je Board. UI-Detail für #227.

---

## 6. Risiken

| Risiko | Wirkung | Gegenmaßnahme |
|---|---|---|
| **Confidentiality-Leak durch JOIN-Umstellung** (irreversibel) | Kundenseite sieht internes Ticket → Produktzusage entwertet | Rolle **einmal je Projekt** (Option A) macht per-Board-Desync **strukturell unmöglich**; PR 2 ist verhaltensneutral bei 1 Board je Projekt; voller Leak-Matrix-Rerun über synthetische Multi-Board-Fixtur (PR 1) läuft der Feature voraus; Write-Time-Wächter auf `tickets.project_id` |
| **Fehlendes `project_id` bei Ticket-INSERT** | JOIN findet Ticket nicht (Verfügbarkeit) oder — bei falschem Wert — Leak | `project_id` unveränderlich beim Insert aus dem Board gesetzt; Write-Time-Wächter schlägt beim INSERT ohne korrektes `project_id` an; NOT-NULL erst nach vollständigem Backfill |
| **NOT-NULL-Neuspalte bricht NC-Migration ab** | Migration schlägt fehl (Erfahrung worktime #596) | Spalten nullable hinzufügen und im selben Schritt befüllen; `archived` als `SMALLINT`, nie `BOOLEAN notnull`; `PARAM_BOOL` schreibt auf PostgreSQL 'f' statt 0 |
| **Nummerndubletten → Dateiname/Deep-Link-Kollision** | zwei Tickets, ein Dateiname | eindeutiger Index (`project_id`, `number`) ist die harte Garantie; `claimTicketNumber` atomar auf Projekt-Zeile; Deep-Link ID-basiert, nicht nummernbasiert |
| **Board zwischen Projekten verschiebbar** würde `tickets.project_id`-Unveränderlichkeit brechen | Kronjuwel-JOIN würde zweispaltig / Zähler instabil | im MVP **verboten**; später nachrüstbar |
| **Migrations-Backfill auf Produktivinstanz** | Datenschieflage bei Fehlern | additiv, Bestand läuft unverändert; PR 0 beweist Backfill isoliert; Version-Bump muss nach oben (sonst lügt der lokale Test); nach appdata-Berührung `occ files:scan-app-data` |
| **e2e/l10n-Waisen bei Verhaltens-Change** | CI rot, unbemerkte UI-Lücke | ALLE `tests/e2e/`-Specs greppen; entfernte Text-Keys aus allen l10n-Dateien löschen, `npm run test` lokal vor Push; UNIT-Suite (`tests/phpunit.xml`) lokal fahren |
| **TS5→7-Migration zieht in den Umbau** | Scope-Explosion, Risiko-Vermischung | TS-Upgrade als **eigenständiger** Tooling-Task außerhalb #246 vormerken |

---

## Verworfene Alternativen

- **Fan-out** (`members.board_id` bleibt, Projekt-Rolle N-fach je Board kopiert): kauft eine buchstäblich unveränderte `TicketScope.php`, schafft dafür eine **dauerhafte Leak-Fläche** — ein verpasstes Rollen-Update auf einem Board öffnet den `internal`-Zweig zu weit. Genau der Tausch, den Risk-first ablehnt.
- **JOIN über die Boards-Tabelle** (`members.project_id = boards.project_id AND boards.id = tickets.board_id`) statt `tickets.project_id`: bringt eine zusätzliche Tabelle in die sensibelste Abfrage. `tickets.project_id` (unveränderlich) hält den Kronjuwel-JOIN einspaltig.
- **Board-präfixierte Dateinamen** (`B2-0042_...`) bei per-Board-Nummern: leckt Board-Identität in Dateinamen und zwingt Deep-Links auf Board+Nummer.
- **Board zwischen Projekten verschiebbar** im MVP: hält `tickets.project_id` unveränderlich und den Zähler stabil. Später nachrüstbar.
- **Status quo** (1 Board = 1 Projekt): erfüllt #246 nicht.

---

*Relevante Dateien: `lib/Access/TicketScope.php`, `lib/Access/ViewerContext.php`, `lib/Access/BoardAccess.php`, `lib/Db/BoardMapper.php`, `lib/Db/MemberMapper.php`, `lib/Db/TicketMapper.php`, `lib/Db/Board.php`, `lib/Db/Member.php`, `lib/Db/Ticket.php`, `lib/Db/Column.php`, `lib/Service/MemberService.php`, `lib/Controller/BoardController.php`, `appinfo/routes.php`, `src/router.ts`, `src/stores/boardStore.ts`, `src/views/ProjectDashboardView.vue`, `tests/ReadPathRegistry.php`, `docs/produktbeschreibung.md`.*
