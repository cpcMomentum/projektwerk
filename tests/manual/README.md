# Handprüfungen gegen eine laufende Nextcloud

Zwei Skripte, die das prüfen, was die containerfreie Suite grundsätzlich nicht
kann: ob die Abfragen gegen ein **echtes** Schema laufen und ob die
Sichtbarkeitsregel mit **echten Zeilen** hält.

Sie laufen von Hand, nicht in der CI. Das ist Absicht — sie brauchen eine
installierte Nextcloud, und was daran automatisiert gehört, ist die Leak-Matrix
(#5), nicht diese Vorstufe.

| Skript | Prüft |
|---|---|
| `queries-run.php` | Bauen und laufen alle Lese-Abfragen? Aliasse, Verbunde, Parameterbindung, Autowiring. Erwartet **keine** Daten |
| `visibility-rule.php` | Hält die Regel mit echten Zeilen? Legt ein Board, drei Mitglieder und fünf Tickets an, prüft, räumt vollständig auf |

## Ausführen

```bash
docker cp tests/manual/. <container>:/var/www/html/custom_apps/projektwerk/tests-manual/
docker exec <container> chown -R www-data:www-data /var/www/html/custom_apps/projektwerk/tests-manual
docker exec -u www-data <container> php -f /var/www/html/custom_apps/projektwerk/tests-manual/queries-run.php
docker exec -u www-data <container> php -f /var/www/html/custom_apps/projektwerk/tests-manual/visibility-rule.php
```

Beide melden am Ende `ERGEBNIS: …` und nennen jede Prüfung einzeln mit `OK` oder
`FAIL`.

## Was sie nicht sind

**Kein Ersatz für die Leak-Matrix.** Die verlangt 4 Nutzer × 9 Tickets × *alle*
registrierten Lese-Endpunkte einschließlich Zählern und Sortierpositionen, dazu
den Vollständigkeitstest gegen `appinfo/routes.php` — und dafür eine
DB-gestützte Testumgebung, die es noch nicht gibt. `visibility-rule.php` deckt
davon den Kern ab (die Symmetrie von `internal`, die Abgrenzung von `private`,
und dass ein Nichtmitglied auch mit selbst gebautem `ViewerContext` aus dem
INNER JOIN fällt), aber eben nur den Kern.

## Stand 08.08.2026

Beide Skripte laufen auf allen drei Datenbanken durch — je eine
NC-34-Installation gegen PostgreSQL 16, SQLite und MySQL 8.4. Auf allen dreien
legt Migration 1 zehn Tabellen und sechzehn Indizes an.
