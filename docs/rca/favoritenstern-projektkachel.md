# RCA — Favoritenstern verschiebt den Projektnamen (Projektkachel im Überblick)

**Datum:** 2026-09-08
**Betroffen:** `src/components/ProjectTiles.vue` (Projektkacheln im Überblick, #226)
**Symptom (Produktiv v0.4.11, iPhone/Safari):** Bei angepinnten Projekten sitzt der
Favoritenstern **zentriert auf einer eigenen Zeile über** dem Projektnamen und drückt
Name und Firmenzeile nach unten. Erwartet war „★ Valore" in einer Zeile.

## Grundursache

Nextcloud legt eine **globale Regel** auf alle Icon-Komponenten:

```css
.material-design-icon { display: flex; justify-content: center; }
```

Der Stern (`vue-material-design-icons/Star.vue`) ist so ein `.material-design-icon`.
Sein Container `.pw-tile__ident` ist **keine** Flex-Box, sondern normaler Blockfluss.
Dadurch wird der Stern zu einem **blockbreiten, zentrierten Kasten auf eigener Zeile** —
statt inline vor dem Titel zu stehen.

Im Code war der Stern klar inline gemeint: `.pw-tile__pin` trug `vertical-align: -2px`
und `margin-inline-end: 3px` — Feinheiten, die nur für ein inline-Element wirken. NCs
Global-Regel hat das überschrieben. Es ist also ein überschriebenes Detail, kein
Design-Schnitzer.

### Verifiziert im Browser (nicht vermutet)

Computed styles am echten Rendering (Dev-Instanz, angepinntes Board):

- `.pw-tile__pin`: `display: flex`, `justify-content: center`, 14×14, **zentriert, eigene
  Zeile über dem Titel** (gemessen: Stern-Unterkante über Titel-Oberkante).
- Der Titel steht eine Zeile tiefer, linksbündig.

## Umfang — alle Kachel-Marker geprüft

Nur der Stern ist betroffen. Die übrigen „Status"-Marker am Kachelrand sind CSS/Text,
kein `.material-design-icon`, und sitzen zudem in Flex-Containern:

| Marker | Bauart | Zustand |
|--------|--------|---------|
| Favoritenstern | Icon-Komponente in Nicht-Flex-Container | **kaputt** |
| Zustand („überfällig/steht still/läuft", `.pw-dot`) | CSS-Punkt (`::before`) + Text, Flex-Kind | ok |
| „N diese Woche" (▲ + Zahl) | Text-Dreieck, Flex-Kind | ok |
| Legende / Balkenstriche | CSS-Kästchen (`<i>`) | ok |

Feinheit: Zustand-Punkt und Wochen-Marke zeigen in den computed styles ebenfalls
`display: flex` — aber *harmlos*, weil sie Flex-**Kinder** der rechten Spalte
(`.pw-tile__headcol`) sind (normale Blockifizierung, rendern wie gewollt). Der Stern
dagegen wird erst durch NCs **Icon-Regel** zum Block. Gleiche Symptomfarbe, andere
Ursache.

## Umfang — ganze App gescannt (latentes Muster?)

Von 80 Icon-Nutzungen sitzen 20 potenziell inline neben Text. Geprüft wurde je der
Eltern-Container:

- `.pw-vis`, `.pw-counts`, `.pw-due`, `.pw-frist__label`, `.pw-crumb` → `inline-flex`
- `.pw-abschnitt__drop`, `.pw-folderpick__item`, `.pw-settings__status` → `flex`
- Sichtbarkeits-Auswahl → `#icon`-Slot von `@nextcloud/vue`

**Alle** anderen Icon-neben-Text-Stellen stehen in einer Flex-Box — dort greift NCs
Block-Regel nicht (Flex-Kind wird harmlos blockifiziert). `.pw-tile__ident` ist die
**einzige** Ausnahme. Es gibt also keine weiteren kaputten Stellen; das Muster „Icon +
Text in Flex-Box" ist in der App sonst durchgängig eingehalten.

## Fix

Den Namen samt Stern in eine kleine Flex-Zeile packen — genau wie `.pw-vis`/`.pw-crumb`
es überall sonst machen —, statt den Stern per Sonderregel inline zu zwingen. Damit ist
die Kachel mit dem Rest der App konsistent und „★ Valore" steht sauber in einer Zeile;
die Firmenzeile bleibt als Block darunter.

Kein separater Sonderfall, kein globaler Eingriff in `.material-design-icon` (der würde
die vielen korrekt zentrierten Icon-Buttons treffen).

## Lehre

Ein rohes `vue-material-design-icons`-Icon, das **inline neben Text** stehen soll, gehört
in einen Flex-Container (`inline-flex`/`flex` + `align-items: center`). Ohne den erbt es
NCs globales `display: flex; justify-content: center` und wird zum zentrierten Block auf
eigener Zeile. In Buttons/Slots ist genau dieses Zentrieren gewollt — deshalb fällt es
nur beim Icon-neben-Text auf.
