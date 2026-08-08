# Changelog

Alle nennenswerten Änderungen an ProjektWerk werden hier dokumentiert.
Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

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

### Changed

- Plattformfenster auf Nextcloud 33–34 und PHP 8.4 angehoben. Nextcloud 32 erreicht im
  September 2026 sein Lebensende; eine Untergrenze, die vor dem ersten Release stirbt, ist keine

### Notes

- Ohne UI: Phase 1 liefert Fundament und Wächter, nichts Vorführbares
