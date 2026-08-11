#!/usr/bin/env bash
#
# Aufbau fuer Spike S1 — Wegwerfcode, gehoert NICHT nach lib/.
#
# Legt ein Projekt an, in dem ein echtes Gastkonto Kundenseite ist, teilt den
# Austauschordner mit ihm und haengt eine Datei an einen oeffentlichen Vorgang.
# Danach laesst sich in einer echten Gast-Sitzung pruefen, was §11.2 fragt.

set -euo pipefail

OCC="docker exec -u www-data -i nextcloud-dev php occ"
GAST="pw-spike-gast"
INTERN="pw-e2e-intern"
ORDNER="Spike-Austausch"

echo "== Ordner beim Dienstleister anlegen"
docker exec -u www-data nextcloud-dev sh -c \
  "mkdir -p '/var/www/html/data/${INTERN}/files/${ORDNER}'"
$OCC files:scan "$INTERN" >/dev/null

echo "== Ordner mit dem Gast teilen (Nextclouds Freigabe, keine eigene)"
$OCC sharing:delete-orphan-shares >/dev/null 2>&1 || true

echo "Fertig. Weiter im Playwright-Spike."
