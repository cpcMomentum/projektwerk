# Design: Arbeitsschritt- & Detail-Interaktion (#344, inkl. #327)

**Stand:** 2026-10-02 · **Status:** abgestimmt mit Axel

## Problem

Die Bedienung der Arbeitsschritte im Vorgang-Detail (`StepList.vue`) ist unstimmig:

- Ein Klick auf den Titel hakt den Schritt ab, weil der Titel im Label der
  Checkbox (`NcCheckboxRadioSwitch`) steht. Erwartet wird, dass er den Schritt öffnet.
- In den Schritt kommt man nur über „Zuweisen oder Frist setzen“ bzw. den Stift.
  Ein sichtbarer Einstieg fehlt.
- Eingabefeld „Neuer Arbeitsschritt“ und Plus stehen nicht auf gleicher Höhe.
- Beschreibung ist ein Einzeiler (schneidet ab), Ergebnis eine feste 3-Zeilen-Box.
- Das Kommentarfeld steht dauerhaft groß da, obwohl #99 es ruhend kompakt wollte.
- #327: Im Bearbeiten-Modus steht der alte Titel weiter über dem Titelfeld.

## Gewählter Ansatz: Checklisten-Prinzip, Bearbeiten an Ort und Stelle

| Aktion | Wirkung |
|---|---|
| Klick aufs **Kästchen** | hakt ab / hebt Abhaken auf, sonst nichts |
| Klick auf den **Titel** | öffnet den Schritt an seiner Stelle in der Liste (bisheriger Bearbeiten-Modus) |
| Enter / Plus in „Neuer Arbeitsschritt“ | legt an, Fokus bleibt in der Eingabezeile (schnelles Runtertippen) |

Es öffnet sich **nichts automatisch** nach dem Anlegen; Details erfasst man per
Titel-Klick. Der geöffnete Schritt ist der bestehende Bearbeiten-Modus (Titel,
Zuständig, Frist, Beschreibung, Ergebnis, „Fertig“).

**Speichern unverändert:** Zuständig und Frist sofort beim Ändern; Titel,
Beschreibung, Ergebnis gepuffert über „Fertig“ bzw. Strg/Cmd+Enter.

### UI-Änderungen

1. **Titel aus dem Checkbox-Label lösen.** Checkbox bekommt ein unsichtbares Label
   (`hidden-visually`, „Erledigt: {title}“); der Titel wird ein eigener
   Knopf, der `beginEdit(step)` auslöst. Tastatur: Titel per Tab erreichbar,
   Enter/Leertaste öffnet.
2. **#327:** Titel-Anzeige nur `v-if="editing !== step.id"`, wie Beschreibung und
   Ergebnis.
3. **Einstieg sichtbar:** Der Titel ist der Einstieg (Hover/Fokus-Zustand wie ein
   Link). Stift und „Zuweisen oder Frist setzen“ bleiben als zweiter Weg.
4. **Auto-Grow:** Beschreibung wird `NcTextArea` (statt `NcTextField`); Beschreibung
   und Ergebnis starten einzeilig, `resize="none"`, wachsen per Muster aus
   `TicketDetail.vue` `autoGrowText()` (#160) bis zu einem CSS-Deckel, danach
   scrollt das Feld. Enter in der Beschreibung macht damit einen Zeilenumbruch;
   Speichern über Strg/Cmd+Enter oder „Fertig“ (wie beim Ergebnis).
5. **Ausrichtung** von Eingabefeld und Plus in `.pw-step--new` (im Browser messen,
   nicht raten).
6. **Kommentarfeld:** ruht einzeilig, wächst beim Hineinklicken / mit Text.
   Befund vorab: `NcRichContenteditable` rendert ein `div[contenteditable]`, keine
   `<textarea>`; die #99-Regel `.pw-comment-new textarea { min-height }` greift
   vermutlich nicht. Im Browser verifizieren, dann auf das tatsächliche Element
   (`.rich-contenteditable__input`) zielen.

7. **Vorgangsfenster oben verankert** (nachträglich, mit Axel abgestimmt): Das
   zentrierte `NcModal` verschob bei jeder Höhenänderung den Inhalt um die halbe
   Differenz. Schrumpfte das Kommentarfeld beim Wegklicken, sprang der
   Schritt-Titel unter dem Mauszeiger weg und der Klick ging verloren (gemessen:
   54 px). `.modal-wrapper:has(.pw-detail)` richtet jetzt oben aus.

### Nachgang

Die Optik des Vorgangsfensters als Ganzes (Schriftvarianten, fünf verschiedene
Bearbeitungsmuster, Feld-Beschriftungen, Breite „Fällig bis") ist bewusst nicht
Teil von #344, sondern geht in ein Design-Review unter #345.

### Datenmodell / API

Keine Änderung. Reines Frontend (`StepList.vue`, `CommentList.vue`, `src/css/app.css`,
`StepList.spec.ts`).

## Verworfene Alternativen

- **Schritt als Unteransicht im Vorgang-Modal** (Inhalt ersetzt, „Zurück“):
  großer Umbau, lohnt erst, wenn Schritte eigenen Inhalt (Kommentare, Anhänge)
  bekommen. Davon steht nichts im Issue.
- **Eigener Dialog über dem Vorgang:** Modal im Modal, bekannte Probleme mit
  Popups (`menuContainer`) und Fokusführung.
- **Schritt nach dem Anlegen automatisch öffnen:** bremst das schnelle Anlegen
  mehrerer Schritte, das heute der Hauptvorteil der Eingabezeile ist.
- **„+ Kommentar“-Knopf vor dem Feld:** ein Klick mehr; das ruhend kompakte Feld
  erreicht dasselbe.

**Zur UI-Regel „Aufklapp-Zeilen abgelehnt“:** Das Bearbeiten an Ort und Stelle
existiert bereits und bleibt; der Schmerz war der versteckte Einstieg, nicht das
Aufklappen. Bewusst so entschieden (Axel, 2026-10-02).

## Abgrenzung

- Vorgangsbeschreibung (#160) bleibt unangetastet.
- Vereinheitlichung aller übrigen Formulare/Auswahlelemente: #345.

## Akzeptanzkriterien

- [ ] Klick aufs Kästchen hakt ab; Klick auf den Titel öffnet, ohne abzuhaken
- [ ] Titel per Tastatur erreichbar und mit Enter zu öffnen; Checkbox hat sprechendes Label
- [ ] Im geöffneten Schritt steht der Titel nur im Eingabefeld (#327)
- [ ] Nach Enter/Plus bleibt der Fokus in „Neuer Arbeitsschritt“, kein Schritt öffnet sich
- [ ] Eingabefeld und Plus stehen auf gleicher Höhe (gemessen)
- [ ] Beschreibung und Ergebnis starten einzeilig und wachsen beim Tippen
- [ ] Kommentarfeld ruht kompakt, wird beim Fokus/mit Text größer, schrumpft mit Entwurf nicht
- [ ] Neue Texte in `t()`, `npm run l10n:check` grün, Sprache umgeschaltet und angesehen
- [ ] `StepList.spec.ts` angepasst (Titel-Klick öffnet, Checkbox toggelt)
