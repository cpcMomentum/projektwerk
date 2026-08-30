# Recherche: Projekt-/Portfolio-Dashboards vergleichbarer Werkzeuge

> Vergleichsrecherche am 2026-08-27 für das Dashboard-Redesign (siehe
> `dashboard-projekt-uebersicht-konzept.md`). Ausgewertet: **Linear, GitHub
> Projects, Asana (Portfolios), Basecamp, Height, Monday.com**. Jira und
> OpenProject bewusst ausgeschlossen (zu schwergewichtig). Nur offizielle
> Doku/Produktseiten als Quelle.

## Die wichtigsten Befunde je Werkzeug

- **Linear:** Trennt **Status** (Lebenszyklus) strikt von **Health** (Zustand:
  grün on track / gelb at risk / rot off track / **grau = kein Update**).
  „My Issues" nach **Handlungsdringlichkeit** gruppiert, nicht alphabetisch;
  eigener „Recent activity"-Tab. Owner/Lead nur auf Projektebene, keine
  Schritt-Rolle. [status](https://linear.app/docs/project-status) ·
  [initiatives](https://linear.app/docs/initiatives) ·
  [my-issues](https://linear.app/docs/my-issues)
- **GitHub Projects:** Ein Project bündelt Vorgänge **repo-übergreifend**
  (Table/Board/Roadmap). Spalten zeigen **Kartenanzahl** + WIP-Limit (Engpass-
  Signal). Insights trennt **Current** (Verteilung) von **Historical** (Verlauf),
  Default = ein Burn-up, kein Chart-Baukasten aufgedrängt.
  [board](https://docs.github.com/en/issues/planning-and-tracking-with-projects/customizing-views-in-your-project/customizing-the-board-layout) ·
  [insights](https://docs.github.com/en/issues/planning-and-tracking-with-projects/viewing-insights-from-your-project/about-insights-for-projects)
- **Asana (Portfolios):** Direktes Ebene-1-Pendant. Zeile je Projekt mit Owner,
  Status, Due, **Fortschritt** und **automatischem Rollup** numerischer Felder.
  Progress-Tab zählt Projekte je Zustand (on/at risk/off). Nur **5 Felder
  gleichzeitig sichtbar** trotz 100 möglicher. Health ist **manuell gepflegt**.
  [portfolio-views](https://help.asana.com/s/article/portfolio-views) ·
  [progress](https://help.asana.com/s/article/portfolio-progress-and-reporting)
- **Basecamp:** Bewusst schlicht, kundentauglich. **Hill Chart** (Uphill =
  Unbekanntes klären, Downhill = Ausführung) macht Denkarbeit sichtbar, rein
  **manuell**. „Hilltop" zeigt alle Projektzustände auf einem Screen und **hebt
  zuletzt aktualisierte hervor**. Kein Zahlen-Dashboard.
  [hill-charts](https://5.basecamp-help.com/article/1078-hill-charts)
- **Height:** „Eine Datenbasis, viele gruppierbare Tabellen". **Section by**
  beliebiges Attribut (auch Sichtbarkeit/Status), Spalten je Liste wählbar,
  eigener „Assigned to me"-Einstieg.
  [overview](https://help.height.app/en/articles/3606831-height-overview)
- **Monday.com:** Sehr visuell. **Battery-Widget** aggregiert Status **über
  mehrere Boards** in einen Fortschrittsbalken (grau = offen, blau = fertig).
  Warnung: 30+-Widget-Kultur verführt zu Deko ohne Handlungsbezug.
  [battery](https://support.monday.com/hc/en-us/articles/360002159360-The-Battery-Widget) ·
  [overview-widget](https://support.monday.com/hc/en-us/articles/360007078739-The-Overview-Widget)

## Was wir übernehmen

1. **Zustand („Health") getrennt vom Status.** Neben Neu/Offen/Wartet/Erledigt
   ein eigenes Zustandssignal je Projekt: „wo liegt der Ball" ist nicht aus
   Status-Zahlen ablesbar. (Linear)
2. **Zustand aus Daten ableiten, nicht manuell pflegen.** Größtes Anti-Muster der
   Recherche: manuell gepflegte Ampeln verrotten (Asana/Basecamp). Wir leiten
   Rot/Gelb aus dem Wartet-Anteil und dem Alter offener Vorgänge ab; **grau =
   niemand am Zug / steht still** (Linear-Idee, aber automatisch).
3. **„Wartet auf Kunde" als eigenes, sichtbares Signal**, nicht nur eine Zahl.
   Das ist die Kernaussage der App.
4. **Verteilung als eine gestapelte Leiste** statt vier lose Zahlen (GitHub
   Card-Counts, Monday Battery). Die Zahlen bleiben (Axel will „Neu" sehen), die
   Leiste macht das Verhältnis auf einen Blick lesbar.
5. **„Meine Maßnahmen" nach Dringlichkeit sortiert, zwei Obligations-Gruppen**
   (zugewiesener Schritt vs. Verantwortung). Diese Trennung hat **keines** der
   sechs Werkzeuge nativ; sie ist ProjektWerks Eigenheit.
6. **Recency als eigenes, benanntes Element** („zuletzt bearbeitet", „letzte 5
   erledigt"), kein Graph. (Basecamp Hilltop, Linear Recent activity)
7. **Mehr-Board-Aggregation ist gerade wegen der fixen 4 Status sauber.** Weil
   Neu/Offen/Wartet/Erledigt über alle Boards dieselbe Bedeutung haben, summieren
   sie sich verlustfrei in einen Balken (Monday Battery, GitHub repo-übergreifend).

## Was wir bewusst weglassen

- Velocity-/Burn-up-Prognosen (Linear graph, GitHub burn-up): messen Liefertempo,
  nicht Ballbesitz.
- Gantt/Roadmap-Timeline als zentrales Element (GitHub Roadmap, Asana Timeline).
- Widget-Flut / Chart-Baukasten (Monday 30+ Widgets, GitHub Insights-Editor).
- Story-Point-/Zahlen-Summen je Spalte („wie viel Arbeit" statt „wer ist am Zug").
- Manuelle Health-Pflege als Pflicht.
