#!/usr/bin/env bash
#
# Legt die beiden Testkonten an, gegen die die E2E-Tests laufen.
#
# Derselbe Weg lokal wie in der CI — deshalb kommt der occ-Aufruf von aussen:
# Lokal steckt Nextcloud in einem Container, in der CI liegt es im Arbeits-
# verzeichnis. Alles andere waere zwei Skripte, die auseinanderlaufen.
#
#   lokal:  PWERK_OCC="docker exec -u www-data -i nextcloud-dev php occ" tests/e2e/provision.sh
#   CI:     PWERK_OCC="php occ" tests/e2e/provision.sh   (aus dem Serververzeichnis)
#
# Idempotent: Ein zweiter Lauf legt nichts doppelt an und faellt nicht um.

set -euo pipefail

OCC="${PWERK_OCC:-php occ}"

# Das Passwort steht hier im Klartext, und das ist Absicht: Es gehoert zu
# Wegwerf-Konten auf einer Wegwerf-Instanz. Ein Geheimnis daraus zu machen
# hiesse, ein Geheimnis zu verwalten, das keines ist — und der naechste, der
# die Tests lokal laufen laesst, sucht es vergeblich.
PASSWORT="${PWERK_E2E_PASSWORT:-e2e-Pw-2026-Test!}"

anlegen() {
	local uid="$1" name="$2"

	if $OCC user:info "$uid" >/dev/null 2>&1; then
		echo "Konto $uid existiert bereits"
		return 0
	fi

	# `--password-from-env` liest NC_PASS/OC_PASS. Der Umweg ueber die Umgebung
	# statt eines Arguments haelt das Passwort aus der Prozessliste heraus.
	OC_PASS="$PASSWORT" $OCC user:add \
		--password-from-env \
		--display-name "$name" \
		"$uid"
	echo "Konto $uid angelegt"
}

anlegen pw-e2e-intern 'E2E Dienstleisterseite'
anlegen pw-e2e-kunde 'E2E Kundenseite'

# Die Specs legen mehrere Boards je Projekt an; das braucht WerkPlus, die App selbst kennt keinen Schalter dafür.
if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
	token=$(php "$(dirname "$0")/werkplus-lizenz.php" "$(cd "$(dirname "$0")/../.." && pwd)" "$($OCC config:system:get instanceid)")
	$OCC config:app:set werkwolke store_version --value=1 >/dev/null
	$OCC config:app:set werkwolke token --value="$token" >/dev/null
	echo "WerkPlus-Testlizenz eingetragen"
else
	echo "Lokal keine Testlizenz: Die Dev-Instanz braucht eine echte WerkPlus-Lizenz für die Specs mit mehreren Boards"
fi
