# RCA: `action_required`-Läufe nach Auto-Fix-Commits des Review-Workflows

> **Status:** Analyse **nicht abgeschlossen** — eine Hypothese offen, sie braucht einen Blick,
> den mein Zugang nicht hat
> **Datum:** 2026-08-09
> **Severity:** Low (Häufigkeit ~0,6 %, selbstheilend durch erneutes Pushen)

---

## 1. Problem-Zusammenfassung

**Symptom:** Nachdem der Workflow `Claude Code Review` einen Auto-Fix-Commit auf den PR-Branch
gepusht hat, starten alle Prüf-Workflows erneut — und bleiben mit `conclusion: action_required`
und 0 s Laufzeit stehen. Sie laufen nie.

**Wirkung:** Der PR zeigt seine Prüfungen immer für den **aktuellen** Head-Commit. Der ist nach
dem Bot-Push ein anderer, und dort ist nie etwas gelaufen. `gh pr checks` meldet dann
„no checks reported" — nicht rot, sondern gar nichts. Der PR sieht ungeprüft aus, obwohl
praktisch derselbe Code Sekunden zuvor sechsmal grün war.

**Reproduktion:** Nicht gezielt reproduzierbar. Zwei bekannte Vorkommen bei geschätzt 300+
Bot-Pushes.

---

## 2. Timeline

| Zeitpunkt | Event |
|---|---|
| 2026-08-06 15:42 | `contractmanager`, Branch `chore/vertragswerk-rename`, Head `ceb98f10` — 4 Läufe `action_required` |
| 2026-08-08 (Session 03) | Erste Analyse. Schluss damals: **betrifft nur ProjektWerk**. Ursache vermutet: Bot gilt als „first-time contributor" |
| 2026-08-09 08:16 | `projektwerk`, Branch `fix/6-kleinkram`, Head `02470b4a` — 5 Läufe `action_required` |
| 2026-08-09 | Diese RCA. Der Befund von Session 03 ist **widerlegt** (siehe 3.1) |

---

## 3. Beweislage

### 3.1 Korrektur des Befunds aus Session 03

Session 03 hielt fest, das Problem betreffe **nur ProjektWerk**. Das war ein Messfehler:
`gh run list --limit 60` reicht bei `contractmanager` nicht weit genug zurück. Über
`actions/runs?per_page=100` erscheinen dort **vier** `action_required`-Läufe vom 2026-08-06.

Damit fällt auch die dortige Erklärung („der Bot hat in diesem Repo nie einen Commit gemergt"):
`contractmanager` ist das **älteste** Repo der Flotte mit 33 `fix(review)`-Commits auf `main`.

### 3.2 Häufigkeit

| | |
|---|---|
| `fix(review)`-Commits in erhaltener Historie (5 Repos) | 323 |
| Davon mit `action_required` als Folge | 2 |

**Der Normalfall ist, dass ein Bot-Push gar keine Läufe auslöst.** In keinem Repo existiert ein
Lauf mit `triggering_actor = github-actions[bot]` und `conclusion: success`. Das passt zu GitHubs
Schleifenschutz: Ereignisse, die mit dem `GITHUB_TOKEN` ausgelöst werden, starten keine neuen
Läufe. `actions/checkout` hinterlegt genau dieses Token (`persist-credentials`, Vorgabe `true`).

**Die eigentliche Frage ist deshalb nicht „warum bleiben die Läufe stehen", sondern
„warum sind sie überhaupt entstanden".** In 321 von 323 Fällen entstanden sie nicht.

### 3.3 Ausgeschlossen — mit Beleg

| Hypothese | Prüfung | Ergebnis |
|---|---|---|
| Workflow-Datei unterscheidet sich | `md5` aller fünf Kopien | identisch (`3043bc90`) |
| Actions-Berechtigungen unterscheiden sich | `actions/permissions` | identisch (`enabled`, `all`) |
| Freigaberegel unterscheidet sich | `actions/permissions/fork-pr-contributor-approval` | identisch (`first_time_contributors`) |
| Workflow-Schreibrechte unterscheiden sich | `actions/permissions/workflow` | identisch (`read`, `can_approve=false`) |
| Repo-Einstellungen unterscheiden sich | `allow_*_merge`, `private`, `fork` | identisch |
| Bot ist in manchen Repos unbekannt | `fix(review)`-Commits auf `main` | überall vorhanden (3–74) |
| Mitarbeiterliste unterscheidet sich | `collaborators` | identisch (3 Personen) |
| Secrets/Variablen unterscheiden sich | `actions/secrets`, `actions/variables` | identisch (`CLAUDE_CODE_OAUTH_TOKEN`, keine Variablen) |
| Zeitartefakt (andere Repos länger still) | letzter `fix(review)` je Repo | nein, `rechnungswerk` am 2026-08-08 ohne Vorfall |
| Commit-Signatur unterscheidet sich | `author`/`committer` der Bot-Commits | überall `author=github-actions[bot]`, `committer=claude[bot]` |
| Standard-Branch unterscheidet sich | `default_branch` | teilt die Flotte anders (`develop` bei worktime + contractmanager) als das Symptom |

### 3.4 Die App-Hypothese — **widerlegt**

Geprüft am 2026-08-09 in der Oberfläche: **Alle fünf Nextcloud-Apps stehen auf der
Repository-Liste der Claude-App** (projektwerk, vinarium, rechnungswerk, worktime,
contractmanager). Die Abdeckung ist nicht der Unterschied.

**Damit ist die Ausgangsfrage beantwortet: Es fehlt keine Einstellung.** Alle zwölf
verglichenen Konfigurationspunkte sind über die Flotte identisch.

### 3.5 Der Mechanismus — belegt

Aus dem Protokoll des Laufs, der gepusht hat (`31302989986`, Zeilen 491–494):

```
Requesting OIDC token...
Exchanging OIDC token for app token...
App token successfully obtained
Using GITHUB_TOKEN from OIDC
```

`anthropics/claude-code-action@v1` tauscht ein OIDC-Token gegen ein **App-Installationstoken**
und setzt dieses als `GITHUB_TOKEN`. Der Auto-Fix pusht damit — **nicht** mit dem
`GITHUB_TOKEN` des Workflows, das `actions/checkout` hinterlegt hat.

Das erklärt den ersten Teil: GitHubs Schleifenschutz gilt für das `GITHUB_TOKEN` des Laufs, nicht
für App-Installationstoken. Der Push löst deshalb sehr wohl `pull_request: synchronize` aus, und
neue Läufe entstehen. Meine frühere Annahme in 3.2, Bot-Pushes erzeugten „normalerweise gar keine
Läufe", war falsch — sie erzeugen welche, sie fallen nur nicht auf, weil sie durchlaufen.

Beleg dafür (vinarium PR#224, 2026-08-04):

```
20:50  Claude Code Review  head=f1acf83c  success  actor=AxDeontour   ← Bot-Commit
20:50  phpunit             head=f1acf83c  success  actor=AxDeontour
```

### 3.6 Was tatsächlich variiert — **ungeklärt**

Nicht der Token-Pfad und nicht das Ereignis, sondern **wem GitHub den Push zuschreibt**.
Zweimal dasselbe Repo, dieselbe Workflow-Datei, derselbe Auth-Pfad im Protokoll:

| Datum | Head | `event` | `triggering_actor` | Ergebnis |
|---|---|---|---|---|
| 2026-08-07 21:41 | `882e38fa` | `pull_request` | `AxDeontour` (208764541) | success |
| 2026-08-09 08:16 | `02470b4a` | `pull_request` | `github-actions[bot]` (41898282) | `action_required` |

Beide Läufe zeigen im Protokoll identisch `Exchanging OIDC token for app token` →
`Using GITHUB_TOKEN from OIDC`. Warum die Zuschreibung zwei Tage später kippt, ist offen.

**Wichtig für die Bewertung:** Solange die Zuschreibung `AxDeontour` lautet, funktioniert das
System wie gedacht — der Bot pusht, die Prüfungen laufen auf dem **neuen** Head, der PR ist
korrekt geprüft. Der Fehler ist nicht der Auto-Fix, sondern die kippende Zuschreibung.

### 3.7 Frühere Hypothese (Stand vor 3.4)

Die **Claude-App der Organisation** ist mit `repository_selection: selected` installiert — sie
deckt also nur ausgewählte Repos ab, nicht alle.

Wird beim Push ein **App-Installationstoken** statt des `GITHUB_TOKEN` verwendet, greift der
Schleifenschutz **nicht**: Der Push löst `pull_request: synchronize` aus, und die dadurch
entstehenden Läufe unterliegen der Freigaberegel. Das erklärt beide Beobachtungen auf einmal —
warum meist keine Läufe entstehen, und warum die wenigen entstandenen sofort stehen bleiben.

**Nicht bestätigt.** Die Liste der ausgewählten Repos ist über `/user/installations/{id}/repositories`
nur mit App- oder erweitertem Token lesbar; mein Zugang bekommt 403.

**So ist sie zu prüfen** (30 Sekunden, in der Oberfläche):
`github.com/organizations/cpcMomentum/settings/installations` → **Claude** → *Configure* →
**Repository access**.

- **Erwartung, wenn die Hypothese stimmt:** `projektwerk` und `contractmanager` stehen auf der
  Liste, `vinarium`, `worktime` und `rechnungswerk` nicht.
- **Widerlegt, wenn:** alle fünf oder keines der beiden auf der Liste stehen.

---

## 4. Root Cause

**Zur Hälfte bestimmt.** Die Kette steht bis auf ein Glied:

```
Bot pusht Auto-Fix mit App-Installationstoken  (3.5, belegt)
   │   Schleifenschutz greift nicht — der gilt nur fuer das GITHUB_TOKEN des Laufs
   ▼
pull_request: synchronize  →  Laeufe entstehen        (3.5, belegt — Normalfall!)
   │
   ▼
triggering_actor = ???                                 (3.6, UNGEKLAERT)
   │
   ├── AxDeontour           →  Laeufe laufen  →  PR korrekt geprueft   (321 Faelle)
   └── github-actions[bot]  →  Freigaberegel  →  action_required        (2 Faelle)
```

**Es fehlt keine Einstellung** (3.4). Zwölf Konfigurationspunkte über fünf Repos verglichen,
alle identisch. Das ist die Antwort auf die Ausgangsfrage.

**Offen bleibt allein**, warum GitHub denselben Push zweimal unterschiedlich zuschreibt.

---

## 5. Empfehlung

**Nichts an den Workflows ändern.** Und zwar aus einem stärkeren Grund als zuvor: Der
Auto-Fix ist nicht die Fehlerquelle. In 321 von 323 Fällen pusht der Bot, die Prüfungen laufen
auf dem neuen Head, und der PR ist korrekt geprüft — besser als ohne Auto-Fix. Ihn zu streichen
würde ein funktionierendes Verfahren wegen einer Zuschreibung entfernen, die zweimal gekippt ist.

**Der Vorfall ist selbstheilend:** ein erneuter Push unter eigenem Namen, und die Prüfungen
laufen. Kosten pro Vorfall: eine Minute.

**Was tatsächlich zu tun ist:**

- [ ] **Beobachten und zählen.** Tritt es häufiger auf, ist die Zuschreibung zu klären — dann mit
      einem Ticket bei `anthropics/claude-code-action`, denn die Token-Wahl liegt dort, nicht bei
      uns.
- [ ] **Beim Merge auf den Head achten.** Zeigt `gh pr checks` „no checks reported", ist das kein
      grünes Licht, sondern der hier beschriebene Zustand. Erneut pushen statt mit `--admin`
      durchmergen.

**Unabhängig von alldem, auf Qualitätsgründen:** Der Auto-Fix sollte Befunde **melden**, wo er sie
nicht zweifelsfrei beheben kann. Der Fix vom 2026-08-09 war inhaltlich berechtigt (ein
Doc-Kommentar nannte ein Feld, das die Schnittstelle nicht führt), hat den erklärenden Halbsatz
aber **gelöscht** statt ihn zu korrigieren. Das ist keine Freigabefrage und wäre auch dann eine
Verschlechterung, wenn nie ein Lauf hängen bliebe.

---

## 6. Prävention

- [ ] `gh run list --limit N` nicht mehr als Beleg für „kommt woanders nicht vor" verwenden —
      das Fenster reicht bei aktiven Repos nur wenige Stunden zurück. `actions/runs?per_page=100`
      mit Zeitstempeln nehmen. Der Fehlschluss aus Session 03 hing genau daran.
- [ ] Bei „betrifft nur Repo X"-Aussagen die **Häufigkeit** danebenstellen. Zwei Vorfälle auf 323
      Gelegenheiten sehen als „Repo X ist kaputt" ganz anders aus als als „passiert selten,
      überall".
- [ ] **Erst das Protokoll, dann die Einstellungen.** Vier Stunden Konfigurationsvergleich über
      fünf Repos haben nichts gefunden; die Zeile `Using GITHUB_TOKEN from OIDC` im Lauf-Protokoll
      hat den Mechanismus in einer Minute geklärt. Bei „warum verhält sich CI hier anders" gehört
      der Log-Vergleich an den Anfang, nicht ans Ende.
- [ ] **Keine Änderung empfehlen, solange die Ursache offen ist.** Ich hatte am 2026-08-09 zuerst
      vorgeschlagen, den Auto-Fix flottenweit zu streichen — bei einer Ursache, die ich im selben
      Absatz als ungeklärt bezeichnet hatte. Die RCA hat gezeigt, dass genau dieses Verfahren in
      321 von 323 Fällen der Grund ist, warum PRs korrekt geprüft sind.

---

*Erstellt am 2026-08-09. Fortsetzung, sobald 3.4 geprüft ist.*
