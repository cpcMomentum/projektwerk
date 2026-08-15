# Konzeptnotiz: Das Überblick-Dashboard

> Kreativsession zu [#116](https://github.com/cpcMomentum/projektwerk/issues/116)
> (aktive Projekte im Überblick). Verwandt: [#115](https://github.com/cpcMomentum/projektwerk/issues/115)
> (Projekte in der Seitenleiste anpinnen), [#114](https://github.com/cpcMomentum/projektwerk/issues/114)
> („wartet auf Kunde" ohne Arbeitsschritte).
>
> Status: Ideensammlung, noch kein Plan. Bau erst nach #112 (Überblick als
> Einstieg) und #114 (Alters-Rechnung).

## Die Kernfrage

ProjektWerk ist kein generisches Projekt-Tool. Seine DNA: Dienstleister und Kunde
teilen sich ein Board, jeder Vorgang hat eine Sichtbarkeit, und der Signalzustand
der ganzen App ist „wer ist am Zug". Ein 08/15-Dashboard zeigt „12 offene
Aufgaben, 3 überfällig". Das sagt hier das Falsche.

Die richtige Frage lautet nicht *wie viel Arbeit liegt da*, sondern:

> **Wo liegt der Ball, und wo liegt er zu lange?**

Der Wert steckt im Hin und Her zwischen Dienstleister und Kunde, quer über alle
Projekte.

## Vier Konzeptrichtungen

- **A) Der Staffelstab (Fluss zuerst).** Nicht nach Projekten sortiert, sondern
  nach Ballbesitz quer über alles: *Bei dir* · *Beim Kunden* · *In Arbeit, keiner
  blockiert*. Der Alarm ist nicht die Zahl, sondern das Alter: wie lange liegt der
  Ball schon auf einer Seite. Greift in #114.
- **B) Projekt-Ampeln (Projekt zuerst).** Jedes aktive Projekt eine Karte mit
  Signal. Gelb nicht wegen „viel offen", sondern weil der Kunde seit 8 Tagen auf
  einer Freigabe sitzt oder seit 5 Tagen nichts passiert. Sortiert nach „braucht
  Aufmerksamkeit zuerst".
- **C) Der Stillstand (Zeit zuerst).** Bewegung gegen Starre. Ein Projekt mit 12
  Vorgängen und 0 überfällig sieht in jeder Zähl-Ansicht gesund aus, kann aber tot
  sein. Macht „ohne Bewegung seit X Tagen" zum Bürger erster Klasse.
- **D) Der Morgen-Blick (Delta zuerst).** Der Überblick ist der Einstieg, auf dem
  Handy die Startseite. „Was hat sich seit gestern getan" als schmaler Feed oben.

## Vorschlag: A als Rückgrat, B als Körper, D als Kopfzeile

Nicht eine Richtung wählen, sondern schichten:

- **Kopfzeile (D):** eine ruhige Delta-Zeile, „Seit gestern: 2 Freigaben erteilt,
  1 neuer Kunden-Kommentar".
- **Portfolio-Blick (A):** zwei, drei Aggregatzahlen, die den Ball zeigen. „Bei
  dir: 4 Vorgänge in 3 Projekten. Kunde blockiert: 6 Vorgänge, ältester seit 9
  Tagen."
- **Deine Projekte (B, geschärft durch C):** aktive Projekte als Karten, sortiert
  nach Aufmerksamkeit. Je Karte:
  - Ballanzeige: bei dir / beim Kunden / läuft
  - „Wartet auf Kunde seit X Tagen" (die Killer-Zahl, #114)
  - „Ohne Bewegung seit X Tagen" (der stille Tod)
  - offene Vorgänge (klein, sekundär)
  - letzter Kunden-Kommentar als menschlicher Anker

## Nordstern-Metrik: Alter schlägt Anzahl

Der rote Faden der App ist die unsichtbaren Fehler: grüne Tests bei falschem
Ergebnis. Ein Zähl-Dashboard hat genau diese Krankheit. „0 überfällig" beruhigt,
wo es warnen müsste, weil ein Projekt still stehen kann, ohne dass ein Zähler
ausschlägt.

**Die Aufgabe dieses Dashboards ist es, den leisen Stillstand laut zu machen.**
Sortiere nach Alter, nicht nach Menge.

## Was wir bewusst NICHT bauen

- **Erfundene Fortschrittsbalken.** ProjektWerk hat keine Story Points. Ein
  „73 %"-Balken wäre eine Lüge mit Nachkommastelle.
- **Eitelkeitszahlen.** „127 Vorgänge gesamt", Burndown-Charts.
- **Doppelung mit „Meine Aufgaben".** Dessen Achse ist die eigene Todo-Liste, die
  Achse hier ist das Portfolio. Zeigt das Dashboard nur wieder meine Schritte, ist
  es überflüssig.
- **Ampelfarben, die nur „viel/wenig" heißen.** Farbe muss „handeln oder nicht"
  bedeuten, kräftig und sparsam.
- **Ruhe verschweigen.** Ein Satz „Nichts hängt, alles in Bewegung" ist ein
  Feature, kein Leerzustand.

## Ein neuer Gedanke: der geteilte Blick

Weil Kunde und Dienstleister dasselbe Board sehen, kann das Dashboard fragen: weiß
der Kunde, dass er am Zug ist? Ein Vorgang „wartet auf Kunde", zu dem nie eine
Benachrichtigung ging (#98), ist ein anderer Fall als einer, der informiert wurde
und trotzdem liegt. Ersteres ist das eigene Versäumnis, letzteres seins. Nicht für
Version eins, aber die Richtung, in die nur dieses Produkt gehen kann.

## Wie #115 und #116 ineinandergreifen: was heißt „aktiv"?

- „Nicht archiviert" ist zu grob, bei über 20 Projekten kein Überblick mehr.
- Vorschlag: Standard ist „hat Bewegung oder offene Punkte in den letzten N Tagen".
  Die in #115 angepinnten Projekte stehen immer oben. Und: ein Projekt, das ohne
  Bewegung verstummt, taucht auf, auch wenn es nicht angepinnt ist. Genau das
  vergessene Projekt willst du sehen.

## Kleinste liebenswerte Version

Ein Abschnitt „Deine Projekte" unter dem bestehenden Überblick. Karten, sortiert
nach Aufmerksamkeit (wartet-auf-Kunde-Alter absteigend, dann Stillstand, dann
Rest), je Karte Ball plus Alter plus offene Zahl. Pins nach oben. Keine neuen
Charts.

**Machbarkeit:** Der `overviewStore` rechnet schon „Zahlen je Projekt, Namen je
Projekt". Das Datenrückgrat steht also teilweise. Was fehlt, ist das Alter
(wartet-seit, Stillstand-seit), und das ist dieselbe Rechnung, die #114 ohnehin
braucht. #114, #116 und der Alters-Gedanke sind derselbe Hebel.

## Offene Detailfragen (fürs spätere Design)

- Karten oder Zeilen? (Axels früherer Befund am Überblick: „zu unstrukturiert".)
- Genaue Definition von „aktiv" und der Schwelle N.
- Wo sitzt die Delta-Kopfzeile, und woran misst sie „seit gestern" bzw. „seit
  deinem letzten Besuch"?
