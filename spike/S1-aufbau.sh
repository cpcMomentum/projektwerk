#!/usr/bin/env bash
#
# Aufbau fuer Spike S1 — Wegwerfcode, gehoert NICHT nach lib/.
#
# Legt beim Dienstleister nur den Austauschordner auf der Festplatte an und
# raeumt verwaiste Freigaben aus fruehren Laeufen auf. Projekt, Vorgang,
# Anhang und die eigentliche Ordnerfreigabe mit dem Gast entstehen erst im
# Playwright-Spike selbst (ueber die App-API bzw. Nextclouds Freigabe-API).
# Danach laesst sich in einer echten Gast-Sitzung pruefen, was §11.2 fragt.

set -euo pipefail

OCC="docker exec -u www-data -i nextcloud-dev php occ"
INTERN="pw-e2e-intern"
ORDNER="Spike-Austausch"

echo "== Ordner beim Dienstleister anlegen"
docker exec -u www-data nextcloud-dev sh -c \
  "mkdir -p '/var/www/html/data/${INTERN}/files/${ORDNER}'"
$OCC files:scan "$INTERN" >/dev/null

echo "== Verwaiste Freigaben aus fruehren Laeufen aufraeumen"
$OCC sharing:delete-orphan-shares >/dev/null 2>&1 || true

echo "Fertig. Weiter im Playwright-Spike."
