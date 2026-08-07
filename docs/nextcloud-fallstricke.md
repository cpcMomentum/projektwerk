# Nextcloud-Fallstricke für ProjektWerk

*Erstellt: 2026-08-07 · Begleitdokument zu `produktbeschreibung.md`*
*Quellen: Nextcloud-Dokumentation, Talk-API-Dokumentation, notify_push-Repository sowie die
hausinternen Standards unter `.claude/standards/`, `nextcloud-profile-for-ai-first-dev.md` und
`nextcloud-app-dev-guide.md` — Erfahrungen aus fünf gebauten Apps.*

**Zweck:** Diese Sammlung gehört nicht in die Produktbeschreibung, sondern in die Umsetzung. Sie ist
**vor der ersten Migration und vor dem ersten Endpunkt** zu lesen. Jeder Punkt ist ein Fehler, der
erst spät auffällt — meist beim ersten Kundenkontakt.

---

## Die fünf, die am teuersten wären

**1. Tabellennamen zu lang.**
Mit dem Präfix `projektwerk_` überschreiten Tabellen wie `projektwerk_ticket_steps` die
22-Zeichen-Grenze älterer Nextcloud-Versionen. Auf NC 34 läuft alles grün, jede Installation auf
NC 30–32 bricht bei der Migration ab — exakt so bei RechnungsWerk passiert.
→ **Präfix `pwerk_`** und `<database>`-Deklaration in der `info.xml`, beides vor der ersten
Migration. Released Migrationen werden nie editiert.

**2. Der Sichtbarkeitsfilter wird an zwei Stellen implementiert.**
Sobald Suche, Dashboard-Widget, Vorschaukarte, Aktivitätsstrom oder Benachrichtigung eine eigene
Abfrage mitbringen, gibt es mehrere Rechtelogiken — und die zweite ist die, die ein internes Ticket
an den Kunden ausliefert. Verschärfend: Keine dieser Schnittstellen wird von der
Guests-Freigabeliste gebremst.
→ **Ein einziger Mapper-Punkt** (`findVisibleFor($userId, …)`) mit der Regel in der WHERE-Klausel.
Jede Integration ruft ausschließlich diesen auf — nie eine eigene Abfrage, nie ein nachgelagertes
Filtern im PHP-Array.

**3. Der Deep-Link in der Mail funktioniert nur für bereits Eingeloggte.**
Ein Link mit `#/ticket/42` verliert sein Fragment beim Login-Umweg und landet auf der Startseite —
also genau bei den Kunden, die den Link am dringendsten brauchen. Outlook SafeLinks und ähnliche
Umschreiber entfernen Fragmente zusätzlich.
→ **Fragmentfreie Server-Route** `/ticket/{id}` mit Rechteprüfung und Weiterleitung, plus Übergabe
des Ziels an den Router über den Initial State. Und: **kein `@` in Pfad oder Query** — Nextclouds
Login-Controller verwirft solche Rücksprungziele stillschweigend, was E-Mail-Adressen und Gast-UIDs
in URLs ausschließt.

**4. Hintergrundjobs laufen faktisch nie.**
Im Cron-Modus `ajax`/`webcron` führt Nextcloud pro Aufruf genau **einen** Job aus; bei 20–40 Jobs
einer Instanz läuft ein 15-Minuten-Job dann alle paar Stunden. Ein als Queued-Job gebauter
Mailversand wäre bei SMTP-Fehler restlos verloren.
→ **Mailversand synchron** nach dem Datenbank-Commit mit Fehlerstatus in der Datenbank, **genau ein**
zeitkritischer TimedJob als Nachlauf, **Systemcron als dokumentierte Installationsvoraussetzung**.
Auf der Dev-Instanz ist der Modus leer (= `ajax`) und `overwrite.cli.url` steht auf
`http://localhost` — beide Werte am 2026-08-07 geprüft.

→ Statt beide Werte auf jeder Instanz von Hand zu erfragen, bringt die App einen **Setup-Check** mit,
der sie prüft und in der Verwaltungsübersicht warnt. Das ist die einzige Bauform, die auch bei
fremden Installationen trägt.

**5. `#[NoAdminRequired]` vergessen.**
Ohne das Attribut gilt maximale Härte (eingeloggt + Administrator + 2FA + CSRF). Die Methode
funktioniert im Test tadellos, weil als Administrator entwickelt wird, und liefert beim ersten
Kundenkontakt 403 — bei einer App, deren ganzer Zweck externe Nutzer sind, der teuerste denkbare
Entdeckungszeitpunkt.
→ Ein Durchlauf mit einem **Nicht-Admin-Konto** ab dem ersten Endpunkt in die Routine, und vor dem
ersten Kundeneinsatz derselbe Durchlauf mit einem **echten Gastkonto**.

---

## Datenbank und Migrationen

- Tabellenpräfix **`pwerk_`**. Grenze für Tabellen mit Auto-Increment-Primärschlüssel: praktisch
  22 Zeichen auf NC 30–32, 63 auf NC 34. Hauspräzedenz für kurze Präfixe existiert (`wt_`,
  `contractmgr_`).
- `<database>sqlite|mysql|pgsql</database>` in die `info.xml`, Reihenfolge laut Store-Schema
  `php → database → nextcloud`. Fehlt das Element, hält Nextcloud die App für Oracle-tauglich und
  erzwingt die strengeren Grenzen.
- Index-Namen sind in PostgreSQL schema-global: immer mit Tabellenpräfix qualifizieren
  (`pwerk_tickets_board_idx`), maximal 30 Zeichen.
- Benutzerkennungen durchgängig `varchar(64)` — **Gast-UIDs sind Hashes mit exakt 64 Zeichen** und
  würden bei `varchar(32)` still abgeschnitten.
- Boolesche Felder (Schritt erledigt, Benachrichtigungsschalter, Board-Flags) als `SMALLINT` mit 0/1
  und `PARAM_INT`. `BOOLEAN` mit `notnull` erzeugt Schema-Fehler; `PARAM_BOOL` schreibt auf
  PostgreSQL `'f'` statt `0`.
- Sichtbarkeits- und Rollenfeld sitzen **ab Migration 1** richtig, oder es kostet für immer
  Zusatzmigrationen.
- Gespeicherte Enum-Werte **ASCII und englisch** (`public`/`internal`/`private`). Umlaute in
  Enum-Werten sind eine Fehlerquelle bei Collation und Migration; die deutschen Bezeichnungen sind
  reine Anzeigetexte aus der Übersetzungsdatei.

## Controller und Rechte

- `#[NoAdminRequired]` an **jeder** Endnutzer-Methode.
- `#[NoCSRFRequired]` ausschließlich an der Seiten-Methode des PageControllers und an der
  Deep-Link-Route.
- Ratenbegrenzung an den Endpunkten, die Mailversand auslösen (Ticket anlegen, Zuweisen) — die App
  verschickt sofort Mail, das ist ein Versandhebel in Kundenhand.
- Kein Attribut-Routing: Alle fünf bestehenden Haus-Apps nutzen `appinfo/routes.php`, Mischbetrieb
  kostet mehr als er bringt.

## App-Abhängigkeiten gibt es nicht

Der `<dependencies>`-Block der `info.xml` kennt `php`, `database`, `command`, `lib`, `owncloud`,
`nextcloud`, `architecture` und `backend` — **kein `<app>`-Element** (im Schema von NC 34 geprüft,
`resources/app-info.xsd`). Nextcloud kann eine Abhängigkeit auf eine andere App nicht ausdrücken:
Eine Installation wird nie blockiert, und eine fehlende Fremd-App fällt erst zur Laufzeit auf.

→ Wer eine Fremd-App braucht, prüft **zur Laufzeit** (`IAppManager::isEnabledForUser()`) und meldet
das über einen Setup-Check. Für ProjektWerk gilt das nur für einen einzigen Fall: Guests installiert,
App nicht auf dessen Freigabeliste.

## Gast-Accounts

- `projektwerk` **muss** in die Freigabeliste der Guests-App. Ohne diesen Schritt liefert jeder
  Request unter `/apps/projektwerk/...` eine HTML-403-Seite — **auch API-Requests**, das Frontend
  stirbt dann an einem unverständlichen Parse-Fehler. Gehört als expliziter Schritt in die
  Installationsanleitung, plus ein Frontend-Wächter, der eine Nicht-JSON-Antwort als „App für Gäste
  nicht freigeschaltet" meldet.
- Bestehende Liste **zuerst lesen, ergänzt zurückschreiben**. Blindes Setzen schaltet Talk für alle
  Kunden ab. Verschärfend: Der Konfigurationswert ist **im Auslieferungszustand gar nicht gesetzt**
  (auf `nextcloud-dev` mit Guests 4.9.0 geprüft) — die Vorgabe steckt als Konstante im Code. Wer die
  Liste zum ersten Mal schreibt, **ersetzt damit die gesamte eingebaute Vorgabe** und muss sie
  vollständig mit übernehmen. Sie lautet in Guests 4.9.0 (`lib/AppWhitelist.php`):

  ```
  files_trashbin, files_versions, files_sharing, files_texteditor, text, activity,
  firstrunwizard, photos, notifications, dashboard, user_status, weather_status
  ```

- **Der `viewer` steht nicht in dieser Liste** — ebenso wenig `spreed`. Das im MVP zugesagte Öffnen
  von Anhängen funktioniert für Gäste also erst nach expliziter Freischaltung; dasselbe gilt später
  für Collabora. `text` und `activity` sind dagegen enthalten. Auf der Produktivinstanz von CPC ist
  die Liste bereits erweitert (Gäste haben dort Talk und Teamordner) — dort gilt die Leseregel
  uneingeschränkt.
- Nextclouds Kontakt-Autovervollständigung liefert Gästen **belegt eine leere Liste**. Jede
  Komponente, die still darauf zurückfällt (Personenauswahl, Erwähnungen in Kommentaren,
  Freigabe-Seitenleiste), ist beim Kunden funktionslos. Deshalb der App-eigene Personen-Endpunkt.
- Gäste brauchen ein **Speicherkontingent größer als 0**, sonst sehen sie geteilte Ordner unter
  Umständen leer.
- **Gast-Anzeigenamen:** Ohne gepflegten Namen steht die E-Mail-Adresse des Kunden als Klartext auf
  jeder Ticketkarte — auch für andere Mitarbeiter der Kundenseite sichtbar. Gehört in den
  Einführungsprozess. Im UI grundsätzlich den Anzeigenamen verwenden, nie die Benutzerkennung.
- Gastkonten tragen die Einladungsadresse als System-E-Mail und können sie selbst nicht ändern —
  der Mailversand an Gäste ist damit belegt möglich.
- Der Austauschordner muss **direkt an die Person** freigegeben werden; Gruppen- und Teamfreigaben
  werden bei Gästen nicht automatisch angenommen, und die App würde Anhänge in einen für den Kunden
  unsichtbaren Ordner schieben, ohne dass irgendwo ein Fehler auftaucht.
- Die Guests-Zusicherung „Gäste können keine Dateien außerhalb von Freigaben anlegen" ist im
  aktuellen Code **nicht mehr durchgesetzt**. Keine Sichtbarkeitsannahme darauf stützen — nur auf den
  Ablageort und die eigenen Rechteprüfungen.

## Theming und Barrierefreiheit

- **Keine festen Farben.** Alles über die Nextcloud-CSS-Variablen (`--color-primary-element`,
  `--color-text-maxcontrast`, `--color-border`, …). Dark Mode und Hoher Kontrast sind bei Nextcloud
  **Theme-Voreinstellungen, keine Media Query** — `prefers-color-scheme` allein erzeugt hellen Inhalt
  im dunklen Rahmen, sobald jemand sein Theme explizit wählt.
- Die Sichtbarkeits-Kennzeichnung darf **nicht allein über Farbe** laufen. Hauserfahrung: Die blanken
  Nextcloud-Signalfarben sind im Vorgabe-Theme für solche Marker zu blass, hier ist bewusste
  Gestaltung nötig.
- Klickflächen mindestens `--default-clickable-area`. Bei dicht gepackten Kanban-Karten mit Chip,
  Avatar und Zähler unterschreitet man das fast von selbst — und trifft damit ausgerechnet die
  geforderte Handy-Ansicht.
- **Tastaturbedienbare Alternative zum Drag & Drop von Anfang an.** Drag & Drop ohne Tastaturweg ist
  ein harter WCAG-Verstoß, und nachträglich in eine fertige Implementierung einzuziehen ist deutlich
  teurer. Die Auswahl der DnD-Bibliothek muss das mitbewerten, nicht nur Touch.
- Jede View-Wurzel braucht mindestens `padding-left: 50px`, sonst überdeckt der
  Navigations-Umschalter den Inhalt — bei einem Board liegt die erste Spalte genau dort.
- Spaltenbreite und Textumbruch explizit festlegen: `width: 100%` ist im Auto-Layout nur ein Minimum,
  lange Ticket-Titel treiben die Spalte über ihren Container hinaus (dokumentierter Fall aus
  WorkTime).

## Übersetzung

- **Deutsche Quellstrings sind zugleich Übersetzungsschlüssel.** Ein später korrigierter Tippfehler
  im deutschen Text verliert stillschweigend die englische Übersetzung — Korrekturlesen vor dem
  ersten Release ist deshalb ungewöhnlich wichtig.
- Keine dynamisch zusammengebauten Strings (`t('…', 'Sichtbar für: ' + rolle)`) — sie tauchen nie in
  der Übersetzungsdatei auf. Besonders naheliegend bei Sichtbarkeitsstufen und Rollennamen.
- Zahlen brauchen die Plural-Funktion mit Platzhalter — „3 Arbeitsschritte offen" und „wartet seit
  N Tagen" sind genau solche Fälle.
- Alle vier Dateien pflegen (`de.json`, `de.js`, `en.json`, `en.js`); ohne die `.js`-Varianten lädt
  der Browser keine Übersetzungen.

## info.xml, Icons, Release

- `min-version="33"`, `max-version="34"`; `max-version` **niemals weglassen**, der App Store weist
  sonst ab. Die Bootstrap-Vorlage im Haus setzt noch `max-version="33"` — bewusst überschreiben.
- Kategorie `organization` (optional zusätzlich `office`), deckungsgleich mit Deck und
  RechnungsWerk.
- `<name>`, `<summary>` und `<description>` englisch als Vorgabe plus deutsche Variante.
- `img/app.svg` **muss** `fill="#fff"` tragen, `img/app-dark.svg` **muss** ohne `fill` auskommen;
  `fill="currentColor"` ist verboten — sonst ist das Icon in einem der beiden Themes unsichtbar.
- **Cache-Buster:** Nextcloud leitet den Asset-Cache-Buster aus der App-Version ab. Ein neues
  JS-Bundle bei unveränderter Version liefert im Browser weiter das alte JavaScript — jeder Test
  lügt dann. Version beim Deployen immer nach oben bumpen.

## Benachrichtigungen im Detail

- Glocke: eigener Notifier, registriert im Bootstrap. Bei fremder App eine passende Ausnahme werfen,
  bei gelöschtem Ticket die Bereits-verarbeitet-Ausnahme; den geparsten Betreff **immer** setzen.
  Aufräumen beim Herunterstufen, Schließen und Löschen.
- E-Mail über die Nextcloud-Mailvorlage mit Überschrift, Text und Schaltfläche für den Direktlink.
  Adresse und **Sprache pro Empfänger** — nicht die Sprache des Auslösers.
- **Talk ist nicht Teil des MVP** — der folgende Punkt ist Vorratswissen für Abschnitt 6, Punkt 2 der
  Produktbeschreibung. Er steht hier, weil er teuer erarbeitet wurde und sonst verloren geht.
- Talk: **`occ talk:bot:create`**, nicht `talk:bot:install`. Der Unterschied ist auf Talk 24.0.3
  verifiziert — `create` beschreibt sich selbst als *„Creates a new bot on the server with 'response'
  feature only"*, und genau dieses Flag braucht aktives Senden. Ein per `talk:bot:install` mit
  `nextcloudapp://<appid>` registrierter App-Bot kann nur **reagieren**. Secret verschlüsselt
  ablegen, signiert über den Nextcloud-HTTP-Client senden. Freischalten pro Gespräch mit
  `talk:bot:setup` — **dafür gibt es keinen Weg aus der App heraus**. Der Bot ist **nicht an die App
  gekoppelt** und bleibt nach einer Deinstallation bestehen; `talk:bot:remove` und
  `talk:bot:uninstall` gehören in die Betriebsanleitung.
- Zeigt die konfigurierte Adresse auf eine lokale Adresse, blockt Nextclouds HTTP-Client den
  Selbstaufruf; dann ist eine Konfigurationsausnahme nötig. **Beim ersten Test zu prüfen.**

## Noch nicht belegt

- Ob Talk-Bot-Nachrichten von der Produktivinstanz aus zugestellt werden (Selbstaufruf hinter
  Reverse-Proxy oder mit Split-DNS scheitert gern still).
- Ob ein Gastkonto den Klick auf eine Ticket-Vorschaukarte bis in die App verfolgen kann — hängt an
  der Freigabeliste, gegen einen echten Gast prüfen, nicht annehmen.
- Verhalten des Referenz-Caches ohne konfigurierten verteilten Cache: lokal fällt er auf einen
  Per-Request-Cache zurück, das Veraltungsverhalten zeigt sich erst produktiv.
- **Migrationspfad, falls ein Kunde später ein reguläres Konto bekommt.** Nextclouds Gast-Migration
  überträgt nur Freigaben, keine App-Daten — Board-Mitgliedschaften und Zuweisungen würden auf die
  alte Kennung zeigen. Ob dieser Fall eintreten soll, ist zu entscheiden; wenn ja, braucht es einen
  eigenen Listener oder einen `occ`-Befehl.
