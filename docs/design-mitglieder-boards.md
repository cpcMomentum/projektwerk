# Design: Mitglieder dürfen Boards im Projekt anlegen (#281)

*Erstellt: 2026-09-14 · baut auf #246 (mehrere Boards/Projekt) und #280 (Gast-Anlage-Sperre) auf.*

## Problem Statement

Eine (oft externe) **Arbeitsgruppe** soll sich unter einem gemeinsamen Projekt
**selbst ein Board anlegen und einrichten** können, ohne dass ein interner
Projekt-Manager jedes Mal ran muss.

Heute geht das nicht: `BoardService::createInProject()` verlangt **Manager**
(`lib/Service/BoardService.php:180`), und Manager kann nur ein internes Mitglied
sein. Ein selbst angelegtes Board wäre zudem wertlos, weil die
**Spaltenpflege** (`ColumnService`) ebenfalls Manager-only ist.

## Bewusst unverändert (Grundfesten)

- **Boards sind keine Sichtbarkeitsgrenze.** Mitgliedschaft/Sichtbarkeit hängen
  am **Projekt** (`TicketScope`, `findAllForUser`). Ein Arbeitsgruppen-Board ist
  für **alle** Projektmitglieder sichtbar (nach public/internal/private). So
  gewollt — es geht um Ordnung, nicht Privatsphäre.
- **Keine Board-eigene Mitgliedschaft**, kein `IGroupManager`, keine
  Admin-Ausnahme. Nummernkreis und Ordner bleiben **projektweit geteilt**.

## Gewählter Ansatz: Board-scopes Ersteller-Recht + Projekt-Flag

Zwei kleine, klar getrennte Bausteine:

1. **Projekt-Flag „Mitglieder dürfen Boards anlegen"** (Default aus, vom
   Projekt-Manager gesetzt). Ist es an, darf **jedes Mitglied** — intern wie
   extern (inkl. Gäste) — ein weiteres Board im Projekt anlegen.
2. **Ersteller-Recht (board-scoped):** Wer ein Board anlegt, wird als dessen
   **Ersteller** vermerkt und darf **genau dieses Board** einrichten — Spalten
   pflegen, Board umbenennen und archivieren. Andere Mitglieder nicht; der
   Projekt-Manager weiterhin alles.

Das ist die **erste board-bezogene Berechtigung** der App (bisher waren
Schreibrechte projektweit über `is_manager`). Sie bleibt minimal: ein
`created_by` am Board plus ein abgeleitetes Flag im Betrachter-Kontext — **keine**
zweite Mitgliederliste.

### Datenmodell (1 Migration, 2 Spalten)

- `pwerk_projects.member_boards_allowed` — `Types::SMALLINT`, `notnull true`,
  `default 0` (wie `archived`; nie `Types::BOOLEAN` mit `notnull`). Das
  Projekt-Flag.
- `pwerk_boards.created_by` — `Types::STRING`, nullable. Kennung des Erstellers.
  - Gesetzt in `createInProject()` (= `$viewer->userId`) und in `create()`
    (= `$userId`, Konsistenz).
  - Altbestand bleibt `null` → dort gibt es keinen Ersteller mit Sonderrecht,
    nur der Manager richtet ein (unverändertes Verhalten).

Spaltennamen bleiben unter den Oracle-Grenzen (`member_boards_allowed` = 21,
`created_by` = 10 Zeichen).

### Autorisierung

Neue abgeleitete Eigenschaft im **Betrachter-Kontext**:

- `ViewerContext.isBoardCreator: bool` — in `BoardAccess::contextFor()` bestimmt
  als `member.user_id === board.created_by` (das Board wird dort ohnehin
  geladen). `forMember()` bekommt das Flag als weiteren Parameter; der
  Architektur-Test, der `forMember` nur in `BoardAccess` erlaubt, bleibt gültig.
- Neue Erlaubnis **„Board-Einrichter"** = `isManager || isBoardCreator`.

Angepasste Guards:

| Aktion | heute | neu |
|---|---|---|
| `BoardService::createInProject` | `assertManager` | `isManager \|\| project.member_boards_allowed` |
| `ColumnService::create/rename/reorder/setFinalOutcome` | `assertManager` | **Board-Einrichter** |
| `ColumnService::delete` | `assertManager` + `assertOwner` | **unverändert** — bewusst NICHT für den Ersteller: Löschen mit Umhängen fasst evtl. unsichtbare Tickets an (destruktiv). Der Ersteller legt an/benennt um/ordnet, entfernt aber nicht. |
| Board **umbenennen** (Titel/Beschreibung) | `assertManager` | **Board-Einrichter** |
| Board **archivieren** (`setArchived`) | `assertManager` | **Board-Einrichter** |
| Board-**Projektfelder** (Org, Ordner, Chat, GitHub) | `assertManager` | **unverändert Manager** |
| Board **hart löschen** | *existiert nicht* (nur Archiv) | unverändert (kein Endpoint) |
| Flag `member_boards_allowed` setzen | — | **Manager** |

**Feld-genaue Regel beim Umbenennen:** `updateBoard` ist ein Sammel-Setter. Der
Ersteller darf darüber **nur `title`/`description`** ändern; enthält der
Änderungssatz ein projektweites Feld (Org/Ordner/Chat/GitHub), bleibt es
Manager-only. Sonst risse der Ersteller projektweite Einstellungen an sich.

### API

Keine neuen Routen zwingend — bestehende Endpunkte, angepasste Guards:

- `settings#createSiblingBoard` (`POST /boards/{boardId}/boards`): Guard gelockert.
- `settings#createColumn|renameColumn|reorderColumns|setColumnOutcome|deleteColumn`,
  `settings#updateBoard`, `settings#archiveBoard`: Guard → Board-Einrichter (bzw.
  feld-genau bei `updateBoard`).
- `settings#updateBoard` bekommt das Feld `memberBoardsAllowed` (Manager-only,
  Projekt-Ebene) — Setzen des Flags.
- `board#show` liefert im `viewer`-Block zusätzlich `isBoardCreator`, und am
  Board/Projekt `memberBoardsAllowed` — damit das Frontend die Aktionen zeigt.

### UI

- **Projekteinstellungen** (`BoardSettingsView`): Schalter „Mitglieder dürfen
  Boards anlegen" (nur Manager sichtbar).
- **Board hinzufügen** (`BoardView`, „Boards"-Menü): sichtbar für Mitglieder,
  wenn `memberBoardsAllowed` — nicht mehr nur `isManager`.
- **Spalten-/Umbenennen-/Archiv-Aktionen**: für den Ersteller seines Boards
  freigeschaltet (`viewer.isBoardCreator`), sonst wie bisher Manager.

## Verworfene Alternativen

- **B — „Alle Mitglieder dürfen jedes Board konfigurieren" (bei aktivem Flag).**
  Einfachster Bau (nur Manager-Check aufweichen), aber ohne Besitz: jedes
  Mitglied könnte fremde Arbeitsgruppen-Boards umbauen. Verworfen — kein klarer
  Verantwortlicher.
- **C — Board-eigene Rolle „Board-Verantwortlicher" mit eigener Mitgliederliste.**
  Führt Board-scopes Mitgliedschaft ein — genau die zweite Stelle, an der
  Sichtbarkeit/Rechte stimmen müssten, die die App bewusst vermeidet. Overkill;
  `created_by` genügt.
- **„Nur Umbenennen" der Spalten.** Zu unflexibel — eine Arbeitsgruppe braucht
  ihre eigenen Spalten, nicht nur andere Namen für die Standardspalten.

## Offene Punkte / Grenzen (MVP)

- **Nur der Ersteller** ist Einrichter, nicht „die ganze Gruppe" — es gibt keine
  Gruppen-Entität. Für die MVP ausreichend (der Ersteller ist der Board-Kümmerer);
  mehrere Einrichter wären eine spätere Erweiterung.
- **Verlässt der Ersteller das Projekt**, entfällt sein Sonderrecht (kein
  Mitglied → kein Kontext); der Projekt-Manager übernimmt. `created_by` bleibt als
  Historie stehen. Akzeptabel.
- **Gäste** dürfen Boards anlegen (Kern des Features) — bewusst anders als #280
  (keine eigenständigen *Projekte*). Der Unterschied ist gewollt und in beiden
  Docs vermerkt.

## Akzeptanzkriterien

- [ ] Manager kann pro Projekt „Mitglieder dürfen Boards anlegen" ein-/ausschalten
      (Default aus).
- [ ] Bei aktivem Flag legt ein **externes** Mitglied ein Board an; es wird als
      Ersteller vermerkt.
- [ ] Der Ersteller pflegt die Spalten **seines** Boards (anlegen/umbenennen/
      ordnen), benennt es um und archiviert es — **ohne** Projekt-Manager zu
      sein. Spalten **entfernen** bleibt Manager/Owner.
- [ ] Der Ersteller kann **keine** projektweiten Board-Felder (Org/Ordner/Chat/
      GitHub) und **keine** anderen Boards ändern.
- [ ] Bei ausgeschaltetem Flag ist „Board hinzufügen" für Nicht-Manager weg;
      Server antwortet 403.
- [ ] Sichtbarkeit/Mitgliedschaft/Nummernkreis/Ordner unverändert; keine
      Board-Mitgliedschaft, kein `IGroupManager`; Architektur-Tests grün.
- [ ] Unit-Tests für die neue Erlaubnis (Ersteller vs. Fremd-Mitglied vs.
      Manager) und den Flag-Guard; l10n DE/EN für neue sichtbare Texte.

## Aufwand / Risiko

**Mittel.** Eine Migration mit zwei Spalten, eine neue Berechtigungs-Achse (board-scoped, klein
gehalten), Guard-Anpassungen in `BoardService`/`ColumnService`/`SettingsController`,
`board#show`-Erweiterung und UI in `BoardView`/`BoardSettingsView`. Risiko
beherrschbar, weil die Sichtbarkeits-Engine unberührt bleibt und die neue
Erlaubnis rein additiv ist.
