# Konzept: Dashboard mit Projekt-Übersicht und Projekt-Dashboard

> Design-Runde am 2026-08-27, ausgelöst von Axels Befund: Der Überblick fühlt
> sich trotz Dringlichkeits-Ampel (#224, gemergt) „nach wie vor nicht wie ein
> richtiges Dashboard" an. Teil von [#219](https://github.com/cpcMomentum/projektwerk/issues/219)
> (Design-Annäherung an WorkTime).
>
> Verwandt, aber älter: `dashboard-ueberblick-konzept.md` (Kreativsession zu #116).
> Diese Notiz löst sie nicht ab, sie führt sie weiter: Der Überblick bleibt die
> „wo hakt es"-Seite, bekommt aber eine echte Dashboard-Form und eine zweite
> Ebene je Projekt.
>
> Status: abgestimmtes Konzept, Umsetzung in zwei Stufen. Noch kein Code.

## Der Kern des Befunds

Der bisherige Überblick ist im Grunde eine **Arbeitsliste** („was muss ich
anfassen"), nur mit einer Ampel obendrauf. Ein **Dashboard** zeigt **Zustand und
Bewegung auf einen Blick**: wie stehen die Projekte, wo staut sich Arbeit, was
habe ich zu tun, was bewegte sich zuletzt. Die Grundform muss sich also ändern,
nicht nur die Optik.

## Das Zwei-Ebenen-Modell

1. **Gesamt-Dashboard** (der Einstieg, ersetzt den heutigen Überblick):
   Projekt-Übersicht als Tabelle mit Status-Zahlen, darunter „Meine Maßnahmen"
   als Tabelle und „Zuletzt von mir bearbeitet".
2. **Projekt-Dashboard** (Klick auf eine Projektzeile führt hierher, **nicht**
   direkt aufs Board): Zustand genau dieses einen Projekts, von hier ein Klick
   weiter aufs gewohnte Kanban-Board.

WorkTime-Look durchgehend: Karten und Tabellen mit Rahmen (`--color-border-dark`)
und `--border-radius-large`, `tabular-nums`, kein Schatten. Dieselbe Tabellenform
wie WorkTimes `YearOverviewTable`.

## Ebene 1: Gesamt-Dashboard

### Projekt-Übersicht (Tabelle)

Eine Zeile je aktivem Projekt. Spalten:

| Projekt | Neu | Offen | Wartet | Erledigt | Fortschritt | Zustand |

- **Neu / Offen / Wartet / Erledigt**: kanonische Status (siehe unten),
  projektübergreifend gleich und damit vergleichbar. Zusätzlich als **eine
  gestapelte Leiste** (farbsegmentiert nach Status), damit das Verhältnis auf
  einen Blick lesbar ist (Muster GitHub Card-Counts / Monday Battery). Die Zahlen
  bleiben daneben stehen.
- **Fortschritt**: Balken `Erledigt / (Erledigt + offene Vorgänge)`. Verworfene
  zählen nicht in den Nenner.
- **Zustand („wo liegt der Ball")**: ein **eigenes Signal, getrennt vom Status**
  und **aus Daten abgeleitet, nicht manuell gepflegt** (zentrale Lehre der
  Recherche, siehe `dashboard-recherche-2026-08-27.md`). Vier Werte:
  - **Rot** — echte Frist verstrichen (überfällig).
  - **Gelb** — Ball liegt beim Kunden („Wartet") oder Vorgänge altern.
  - **Grau** — niemand am Zug / steht still (keine Bewegung, wartet nicht).
  - **Grün** — läuft.

  „Wartet auf Kunde" bekommt bewusst ein sichtbares eigenes Signal, nicht nur eine
  Zahl: Es ist die Kernaussage der App.
- **Ganze Zeile klickbar** → Projekt-Dashboard (Ebene 2).
- Angepinnte Projekte zuerst (Stern), wie heute im Abschnitt „Projekte mit
  Bewegung".

### Meine Maßnahmen (Tabelle)

Eine Datenquelle mit der bestehenden Seite „Meine Aufgaben" (`taskStore`), im
neuen Tabellen-Look. Spalten:

| Vorgang | Projekt | Art | Status | Fällig |

- **Art** unterscheidet nach **Obligation**, nicht nach jeder technischen Rolle:
  - **Schritt** — mir zugewiesener Arbeitsschritt (`Step.assignedUserId == ich`).
    Das konkrete „ich muss X erledigen".
  - **Verantwortung** — ich bin für den Vorgang zuständig
    (`Ticket.responsibleUserId == ich`) oder am Vorgang beteiligt
    (`pwerk_ticket_users`), so wie „Meine Aufgaben" heute filtert.
  - **Ersteller/Owner** (`Ticket.creatorUserId`) ist **keine** eigene Art:
    etwas angelegt zu haben ist keine Pflicht zu handeln. Nur als **optionaler
    Filter** „von mir angelegt", um Angestoßenes zu verfolgen.
- **Jede Zeile klickbar** → öffnet den Vorgang (Deep-Link ins Board mit
  geöffnetem Ticket, wie `openTicket` heute).
- Sortierung wie bisher: nach Fälligkeit, Überfälliges oben, dann Alter (§9).

### Zuletzt von mir bearbeitet

Schmale Liste: Vorgänge mit `lastEditorUserId == ich`, nach `updatedAt`
absteigend, Top N. Die leichte Variante von „woran habe ich zuletzt gearbeitet",
**nicht** der große Aktivitätsstrom (#19, backlog) und kein Ersatz dafür.

## Ebene 2: Projekt-Dashboard (neue Ansicht + Route)

Landepunkt nach Klick auf ein Projekt. Inhalt:

- **Kopf**: Projektname, Firmenzeile, Brotkrume „‹ Überblick", Knopf
  **„Board öffnen"**.
- **Status-Kacheln** für dieses Projekt: Neu / Offen / Wartet / Überfällig /
  Erledigt.
- **Fortschritt**: `Erledigt / gesamt` als Balken.
- **Verteilung über die Phasen**: hier die **echten Board-Spalten** dieses
  Projekts (frei benannt, z. B. Eingang / In Arbeit / Beim Kunden / Fertig) als
  Balken. Zeigt, wo sich die Arbeit staut.
- **Offene Vorgänge** als Tabelle (Vorgang, Phase, Verantwortlich, Fällig),
  klickbar.
- **Zuletzt bearbeitet (Projekt)**: Vorgänge des Projekts nach `updatedAt`.
- **Zuletzt abgestellt (Projekt)**: die letzten **5 erledigten** Maßnahmen
  (`closedOutcome == done`, nach `closedAt` absteigend). Zeigt, was zuletzt vom
  Tisch kam.

## Mehrere Boards je Projekt (Zukunft)

Heute gilt **ein Board = ein Projekt**; der Überblick nutzt `boardId` als
Projekt. Absehbar bekommt ein Projekt **mehrere Issue-Boards** (Axel,
2026-08-27). Dann wird das **Projekt-Dashboard die Klammer über mehrere Boards**:
Status-Zähler und Listen aggregieren über alle Boards des Projekts. Das Zwei-
Ebenen-Modell trägt das bereits; die Aggregations-Abfrage muss nur von „je Board"
auf „je Projekt (Menge von Boards)" umgestellt werden können. Bis das Projekt-
Objekt über den Boards existiert, bleibt Board = Projekt. **Beim Bau der
Aggregation nicht auf 1:1 festnageln.**

## Statusmodell (kanonisch)

Die Boards haben frei benannte Spalten; für die projektübergreifende Tabelle
braucht es eine **einheitliche Ableitung**:

- **Erledigt** — abgeschlossen mit `closedOutcome == done`.
- **Verworfen** — abgeschlossen mit `closedOutcome == discarded`. Nicht im
  Fortschritts-Nenner, in der Tabelle höchstens dezent.
- **Wartet** — offen und wartet auf die Kundenseite (wie im heutigen Überblick).
- **Neu** — offen und noch in der **ersten Spalte** des Boards (Eingang, noch
  nicht aufgegriffen). Zweck (Axel, 2026-08-27): **sichtbar machen, welche neuen
  Themen reinkommen.** Bleibt als eigener Status.
- **Offen** — offen, nicht wartend, nicht mehr „neu".

## Daten und Backend

- Der heutige Überblick lädt **nur offene** Vorgänge. Für „Erledigt"-Zahlen und
  Fortschritt braucht es eine **neue, aggregierte Abfrage**: Status-Zähler je
  Board, inklusive abgeschlossener Vorgänge. Der vorhandene `TaskFilter` kennt
  bereits `includeClosed` — darauf lässt sich aufsetzen.
- Alle Zähler und Listen laufen über die **sichtbarkeits-gefilterte** Menge
  (`scopedQuery` / `findVisibleAcrossBoards`). **Auch Zähler dürfen Verborgenes
  nicht verraten** (Architektur-Leitplanke aus CLAUDE.md).
- Felder, die schon da sind: `updatedAt`, `lastEditorUserId`, `closedOutcome`,
  `closedAt`, `responsibleUserId`, `creatorUserId`, `Step.assignedUserId`,
  Board-Spalten mit Position und `finalOutcome`.

## Umsetzung in zwei Stufen

- **Stufe 1 (eigenes Issue):** Backend-Aggregation der Status-Zähler +
  Gesamt-Dashboard (Projekt-Tabelle, Maßnahmen-Tabelle, „Zuletzt von mir
  bearbeitet"). „Meine Aufgaben" wird die volle Maßnahmen-Tabelle; das Dashboard
  zeigt eine kompakte Fassung mit „alle ansehen".
- **Stufe 2 (eigenes Issue):** Projekt-Dashboard als neue Ansicht + Route,
  Projektzeile verlinkt dorthin statt aufs Board, „Board öffnen" als Weiterweg.

Jede Stufe ist für sich lauffähig und deploybar. Kein Big-Bang (#219).

## Geklärt (2026-08-27)

1. **„Neu"** bleibt als Status: offen und in der ersten Board-Spalte. Zweck:
   sehen, welche neuen Themen reinkommen.
2. **„Meine Aufgaben" ↔ Dashboard**: eine Quelle. Die Seite „Meine Aufgaben" wird
   die volle Maßnahmen-Tabelle, das Dashboard zeigt eine kompakte Fassung mit
   „alle ansehen".
3. **Rollen** nach Obligation (Schritt / Verantwortung), Owner als Filter.

## Recherche (erledigt 2026-08-27)

Vergleich mit Linear, GitHub Projects, Asana, Basecamp, Height, Monday.com in
`dashboard-recherche-2026-08-27.md`. Eingearbeitet: abgeleitetes Zustandssignal
(statt manueller Ampel), „Wartet" als eigenes Signal, gestapelte Status-Leiste,
Dringlichkeits-Sortierung der Maßnahmen, Recency als eigene Blöcke, fixe 4 Status
als Grundlage der Mehr-Board-Aggregation. Bewusst weggelassen: Velocity/Burn-up,
Gantt/Roadmap, Widget-Flut, Arbeitsmengen-Kennzahlen.
