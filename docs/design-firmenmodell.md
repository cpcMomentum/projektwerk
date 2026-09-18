# Design: Firmenmodell — Firma pro Person, Kunde pro Projekt

## Problem Statement

Das aktuelle Modell kennt pro **Board** genau zwei Firmen (`orgInternal`, `orgExternal`)
und leitet die angezeigte Firma einer Person aus ihrer **Rolle** ab (intern → `orgInternal`,
extern → `orgExternal`). Das bricht, sobald auf einer Seite mehrere Firmen mitarbeiten.

Konkreter Fall **MI 514**: Kunde ist das MI, aber Timo (Subunternehmer) kommt von **Nect** —
eine dritte Firma. Timo ist „Kundenseite" (`external`) und würde als die eine Kundenfirma
angezeigt. Zusätzlich zeigt die Personensuche bei Gästen die interne Konto-ID
(`e0dd0ae0f470a159528a2a…`) statt etwas Lesbarem.

**Bewusste Abgrenzung (Kernentscheidung):** Die Sichtbarkeit bleibt **unangetastet**. Die
Produktbeschreibung (§75–80) legt das Zwei-Seiten-Modell absichtlich fest — `internal` ist
symmetrisch, ein internes Kundenseiten-Ticket sieht nur die Kundenseite. MI und Nect werden
**gleich behandelt** (gemeinsam „Kundenseite", keine Ticket-Trennung). Es geht **nur um die
Anzeige** der Firma, nicht um eine Trennung der Sichtbarkeitssphären.

## Gewählter Ansatz: Weg 1 — Firma an die Person, Kunde ans Projekt (nur Anzeige)

Drei entkoppelte Konzepte:

1. **Rolle** (`internal`/`external`) — bleibt exakt wie heute, trägt allein die Sichtbarkeit.
2. **Firma pro Mitglied** (neu) — reine Anzeige, ersetzt die Ableitung aus der Board-Firma.
3. **Kunde pro Projekt** (neu) — Label „für wen läuft das Projekt", filterbar im Überblick.

### Datenmodell

**`pwerk_members` — neue Spalte `company`** (`?string`, nullable)
- Trägt die Firma der Person je Mitgliedschaft (nicht am NC-Konto — analog `role`, `displayName`).
- Anzeige-Quelle für die Sekundärzeile überall, wo bisher `orgInternal`/`orgExternal` per Rolle stand.

**`pwerk_projects` — neue Spalte `customer`** (`?string`, nullable)
- Der Auftraggeber des Projekts (MI). Optional (interne Vorhaben brauchen keinen Kunden).
- Basis für den Überblick-Filter „alle Projekte für Kunde X".

**`pwerk_boards` — `orgInternal`/`orgExternal` entfallen**
- Werden per Migration in die neuen Felder überführt (siehe Migration).

### Selbst-füllende Auswahl (Firma UND Kunde)

Beide Felder sind **Auswahl mit Freitext-Neuanlage** (Combobox): vorhandene Werte vorschlagen,
neuen tippen möglich. Die Vorschlagsliste entsteht aus **bereits verwendeten** Werten (distinct
über die Projekte/Mitglieder des Betrachters) — **kein** separater Verwaltungsbildschirm, keine
neue Stammdaten-Tabelle. Konsistenz („Nect" überall gleich) ohne Pflegeaufwand.

**Vorbelegung beim Hinzufügen eines Mitglieds** (Reibung minimieren):
- Rolle `internal` → `company` = eigene Firma (aus bisherigem `orgInternal`, i. d. R. cpcMomentum).
- Rolle `external` → `company` = `customer` des Projekts (MI).
- Nur der Sonderfall (Timo → Nect) wird überschrieben.

### API-Design

- `member#add` / `member#update`: zusätzliches optionales Feld `company`.
- `Member::jsonSerialize()`: `company` mit ausgeben.
- Projekt-Endpunkte (anlegen/bearbeiten): Feld `customer`.
- Überblick/Boards-Liste: `customer` mitliefern; Distinct-Liste für die Vorschläge bereitstellen
  (eigener leichter GET oder aus der bestehenden Overview-Antwort ableiten).
- Personensuche (`memberSearch`): Sekundärwert = **E-Mail** statt `userId`; fehlt sie (Gast),
  nur Anzeigename, ggf. Kennzeichnung „Gast". Die Hash-ID verschwindet aus der Anzeige.

### UI-Komponenten

- **BoardSettingsView**: „Firma (eigene Seite)/(Kundenseite)" entfällt; stattdessen je Mitglied
  ein Firmen-Auswahlfeld (vorbelegt). Projekt bekommt ein **Kunde**-Feld. Personensuche zeigt
  E-Mail statt Hash (`:449` `person.userId` → E-Mail).
- **CreateBoardWizard**: analog — statt zwei Board-Firmen die Kunde-Angabe + Mitglieder-Firmen.
- **Anzeige-Stellen** (Firma als `subname`/Sekundärzeile) auf `member.company` umstellen:
  `StepList.vue`, `CreateTicketDialog.vue`, `TicketDetail.vue` (und alle, die heute
  `roleOf(...) === 'internal' ? orgInternal : orgExternal` verwenden).
- **Überblick (BoardsView/Overview)**: Kunden-**Filter** („alle Projekte für Kunde X").

### Migration (Version000021)

Startbelegung, sodass die Anzeige danach **exakt** wie vorher aussieht:
- Für jedes Mitglied: `company` = `role === 'internal' ? board.orgInternal : board.orgExternal`.
- Für jedes Projekt: `customer` = `orgExternal` **eines** seiner Boards.
  - Offen: bei mehreren Boards mit unterschiedlichem `orgExternal` (durch #246 möglich) —
    Regel festlegen (erstes nicht-leeres / Board mit niedrigster ID). Im Plan klären.
- `orgInternal` wird nicht in ein Feld überführt, sondern dient als Default-Quelle für die
  Mitglieder-`company` (siehe oben); die Board-Spalten werden nach dem Backfill entfernt
  (oder zunächst nur nicht mehr gelesen — im Plan entscheiden: Spalte droppen vs. verwaisen lassen).

## Verworfene Alternativen

- **Weg 2 — echte Drei-Parteien-Trennung (MI ≠ Nect in der Sichtbarkeit).** Verworfen: bricht das
  bewusst gesetzte Zwei-Seiten-Modell und die einzige Stelle, an der Sichtbarkeit definiert ist
  (Produktbeschreibung §75–80, CLAUDE.md „Sichtbarkeit ist EINE Bedingung an EINER Stelle").
  Groß, riskant, hier nicht gebraucht (MI und Nect werden gleich behandelt).
- **Freitext-Firma ohne Vorschläge.** Verworfen: Schreibvarianten („MI"/„BMI") zerstören den
  Kunden-Filter.
- **Eigene Firmen-/Kunden-Stammdaten-Entität mit Verwaltungs-UI.** Für jetzt überdimensioniert;
  die selbst-füllende Auswahl reicht. Später bei Bedarf (Ansprechpartner, Rechnungsadresse)
  zu einer echten Entität ausbaubar, ohne den jetzigen Aufbau zu verbauen.

## Offene Fragen (für den Plan)

- Migrations-Regel für `customer` bei Projekten mit mehreren Boards und uneinheitlichem `orgExternal`.
- `orgInternal`/`orgExternal`-Spalten am Board: sofort droppen oder erst verwaisen lassen?
- Vorschlagsliste (distinct Firmen/Kunden): eigener Endpunkt oder aus Overview ableiten?
- E-Mail in der Personensuche: aus `IUserManager`/Account-Daten — Sichtbarkeit/Datenschutz prüfen.

## Akzeptanzkriterien

- [ ] Ein externes Mitglied kann eine eigene Firma tragen (Timo → „Nect"), unabhängig vom Kunden (MI).
- [ ] Die Firma erscheint pro Person konsistent in Ticket-Anzeige und Mitgliederliste.
- [ ] Die Personensuche zeigt **keine** User-ID-Hashes mehr, sondern E-Mail/Name.
- [ ] Das Projekt trägt einen optionalen **Kunden**; der Überblick lässt danach filtern.
- [ ] Firma und Kunde sind selbst-füllende Auswahlfelder (Vorschlag vorhandener Werte + Neuanlage).
- [ ] Nach der Migration sieht jede bestehende Anzeige aus wie vorher (kein sichtbarer Bruch).
- [ ] Die Sichtbarkeit (`internal`/`external`) ist unverändert — belegt durch die bestehenden
      Sichtbarkeits-/LeakMatrix-Tests, die grün bleiben.
