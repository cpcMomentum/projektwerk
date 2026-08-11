# Changelog

Alle nennenswerten Änderungen an ProjektWerk werden hier dokumentiert.
Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- **Anhänge am Vorgang** (Phase 5 Teil B) — eine Datei landet in dem Projektordner, der zur
  Sichtbarkeit ihres Vorgangs gehört. Flache Ablage mit Vorgangsnummer davor
  (`0042_angebot.pdf`), bei Namensgleichheit wird gezählt statt überschrieben: Zwei Personen,
  die am selben Tag „scan.pdf" anhängen, dürfen einander nicht die Datei wegnehmen. Gespeichert
  wird die **Datei-ID**; der Verweis führt in Nextclouds eigene Dateiansicht, es gibt **keinen
  eigenen Downloadweg** — wer die Datei sehen darf, entscheidet Nextcloud.

  **Für Vorgänge ohne Ablageort gibt es keine Anhänge.** Ein interner Vorgang der Kundenseite
  und ein „Nur ich"-Vorgang haben keinen Ordner, in dem die Datei genauso eng läge; einen der
  beiden vorhandenen zu nehmen hieße, sie jemandem hinzulegen, der den Vorgang nicht sehen
  darf. Die App lehnt dann ab, statt einen Ort zu raten.

  **Lösen löst nur die Verknüpfung.** Die Datei bleibt liegen, wo sie liegt — die App löscht
  nie (§5.18). Der Rückfragedialog sagt das ausdrücklich, damit niemand „lösen" für
  „wegräumen" hält.
- **Die Sichtbarkeit eines Vorgangs mit Anhängen lässt sich nicht ändern** (§3.10 Stufe 1).
  Das ist der einzige Punkt, an dem ein Leck **physisch** würde: Läge die Datei erst in
  `90_Austausch`, hätte die Kundenseite sie gesehen, und keine spätere Codekorrektur nähme das
  zurück. Ein Umzug der Dateien ist nicht transaktional zur Datenbank, und §11.3 ist
  unbeantwortet — bis Spike S2 das klärt, wird gar nicht erst verschoben. Die Absage steht
  **vor** der Bestätigung: `visibility-impact` liefert die Zahl längst mit, und eine Warnung zu
  bestätigen, die ohnehin abgewiesen würde, wäre ein Handgriff ohne Wirkung.
- **Dateiablage in den Projekteinstellungen** — die beiden Projektordner lassen sich am Board
  hinterlegen. Die Spalten dafür stehen seit Migration 1, gesetzt hat sie bisher nichts; ohne
  sie hätte ein Anhang keinen Ort, an den er gehört. Eingetragen wird ein **Pfad**, gespeichert
  wird die **Datei-ID** — wer den Ordner später umbenennt, lässt eine Beschriftung veralten und
  keine Verknüpfung reißen. Der Server löst auf und antwortet mit dem kanonischen Pfad; ein
  Ordner, der nicht existiert, keiner ist oder nicht beschreibbar ist, wird beim Eintragen
  abgewiesen und nicht erst beim ersten Anhang. Ein leeres Feld entfernt die Zuordnung — der
  Ordner selbst bleibt unangetastet, die App löscht nicht (§5.18).

  Welcher der beiden Ordner für einen Vorgang zuständig ist, folgt aus seiner Sichtbarkeit und
  steht an **einer** Stelle (`ProjectFolderService::locationFor`). Ein interner Vorgang der
  Kundenseite und ein „Nur ich"-Vorgang bekommen ausdrücklich **keinen** — für sie gibt es
  folgerichtig auch keine Anhänge (§3.10).

  **Ohne Nextclouds Dateiwähler, und das ist eine Werkzeuggrenze, keine Entwurfsentscheidung:**
  `@nextcloud/dialogs` lädt die Auswahl über `import()` nach, und ein IIFE-Bundle verträgt
  keine Codeaufteilung. Mit Vite 8/Rolldown bricht der Build daran ab — `codeSplitting: false`,
  die Option, die die Fehlermeldung selbst vorschlägt, greift bei einem gewöhnlichen `import()`,
  bei diesem Paket nicht. Ein Wähler lässt sich später davorsetzen, ohne ein gespeichertes Feld
  anzufassen.
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
- **Spalte entfernen — immer mit Zielspalte, nie mit Ticketverlust.** Eine Spalte enthält
  womöglich Vorgänge, die der Löschende nicht sehen darf; eine Rückfrage könnte dann nur
  zwischen zwei Fehlern wählen — eine Zahl über alle verriete Verborgenes, eine Zahl über die
  sichtbaren löschte ungefragt mehr, als sie ankündigt. Deshalb wird nicht gelöscht, sondern
  verschoben: Die Zielspalte ist Pflicht und ohne Vorbelegung, alle Vorgänge wandern dorthin
  (auch die verborgenen und die weich gelöschten), erst dann fällt die leere Spalte weg.
  Verschieben und Wegfallen stehen in einer Transaktion. **Nur der Board-Eigentümer**, nicht
  jeder mit Verwaltungsrecht — und ausdrücklich keine Admin-Ausnahme
- **Kontensuche** für das Hinzufügen von Mitgliedern — eigener Endpunkt statt Nextclouds
  Personensuche, die in Gast-Sitzungen prinzipbedingt leer bliebe. Achtet
  `shareapi_allow_share_dialog_user_enumeration`
- **Kommentare** in Markdown, mit der Sichtbarkeit des Vorgangs. Sie haben keine eigene —
  gelesen werden sie weiter über `ticket#show` aus der gefilterten Ticketmenge, neu sind nur
  Schreibwege. **Ändern und Löschen kann nur die verfassende Person**, ohne Ausnahme für
  Verwaltungsrecht oder Board-Eigentum; der Preis dafür ist benannt: Ein Versehen bleibt
  stehen, wenn die Person das Board verlassen hat. Gelöscht wird hart, es gibt keinen
  Papierkorb. Ein hineinkopierter Dateilink wird **nicht** in eine Vorschau aufgelöst
  (`interactive` bleibt aus) — was hinter ihm steckt, entscheiden Nextclouds Freigaben und
  nicht die Sichtbarkeit des Vorgangs. Das ist einem Kunden gegenüber zu benennen, bevor
  Phase 5 vorgeführt wird. Markdown mit `remark-gfm`: Tabellen, Aufgabenlisten und
  Durchstreichen rendern, statt als Striche im Text zu landen
- **Arbeitsschritt anlegen mit Zuständiger und Fälligkeit in einem Zug.** Vorher legte man an
  und wies danach zu; die Fälligkeit ließ sich über die Oberfläche **gar nicht** setzen, nur
  anzeigen. Der schnelle Weg bleibt: Beide Felder sind leer vorbelegt, Enter sendet ab.
  Nebeneffekt, erwünscht — die Uhr „wartet seit" beginnt jetzt beim Anlegen statt beim
  Zuweisen. Auf schmalen Bildschirmen teilen sich Zuständige und Fälligkeit **eine** Zeile:
  gestapelt wäre aus jedem Schritt ein Block von drei Zeilen geworden, und der Großteil davon
  wären leere Felder gewesen
- **Arbeitsschritte** mit Titel, Zuweisung, Fälligkeit und erledigt. Wer einen Schritt bekommen
  darf, folgt aus der Sichtbarkeitsregel und kommt vom Server; bei einem öffentlichen Vorgang
  stehen beide Seiten gemeinsam und ohne Warnung in der Liste
- **„Wartet auf Kunde"** — gerechnet, nie gespeichert. Marke über dem Titel, im Detail als Satz
  mit Namen, Filterschalter „Nur wartend" mit Zählanzeige. In der Kundenansicht neutral
  formuliert (`wartet auf euch`, `liegt bei`)
- **„Meine Aufgaben"** — projektübergreifend, laut §9 die Startseite des Kunden. Zwei Abschnitte:
  *Meine Arbeitsschritte* (mir zugewiesen, offen) und *Meine Vorgänge* (verantwortlich oder
  mitarbeitend), jede Zeile mit Vorgang und Projekt als Herkunft. Ein Kästchen erledigt einen
  Schritt, ohne die Ansicht zu verlassen. Sortiert nach Fälligkeit, Überfälliges dadurch oben;
  ohne Fälligkeit ans Ende. Beide Abschnitte kommen aus **einer** Antwort, und die Rolle bildet
  `TicketScope` je Board — dieselbe Person kann in einem Projekt intern und im anderen extern
  sein
- **Ältere Erledigte als Sicht, nicht als Ablageort.** `closed_at` bleibt die einzige Wahrheit;
  ein Archiv als dritter Zustand hätte die Verdopplung von „erledigt" verdreifacht. Je Spalte
  bleiben die zuletzt geschlossenen zehn stehen, der Rest steht hinter „N ältere Vorgänge
  anzeigen". **Anzahl statt Alter** — unbedienbar macht ein Board die Menge der Erledigten, nicht
  ihr Alter, und eine Zeitgrenze verhielte sich ausgerechnet an beiden Enden falsch. Kein neues
  Feld, keine Migration, keine Einstellung
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

- **Eine Fälligkeit ließ sich setzen, aber nie wieder löschen.** `StepController::update` baute
  seine Änderungsliste aus allem, was `!== null` war — und verwarf damit genau das ausdrückliche
  „Frist entfernen". Dieselbe Falle war für die Zuweisung schon einmal behoben worden; für die
  Fälligkeit fiel sie erst auf, als das Feld überhaupt aus der Oberfläche heraus zu bedienen war.
  Beide Felder laufen jetzt über dieselbe `array_key_exists`-Prüfung, und ein Controller-Test hält
  fest, dass ein ausdrückliches `null` durchkommt
- **Ein Verbindungsabbruch meldete sich auf Englisch.** Axios legt bei fehlender Antwort
  `Network Error` bei, und das stand wörtlich vor dem Nutzer — beim häufigsten aller Fehler und in
  einer durchgehend deutschen Oberfläche. Betraf jeden Aufruf der App, aufgefallen an der
  Aufgabenansicht. Die Meldung des Servers behält Vorrang, wo es eine gibt
- **Auf dem Handy verdeckte der Navigations-Umschalter jede Überschrift** — aus „Projekte" wurde
  sichtbar „ojekte". Betraf alle vier Ansichten; jetzt macht ihm die Kopfzeile Platz, ohne dass die
  Liste darunter Breite verliert
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
