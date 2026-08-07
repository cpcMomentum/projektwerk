# Changelog

Alle nennenswerten Änderungen an ProjektWerk werden hier dokumentiert.
Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- Projektgerüst: Vue 3 + Vite + TypeScript + @nextcloud/vue 9, PHP 8.2+ mit OCP-APIs
- CI: `node` (Typecheck, vitest, Build) und `phpunit` (PHP 8.2/8.4 gegen alle in
  `info.xml` deklarierten ocp-Versionen), dazu `canary`, `claude` und `claude-code-review`
