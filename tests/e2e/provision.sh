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

# WerkPlus-Grenzen (#288) fuer die E2E-Instanz aufheben: Jeder Spec baut sein
# eigenes Kundenprojekt (ein externes Mitglied) und teils mehrere Boards. Der
# freie Umfang (je 1) ist hier nicht das Pruefziel — die Enforcement-Logik haengt
# an EntitlementServiceTest. Hoch statt 0, weil 0 laut Vertrag auf 1 zurueckfaellt.
# Idempotent wie der Rest des Skripts.
$OCC config:app:set projektwerk plus_max_customer_projects --value=9999
$OCC config:app:set projektwerk plus_max_boards_per_project --value=9999
