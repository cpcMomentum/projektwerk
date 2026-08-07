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

## Status

**Frühe Entwicklung.** Das Gerüst steht, die Fachlogik nicht.

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

## Anforderungen

- Nextcloud 33–34
- PHP 8.4+
- Node 20+ für den Frontend-Build

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

## Installation (eigene Instanz)

```bash
# Ordner nach apps/ bzw. custom_apps/ kopieren, dann:
php occ app:enable projektwerk
```

## Dokumentation

- `docs/nextcloud-fallstricke.md` — Plattform-Eigenheiten, **vor der ersten Migration lesen**
  (das Tabellenpräfix `pwerk_` sitzt ab Migration 1)

## Lizenz

AGPL-3.0-or-later
