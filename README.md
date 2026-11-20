# ProjektWerk

Kanban-Werkzeug für die eigene Nextcloud, mit dem ein Dienstleister seine Projekte
**gemeinsam mit dem Kunden** führt: ein Board pro Projekt, auf dem interne und
kundensichtbare Vorgänge nebeneinander liegen, getrennt durch eine Sichtbarkeit am
einzelnen Ticket.

Der eigentliche Zweck ist nicht das Board, sondern die **sichtbare Zuständigkeit**:
Liegt ein Vorgang beim Kunden, steht das am Ticket — als geteilter Zustand, den beide
Seiten sehen, statt als Behauptung in einer E-Mail.

## Das Konzept in einem Satz

Ein Board, zwei Sichten — nicht zwei Boards.

| Anzeige | Wert | Sichtbar für |
|---|---|---|
| **Alle Beteiligten** | `public` | alle Board-Mitglieder, beide Seiten |
| **Intern** | `internal` | Mitglieder mit derselben Rolle wie die anlegende Seite |
| **Nur ich** | `private` | nur die anlegende Person |

`internal` ist **symmetrisch** — ein internes Ticket der Kundenseite sieht nur die
Kundenseite. Es gibt **keine Admin-Ausnahme**. Kommentare, Anhänge und Arbeitsschritte
erben die Sichtbarkeit vom Ticket; sie wird an genau einer Stelle geführt.

## Funktionen

- **Ein Board, zwei Sichten**: Sichtbarkeit am einzelnen Ticket (Alle Beteiligten, Intern, Nur ich) statt zweier getrennter Boards
- **Symmetrisch**: Auch die Kundenseite hat interne Vorgänge, und es gibt keine Admin-Ausnahme
- **Wartet auf Kunde**: abgeleiteter, beidseitig sichtbarer Zustand mit Datum, statt Nachfassen per E-Mail
- **Arbeitsschritte**: einzeln zuweisbar, ausdrücklich auch an Personen der Kundenseite
- **Dateien bleiben Dateien**: Anhänge liegen in den echten Projektordnern, der Ablageort ist die Sichtbarkeit
- **Meine Aufgaben**: projektübergreifende Sicht über Tickets und Arbeitsschritte
- **Kunden ohne Fremdkonto**: Zusammenarbeit auf der eigenen Nextcloud, wahlweise per Gastzugang

## Status

**Im Produktiveinsatz seit August 2026** und im Nextcloud App Store verfügbar.
Die App wird laufend weiterentwickelt; Neuerungen stehen im [CHANGELOG](CHANGELOG.md).

## Betriebsarten

Die App hängt an **keiner anderen Nextcloud-App** — auch nicht an Guests oder Talk.
Die Rolle `internal`/`external` hängt an der Board-Mitgliedschaft, nicht am Kontotyp.
Derselbe Code trägt drei Betriebsarten:

| Betriebsart | Voraussetzung |
|---|---|
| Nur intern | keine |
| Kunden mit Gastzugang | Guests-App |
| Kunden mit vollem Konto | keine |

Ist Guests im Einsatz, muss `projektwerk` (und für Anhänge auch `viewer`) auf dessen
Freigabeliste stehen — sonst bekommen Gäste eine 403-Seite. Die App prüft das selbst
über einen Setup-Check und meldet sich nur, wenn Guests überhaupt installiert ist.

## WerkPlus (optional)

ProjektWerk ist kostenlos und Open Source. Wer die Weiterentwicklung
unterstützen möchte, findet mit WerkPlus optionale Zusatzfunktionen für alle
Werk-Apps. Welche es für ProjektWerk gibt, steht auf
https://werkwolke.de/funktionen#projektwerk

Frei enthalten sind zehn aktive Projekte mit je einem Board und ein Kundenprojekt.
WerkPlus schaltet weitere Projekte, Kundenprojekte und Boards frei, dazu Antworten
per E-Mail und den KI-Zugriff über MCP.

## Anforderungen

- Nextcloud 33–35
- PHP 8.3+
- Node 20+ für den Frontend-Build

## Installation

Über den Nextcloud App Store: in der eigenen Nextcloud unter **Apps › Organisation**
nach „ProjektWerk“ suchen und aktivieren.

Von Hand (z. B. für die Entwicklung):

```bash
# Ordner nach apps/ bzw. custom_apps/ kopieren, dann:
php occ app:enable projektwerk
```

## Entwicklung

```bash
composer install
npm install

npm run dev        # Vite-Dev-Server
npm run build      # Produktions-Build nach js/ + css/ (vor jedem Commit!)
npm run typecheck
npm test           # vitest
composer test      # PHPUnit
```

`js/` und `css/` werden **committet** — ohne sie ist die App auf dem Server nicht
funktionsfähig.

## Dokumentation

- `docs/nextcloud-fallstricke.md` — Plattform-Eigenheiten, **vor der ersten Migration lesen**
  (das Tabellenpräfix `pwerk_` sitzt ab Migration 1)

## Lizenz

AGPL-3.0-or-later
