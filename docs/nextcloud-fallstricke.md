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
- **`set()` quotiert sein zweites Argument als Spaltennamen.** `->set('position', 'position + '
  . $qb->createNamedParameter($x))` erzeugt deshalb keine Rechnung, sondern die Suche nach einer
  Spalte namens `"position + :dcValue2"` — auf PostgreSQL ein harter Fehler. Der scheinbare
  Ausweg `createFunction()` schreibt `position` unquotiert, und `position` ist in PostgreSQL ein
  Schlüsselwort. Wer eine Spalte relativ fortschreiben will, rechnet in PHP und schreibt je Zeile
  einen Wert (so macht es `TicketMapper::rebalanceColumn()` und `moveColumnContents()`).
  Am 2026-08-09 in #60 gemessen.

## Controller und Rechte

- `#[NoAdminRequired]` an **jeder** Endnutzer-Methode.
- `#[NoCSRFRequired]` ausschließlich an der Seiten-Methode des PageControllers und an der
  Deep-Link-Route.
- Ratenbegrenzung an den Endpunkten, die Mailversand auslösen (Ticket anlegen, Zuweisen) — die App
  verschickt sofort Mail, das ist ein Versandhebel in Kundenhand.
- Kein Attribut-Routing: Alle fünf bestehenden Haus-Apps nutzen `appinfo/routes.php`, Mischbetrieb
  kostet mehr als er bringt.
- **Ein JSON-Rumpf an einem DELETE kommt an.** `Request::decodeContent()` decodiert für **jede**
  Methode außer GET und legt die Werte in dieselben Parameter, aus denen der Controller liest
  (`getParam` → `__get('parameters')` → `getContent()`). Ein Pflichtparameter, der zum Vorgang
  gehört und nicht zum Ort, darf deshalb in den Rumpf — `settings#deleteColumn` macht das mit der
  Zielspalte. Voraussetzung ist `Content-Type: application/json`.
- **`JSONResponse(null, 204)` sendet keinen Rumpf.** `App.php` erkennt 204 und 304 und unterdrückt
  Rumpf **und** `Content-Length`. Ein 204 ist damit die richtige Antwort für einen Schreibvorgang,
  dessen Ergebnis nichts verraten darf.
- **Für `curl`-Proben gegen die API: `OCS-APIRequest: true` mitschicken.** Ohne den Kopf antwortet
  Nextcloud auch bei gültiger Basic-Auth mit `412 CSRF check failed` — was wie ein Rechtefehler
  aussieht und keiner ist.

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
  Request unter `/apps/projektwerk/...` eine HTML-Fehlerseite — **auch API-Requests**, das Frontend
  stirbt dann an einem unverständlichen Parse-Fehler. Gehört als expliziter Schritt in die
  Installationsanleitung, plus ein Frontend-Wächter, der eine Nicht-JSON-Antwort als „App für Gäste
  nicht freigeschaltet" meldet.

  **Gemessen in S1 (2026-08-07, NC 34.0.0, Guests 4.9.0): Der Status ist `500`, nicht `403`.**
  Der Rumpf sagt „Access to this resource (projektwerk) is forbidden for guests" — ein
  Berechtigungsfall im Gewand eines Serverfehlers. Für den Wächter in `api.ts` heißt das: Er darf
  **nicht** am Statuscode hängen. `500` mit HTML ist hier der Normalfall, nicht die Ausnahme.
- Bestehende Liste **zuerst lesen, ergänzt zurückschreiben**. Verschärfend: Der Konfigurationswert ist **im Auslieferungszustand gar nicht gesetzt**
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
- ~~Gäste brauchen ein **Speicherkontingent größer als 0**, sonst sehen sie geteilte Ordner unter
  Umständen leer.~~ **Am 2026-08-11 widerlegt** (NC 34, Guests 4.9.0): Ein Gast mit `0 B` sieht den
  geteilten Ordner, öffnet Dateien darin und lädt sogar selbst hoch — das Kontingent des
  Speichereigentümers zählt, nicht seines. Ausführlich unten unter „S1 abgeschlossen".
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

### S1 — gemessen am 2026-08-07 (NC 34.0.0, Guests 4.9.0, PHP 8.4)

Drei der sechs Punkte aus §11.2 sind beantwortet. Was dabei **anders war als angenommen**, steht
zuerst.

> **Nachtrag 2026-08-08:** Punkt 2 ist inzwischen ebenfalls beantwortet — er hing an der ersten
> API-Route und wurde nachgeholt, sobald es sie gab. Damit sind es **vier von sechs**; die Zeile
> steht in der Tabelle unten, der ausführliche Befund am Ende dieses Abschnitts.

**Die Freigabeliste ist gesetzt, auch wenn sie leer aussieht.** `occ config:app:get guests whitelist`
gibt im Auslieferungszustand **nichts** aus — trotzdem ist die Liste wirksam, denn Guests 4.9.0
hinterlegt sie als Lexikon-Vorgabe (`ConfigLexicon`, Eintrag `whitelist`, Vorgabewert
`AppWhitelist::DEFAULT_WHITELIST`). `usewhitelist` steht ebenso auf Vorgabe **`true`**, ohne Zeile in
der Konfiguration. Wer den Ist-Zustand über `occ` oder rohes `IAppConfig` liest und das Ergebnis
„leer" als „keine Liste" deutet, schreibt anschließend eine Ein-Element-Liste und verliert die zwölf
Vorgabe-Apps. Der Setup-Check muss über denselben Weg lesen wie die App selbst.

**Zwei Ebenen, nicht eine.** Neben `DEFAULT_WHITELIST` gibt es `WHITELIST_ALWAYS` — `core`, `files`,
`dav`, `settings`, `theming`, `guests`, `dashboard`, `user_status` und die Zwei-Faktor-Apps. Die
stehen in keiner Konfiguration und können durch einen Fehlgriff auch nicht verloren gehen. Der
Setup-Check darf sie nicht als fehlend melden.

**Talk war nie freigeschaltet.** Die frühere Formulierung „blindes Setzen schaltet Talk für alle
Kunden ab" trifft nicht zu: `spreed` steht in keiner der beiden Listen. Ein Gast bekommt auf
`/apps/spreed/` **vorher wie nachher** dieselbe Fehlerseite. Was ein blindes Setzen tatsächlich
kostet, sind die zwölf Vorgabe-Apps — `files_sharing`, `text`, `photos`, `activity`,
`notifications` und die übrigen. Das ist Schaden genug, aber ein anderer.

**Der Statuscode ist 500.** Siehe oben. Für den Wächter in `api.ts` maßgeblich.

**Die Änderung wirkt nicht im selben Atemzug.** Der erste Request unmittelbar nach
`occ config:app:set` lief noch gegen den alten Wert (`fast cache`, hier Redis), der zweite war grün.
Wer den Setup-Check nach einer Korrektur sofort erneut abfragt, sieht unter Umständen noch den
Fehlstand.

| §11.2 | Frage | Befund |
|---|---|---|
| 1 | Freigabeliste lesen und ergänzt zurückschreiben | **geht**, aber nur über die App-Konfiguration mit Lexikon-Vorgabe; `occ`-Sicht ist irreführend |
| 2 | `#[NoAdminRequired]`-JSON-Endpunkt in echter Gast-Sitzung | **geht** (nachgeholt am 2026-08-08). 200 mit `application/json`, Rolle kommt als `external` zurück. Voraussetzung ist `projektwerk` auf der Freigabeliste; ohne sie 500 mit HTML |
| 5 | Gast-UID-Länge | **exakt 64 Zeichen.** Bei aktivem Datenschutzschalter ist die Kennung ein Hex-Hash der Adresse, nicht die Adresse. `varchar(64)` passt — mit **null** Spielraum |
| 5 | Quota > 0 | **Nein: `0 B` im Auslieferungszustand** — aber **folgenlos** für unseren Ablauf (am 2026-08-11 nachgemessen, siehe unten). Belastet wird der Speichereigentümer, und unsere Anhänge liegen immer beim Dienstleister |
| 6 | Auffindbarkeit von Gästen beim Hinzufügen | **Gäste sind auffindbar.** Ein interner Nutzer findet den Gast über Anzeigename und Adresse (OCS `sharees`), zurück kommt die Hash-Kennung |
| 6 | Personensuche **durch** einen Gast | **Nur exakte Treffer.** Die Suche eines Gasts nach `admin` liefert `users: []` und ausschließlich `exact`. Kein Durchblättern, keine Teiltreffer — die Begründung für den App-eigenen Personen-Endpunkt bleibt gültig, ist aber genauer: nicht „leer", sondern „nur wer exakt benannt wird" |

**§11.2 Punkt 2 — beantwortet am 2026-08-08 (NC 34.0.0, Guests 4.9.0).** Mit der ersten API-Route
(`board#index`, `board#show`) nachgeholt: Ein Gastkonto erreicht einen `#[NoAdminRequired]`-Endpunkt
und bekommt **200 mit `application/json`** — die Freigabeliste muss dafür `projektwerk` enthalten.
Die Rolle kommt korrekt als `external` zurück, dieselbe Anfrage als internes Mitglied liefert
`internal`. Der Kundenzugriff auf die Schnittstelle ist damit belegt und nicht mehr angenommen.

**Nebenbefund, der jedes Skript betrifft: App-Routen brauchen den Header `OCS-APIRequest: true`.**
Basic Auth allein genügt nicht — Nextcloud legt dabei eine Sitzung an, und die CSRF-Prüfung
antwortet dann mit **412 `CSRF check failed`**, auch auf reine Lese-Endpunkte. Das gilt für
`/apps/…`-Routen ebenso wie für `/ocs/…`. Wer ohne Browser gegen die App prüft, setzt den Header;
wer ihn vergisst, misst die CSRF-Schicht statt der App.

**Der Administrator bekommt 404, nicht 403.** Am selben Durchlauf gemessen: `admin` ist kein Mitglied
des Boards und erhält beim Einzelabruf `404` und bei der Liste `[]`. Die Zusage „keine
Admin-Ausnahme" ist damit über HTTP belegt, nicht nur im Test.

**Offen aus S1** — beide brauchen einen echten Browser, einer zusätzlich einen Team-Ordner:

- §11.2 Punkt 3: `viewer` auf einer Datei in `90_Austausch`. Braucht den Team-Ordner, den es noch
  nicht gibt.
- §11.2 Punkt 4: fragmentfreier Deep-Link aus abgemeldetem Zustand. Braucht die Deep-Link-Route
  `/t/{id}` aus Phase 2 und einen Browser für den Anmeldeumweg.

> **Beide beantwortet am 2026-08-11** — siehe den Abschnitt direkt darunter. S1 ist damit
> vollständig.

### S1 abgeschlossen — gemessen am 2026-08-11 (NC 34.0.0.12, Guests 4.9.0, PHP 8.4)

Gemessen mit einem **echten Gastkonto** (`occ guests:add`) in einem echten Browser, gegen ein
Projekt, in dem der Gast Kundenseite ist. Der Spike liegt als `spike/S1-gast-durchstich.spec.ts` bei
und wird nicht mitausgeliefert.

**§11.2 Punkt 3 — die Datei erreicht der Gast, über die Datei-ID.** `/index.php/f/{fileId}`
antwortet mit **303** auf `/apps/files/files/{id}?dir=/<Ordner>&openfile=true`; im Browser baut sich
die Dateiliste auf und der Anhang steht mit Namen da. Nextcloud löst die ID **im Baum des Gastes**
auf — der Verweis funktioniert also, ohne dass die App einen Pfad kennt oder einen eigenen
Auslieferungsweg baut. Voraussetzung bleibt `viewer` auf der Freigabeliste und eine Freigabe
**direkt an die Person**.

**§11.2 Punkt 4 — der fragmentfreie Deep-Link trägt.** `/apps/projektwerk/t/{id}` aus abgemeldetem
Zustand landet auf `/login`, und das Rücksprungziel enthält **kein `@`** — also genau die Bedingung,
an der Nextclouds Login-Controller solche Ziele sonst stillschweigend verwirft. Die Entscheidung
gegen ein `#`-Fragment ist damit belegt richtig und nicht mehr nur begründet.

**Korrektur zum Quota — die Sorge war unbegründet, und zwar aus einem Grund, der es bleibt.**
Ein frisch angelegtes Gastkonto hat weiterhin `quota: 0 B` (die Vorgabe hängt am Instanz-Preset).
Die bisher hier stehende Folgerung war trotzdem falsch. Gemessen mit genau diesem Konto:

| Was | Ergebnis |
|---|---|
| Geteilten Projektordner sehen | geht |
| Anhang darin öffnen | geht |
| **Selbst einen Anhang hochladen** | **geht** — HTTP 201 über `attachment#create` |

Der Grund: Nextcloud belastet das Kontingent des **Speichereigentümers**, nicht des Hochladenden.
Die Datei landete in `pw-e2e-intern/files/…`, der Speicher des Gastes blieb bei `used: 0`. Weil
unsere Anhänge **grundsätzlich im Projektordner des Dienstleisters** liegen (§5.18, „der Ablageort
IST die Sichtbarkeit") und nie im Heimatordner des Gastes, greift das Gastquota an keiner Stelle des
Ablaufs.

Das ist kein Zufall, sondern eine Folge der Bauform: Eine Entscheidung, die aus Vertraulichkeits-
gründen fiel, entschärft nebenbei ein Plattformproblem. **Sie bleibt nur so lange richtig, wie kein
Projektordner dem Gast selbst gehört** — mit `0 B` kann er ohnehin keinen anlegen. Der Setup-Check
darf das Quota deshalb allenfalls als Hinweis melden, nicht als Fehler.

**Auffindbarkeit, genauer als bisher.** Der App-eigene Personen-Endpunkt findet Gäste über
Anzeigename **und** Kennung (`Spike` → `pw-spike-gast`, `Kunde Müller` → Hash-Kennung). Wer bereits
Mitglied des Boards ist, wird ausgeschlossen — das sieht in einem Test wie „Gäste sind nicht
auffindbar" aus und ist es nicht. Wer das nachmisst, braucht ein **zweites Board ohne diese
Mitgliedschaft** als Gegenprobe.

**Zur Methode:** Das Anmeldeformular ließ sich per `curl` nicht bedienen (die Anmeldung fällt auf
`/login?direct=1` zurück, auch mit gültigen Zugangsdaten und frischem `requesttoken`) — die
Gegenprobe mit einem regulären Konto scheiterte genauso, es liegt also am Ablauf und nicht am
Gastkonto. Gemessen wurde deshalb über **Basic Auth** gegen `/apps/...` und `/ocs/v2.php/...`; beide
Konten antworten dort mit `207` auf WebDAV, die Sitzung ist also echt. Für die drei offenen Punkte
(Weiterleitung nach Login, Viewer im Browser) reicht das nicht — die brauchen einen echten Browser.

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

### S2 — gemessen am 2026-08-07 (NC 34.0.0, Team folders 22.0.6)

Aufbau: Team-Ordner `Projekt-Spike` mit `90_Austausch` und `91_Tickets_intern`, eine Datei im
Kundenordner, direkt an das Gastkonto freigegeben, dann per WebDAV `MOVE` in den internen Ordner.

**Der Umzug erhält die Datei-ID.** `1010` vorher, `1010` nachher. Referenzen über die Datei-ID
überleben also, wie die Architektur es annimmt.

**Und genau deshalb überlebt auch die Freigabe.** Die Freigabe wandert mit und zeigt danach auf
`/Projekt-Spike/91_Tickets_intern/angebot.txt`. Der Gast liest die Datei **weiter mit HTTP 200**,
nachgeprüft aus seiner eigenen Sitzung, obwohl sie jetzt im internen Ordner liegt.

> **Der Ablageort ist nicht die Zugriffskontrolle.** Er ist die organisatorische Wahrheit; der
> Zugriff hängt an der Freigabe, und die klebt an der Datei-ID, nicht am Pfad. Ein Sichtbarkeits-
> wechsel, der die Datei nur verschiebt, **entzieht dem Kunden nichts**. Die App muss die Freigabe
> **ausdrücklich aufheben** und das Ergebnis prüfen — sonst ist R3 kein Risiko mehr, sondern der
> Normalfall: DB-seitig `internal`, physisch weiter beim Kunden, und niemandem fällt es auf.

**Team-Ordner brauchen das Freigaberecht explizit.** Mit `occ groupfolders:group <id> <gruppe> write`
scheitert jede Freigabe aus dem Ordner heraus mit „Die Freigabe von … ist Ihnen nicht erlaubt" — die
Meldung nennt den Grund nicht. Nötig ist `read write share delete`. Gehört in die
Installationsanleitung und in den Setup-Check.

**Nicht beantwortet: Versionen.** Trotz `files_versions` 1.27.0 und dreier Schreibvorgänge lag vor
dem Umzug **keine** Version vor — Nextcloud fasst schnell aufeinanderfolgende Schreibvorgänge
zusammen. Damit ist die Frage „überleben Versionen den Umzug?" mangels Versionen offen und braucht
einen zweiten Anlauf mit zeitlichem Abstand.

### S4 — gemessen am 2026-08-07 (NC 34.0.0, PHP 8.4, Symfony Mailer)

Aufbau: `nextcloud-dev` gegen `mailhog-dev` (SMTP 1025), Versand über `occ user:welcome` — der Weg
läuft durch `IMailer` wie der spätere App-Versand. Gemessen wurde die Wanduhr des gesamten
`docker exec`, der reine Versand liegt also unter den genannten Werten.

| Fall | Dauer | Verhalten |
|---|---|---|
| SMTP erreichbar | **0,23 s** | Mail kommt an |
| Port zu (Connection refused) | **0,19 s** | keine Mail, **Exitcode 0, keine Ausgabe** |
| Host verschluckt Pakete, Vorgabe | **10,3 s** | keine Mail, Exitcode 0 |
| dasselbe mit `mail_smtptimeout=3` | **3,2 s** | keine Mail, Exitcode 0 |

**Das Zeitbudget für den synchronen Versand ist damit eine Zahl: rund 10 Sekunden je Versuch**, wenn
die Gegenstelle Pakete verschluckt statt abzulehnen. Genau dieser Fall — Firewall, falscher Host,
abgelaufenes Relay — ist der wahrscheinliche, nicht der geschlossene Port. Zehn Sekunden hängen
sonst in der Schreibanfrage des Nutzers, der gerade ein Ticket anlegt.

`mail_smtptimeout` wirkt **exakt** und ist der Hebel dafür. Der `InstanceConfigCheck` prüft ihn mit:
ungesetzt bedeutet 10 s.

**Der Fehlschlag ist auf Aufruferebene still.** `occ user:welcome` liefert Exitcode 0 und keine
Ausgabe, obwohl keine Mail rausgeht. Im Log steht er sehr wohl — Level 3, `app: core`,
`Symfony\Component\Mailer\Exception\TransportException` mit dem Text „Connection could not be
established with host …". Für die App heißt das: **`IMailer::send()` wirft**, und wer nicht fängt,
merkt nichts. Das ist der gemessene Beleg für die Outbox aus E2; ihre Spalten stehen damit fest:
Empfänger, Betreffschlüssel, Versuchszähler, Zeitpunkt des letzten Versuchs und der Ausnahmetext.

**`overwrite.cli.url` schlägt auf die Links in der Mail durch — belegt.** Die zugestellte Mail
enthält `http://localhost/`, weil der Versand aus einem CLI-Kontext lief. Für einen Kunden ist das
ein toter Link, und es fällt niemandem auf, der die Mail nicht liest: Versand und Zustellung sind
erfolgreich. Der `InstanceConfigCheck` muss den Wert deshalb nicht nur auf „gesetzt" prüfen, sondern
auf „von außen erreichbar" — mindestens auf „nicht `localhost`".

## Team-Ordner und der Datei-Umzug (S2, gemessen am 2026-08-11)

Gemessen an einem echten Team-Ordner (`occ groupfolders:create`, Groupfolders 22.0.6, NC 34) mit
den beiden Unterordnern `90_Austausch` und `91_Tickets_intern`. Spike:
`spike/S2-datei-umzug.spec.ts` und `spike/S2b-rechte.spec.ts`.

### §11.3 ist beantwortet: **Ja, der Umzug ist verlustfrei**

| Was | Vor dem Umzug | Nach dem Umzug |
|---|---|---|
| Datei-ID | 1567 | **1567 — unverändert** |
| Versionen | 2 | **2** |
| Einzelfreigabe an den Gast | 1 | **1, am neuen Ort** |
| Freigaben am alten Ort | — | 0 |

`MOVE` zwischen zwei Unterordnern desselben Team-Ordners antwortet mit 201 und erhält alle drei
Eigenschaften. **Die technische Voraussetzung für den Auto-Move aus Phase 7b ist damit gegeben** —
die Rückfallebene „kleiner Team-Ordner je Projekt" wird nicht gebraucht.

### Der Befund, der mehr zählt als die Frage

Die Gegenprobe hat etwas Wichtigeres gezeigt: **Der Gast erreichte die Datei im internen Ordner
auch dann, als gar keine Freigabe mehr auf ihr lag.** Grund ist nicht der Umzug, sondern der
Team-Ordner selbst — er zeigt allen Mitgliedern seiner Gruppe **alles** darin, Unterordner
eingeschlossen.

**Betrifft ausschließlich den Team-Ordner-Aufbau — nicht die Struktur, die die Produktbeschreibung
vorsieht.** Wer `90_Austausch` und `91_Tickets_intern` als zwei Unterordner **eines Team-Ordners**
anlegt und die Kundenseite in dessen Gruppe aufnimmt, hat keine Trennung: Jeder interne Anhang liegt
offen, ohne dass irgendwo ein Fehler auftaucht. Die Sichtbarkeitsregel der App stimmt dabei
weiterhin — sie regelt Vorgänge, nicht Dateien. Der Ablageort ist die Sichtbarkeit (§5.18), und
genau deshalb muss der **Ablageort** die Trennung tragen.

**Es lässt sich sauber trennen, aber nur ausdrücklich.** Mit erweiterten Rechten:

```bash
occ groupfolders:permissions <id> --enable
occ groupfolders:permissions <id> 91_Tickets_intern -u <kundenkonto> -- -read
```

Danach gemessen: `90_Austausch` → HTTP 207, `91_Tickets_intern` → **HTTP 404**. Der Ordner ist für
das Kundenkonto nicht mehr vorhanden, nicht bloß leer.

### Die vorgesehene Struktur hat das Problem nicht

**Die Produktbeschreibung beschreibt bereits den sicheren Aufbau**, und zwar aus einem anderen
Grund als diesem hier — er fällt als Nebenwirkung ab:

- **§16:** Ein Projektordner je Projekt mit fester Struktur (`00_Angebot_Abrechnung`, `10_Input`,
  `20_Inhalte`, …, `90_Austausch`, `99_Archiv`). `91_Tickets_intern` kommt als **Geschwisterordner**
  dazu, „nur von der App gefüllt".
- **§19:** „Freigabe von `90_Austausch` legt CPC von Hand an." Geteilt wird **genau dieser eine
  Unterordner** — der Projektordner als Ganzes nie. Er enthält schließlich auch
  `00_Angebot_Abrechnung`, das der Kunde laut §16 „nie" sehen darf.

Damit ist `91_Tickets_intern` für die Kundenseite nicht verschlossen, sondern **nicht vorhanden**:
Es wurde ihr nie gegeben. Es gibt keinen Einrichtungsschritt, den jemand vergessen könnte.

Und daraus folgt auch: **Voller Zugriff auf `90_Austausch` ist unbedenklich.** Alles darin gehört
per Konstruktion zu Vorgängen für „Alle Beteiligten" — die App legt dort nichts anderes ab.

| Aufbau | Trennung | Zusatzschritt |
|---|---|---|
| **Projektordner, nur `90_Austausch` freigegeben** (§16/§19) | von selbst | keiner |
| Team-Ordner mit beiden Unterordnern | nur mit erweiterten Rechten | ein Befehl je Projekt |

→ Ein Setup-Check bleibt sinnvoll — die App kennt beide Ordner-IDs und könnte prüfen, ob ein
externes Mitglied den internen Ordner erreicht. **Dringlich ist er nicht:** Er sichert einen Aufbau
ab, den die Produktbeschreibung gar nicht vorsieht, und zahlt sich erst auf fremden Installationen
aus.

> **Zur Entstehung dieser Notiz:** Der Team-Ordner war der Aufbau des Spikes, weil §11.3 wörtlich
> nach „zwei Unterordnern desselben Team-Ordners" fragt. Der Befund wurde daraufhin zunächst
> behandelt, als wäre das die Struktur bei CPC. Ist er nicht — richtiggestellt am 2026-08-11.

### Und warum §5.18 „keine von der App angelegten Freigaben" jetzt doppelt richtig ist

Die Einzelfreigabe **wandert mit der Datei**. Hätte die App beim Anhängen eine Freigabe erzeugt,
trüge ein späterer Auto-Move sie in den internen Ordner mit — und der Kunde behielte Zugriff auf
eine Datei, die gerade intern geworden ist. Die Regel war als Vereinfachung gedacht; sie ist
zugleich die Bedingung, unter der der Auto-Move überhaupt sicher sein kann.

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
