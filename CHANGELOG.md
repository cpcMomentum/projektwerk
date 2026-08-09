# Changelog

Alle nennenswerten Änderungen an ProjektWerk werden hier dokumentiert.
Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- **Board-Oberfläche**: Projektliste, Board mit Spalten und Karten, Ticket-Detail als Overlay.
  „Verschieben nach …" im Kartenmenü ist der einzige Verschiebeweg — Drag & Drop kommt in
  Phase 7, die Alternative ohne Ziehen war zuerst da
- **Sichtbarkeit ändern** samt Rückfrage mit konkreten Namen und Zahlen. Ob zurückgefragt wird,
  entscheidet der Server über `visibility-impact`: Das Frontend kennt die Rangfolge der drei
  Stufen nicht, sonst stünde die Sichtbarkeitsregel ein zweites Mal im Code. Hochstufen läuft
  ohne Rückfrage durch und bleibt kurz widerrufbar
- **Deep-Link `/t/{id}`**, fragmentfrei und mit Rechteprüfung. Ticket unbekannt, verborgen und
  fremdes Projekt ergeben dieselbe Antwort — wer eine Zahl im Link hochzählt, lernt nichts.
  Aus abgemeldetem Zustand in einer Gast-Sitzung nachgewiesen
- **Projekteinstellungen**: Projekt, Spalten, Mitglieder und Archiv. Spaltenreihenfolge über
  Hoch/Runter statt Ziehen
- **Kontensuche** für das Hinzufügen von Mitgliedern — eigener Endpunkt statt Nextclouds
  Personensuche, die in Gast-Sitzungen prinzipbedingt leer bliebe. Achtet
  `shareapi_allow_share_dialog_user_enumeration`
- **Arbeitsschritte** mit Titel, Zuweisung, Fälligkeit und erledigt. Wer einen Schritt bekommen
  darf, folgt aus der Sichtbarkeitsregel und kommt vom Server; bei einem öffentlichen Vorgang
  stehen beide Seiten gemeinsam und ohne Warnung in der Liste
- **„Wartet auf Kunde"** — gerechnet, nie gespeichert. Marke über dem Titel, im Detail als Satz
  mit Namen, Filterschalter „Nur wartend" mit Zählanzeige. In der Kundenansicht neutral
  formuliert (`wartet auf euch`, `liegt bei`)
- **Weiches Löschen**: `deleted_at`, ausgewertet allein in `TicketScope::apply()`. Kein
  Papierkorb in der App — der wäre ein zweiter Ort, an dem Tickets leben. Wiederhergestellt
  wird per `occ projektwerk:ticket:restore`
- Anzeigename kommt vom Server (`resolvedName`): Übersteuern an der Mitgliedschaft, sonst der
  Name aus Nextcloud, sonst die Kennung
- Projektgerüst: Vue 3 + Vite + TypeScript + @nextcloud/vue 9, PHP 8.4+ mit OCP-APIs
- CI: `node` (Typecheck, vitest, Build) und `phpunit` (PHP 8.4/8.5 gegen alle in
  `info.xml` deklarierten ocp-Versionen), dazu `canary`, `claude` und `claude-code-review`
- Datenmodell: Migration 1 mit allen zehn Tabellen (Präfix `pwerk_`) und sechzehn Indizes.
  Läuft auf SQLite, MySQL 8.4 und PostgreSQL 16 durch
- Zugriffsschicht: `ViewerContext`, `BoardAccess`, `TicketScope`. Die Sichtbarkeitsregel
  steht als JOIN an genau einer Stelle; `TicketMapper` hat keine kontextfreie Lesemethode,
  die vier Kinder-Mapper nur `findForTickets()` und `countForTickets()`
- Leak-Matrix: DB-gestützter CI-Wächter über fünf Betrachter und alle sechzehn Lesepfade,
  einschließlich Zählern. Ein Vollständigkeitstest lässt jeden nicht registrierten Lesepfad
  und jede nicht registrierte GET-Route fallen
- Setup-Checks: `InstanceConfigCheck` (Cron-Modus, `overwrite.cli.url`, `mail_smtptimeout`)
  und `GuestsWhitelistCheck` (ProjektWerk und Viewer auf der Freigabeliste der Guests-App).
  Beide melden nur und schreiben nicht

### Fixed

- Teleportierter Dialoginhalt trug die App-Klasse nicht in sich. `NcModal` und `NcDialog`
  hängen ihren Inhalt an den `body`, wo `.app-projektwerk` kein Vorfahr mehr ist — im Overlay
  griff dadurch keine einzige CSS-Regel. Ein Wächter prüft das jetzt über den Quelltext
- Versionskonflikt (409) bekommt überall dieselbe Antwort; das Board lädt nach, statt zum
  Neuladen aufzufordern
- Spaltenname wird beim Verlassen des Feldes gespeichert statt bei jedem Tastendruck
- Zuweisung eines Arbeitsschritts liess sich nicht mehr entfernen: `getParam()` prüft mit
  `isset()` und kann ein ausdrückliches `null` nicht von „nicht genannt" unterscheiden

### Changed

- Plattformfenster auf Nextcloud 33–34 und PHP 8.4 angehoben. Nextcloud 32 erreicht im
  September 2026 sein Lebensende; eine Untergrenze, die vor dem ersten Release stirbt, ist keine

### Notes

- Ohne UI: Phase 1 liefert Fundament und Wächter, nichts Vorführbares
