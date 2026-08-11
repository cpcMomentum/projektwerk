#!/usr/bin/env bash
#
# Spike S4 — Treiber. **Wegwerfcode.**
#
# Setzt die Mailkonfiguration je Messung um und ruft `S4-mail-und-link.php` in
# einem EIGENEN Prozess auf: Nextclouds Mailer baut seinen Transport genau
# einmal je Prozess, im selben Prozess misst man sonst immer den ersten Versuch.
#
# Der `trap` stellt die Ausgangslage wieder her — auch wenn eine Messung
# abbricht. Eine Instanz mit kaputter Mailkonfiguration zurueckzulassen waere
# die Art Schaden, die erst Tage spaeter auffaellt.

set -uo pipefail

C="docker exec -u www-data -w /var/www/html/custom_apps/projektwerk nextcloud-dev php"
OCC="docker exec -u www-data nextcloud-dev php occ"

HOST_VORHER=$($OCC config:system:get mail_smtphost 2>/dev/null | tr -d '\r')
PORT_VORHER=$($OCC config:system:get mail_smtpport 2>/dev/null | tr -d '\r')

aufraeumen() {
	echo
	echo "== Ausgangslage wiederherstellen: ${HOST_VORHER}:${PORT_VORHER}"
	$OCC config:system:set mail_smtphost --value="$HOST_VORHER" >/dev/null
	$OCC config:system:set mail_smtpport --value="$PORT_VORHER" >/dev/null
	$OCC config:system:delete mail_smtptimeout >/dev/null 2>&1
	$OCC config:system:get mail_smtphost
}
trap aufraeumen EXIT

# $1 Beschriftung · $2 Host · $3 Port · $4 optionale Zeitgrenze
messen() {
	$OCC config:system:set mail_smtphost --value="$2" >/dev/null
	$OCC config:system:set mail_smtpport --value="$3" >/dev/null
	if [ -n "${4:-}" ]; then
		$OCC config:system:set mail_smtptimeout --value="$4" --type=integer >/dev/null
	else
		$OCC config:system:delete mail_smtptimeout >/dev/null 2>&1
	fi
	$C spike/S4-mail-und-link.php senden "$1"
}

echo
echo "=== S4 — Mail bei totem SMTP-Port ==="
echo

messen "Erreichbarer Server (mailhog-dev:1025)" mailhog-dev 1025
messen "Port abgelehnt (127.0.0.1:2525)" 127.0.0.1 2525
messen "Verbindung verschluckt, NC-Vorgabe (10.255.255.1:25)" 10.255.255.1 25
messen "Verbindung verschluckt, Zeitgrenze 5 s" 10.255.255.1 25 5
messen "Verbindung verschluckt, Zeitgrenze 2 s" 10.255.255.1 25 2

echo
echo "=== S4 — Deep-Link aus dem CLI-Kontext ==="
echo
$C spike/S4-mail-und-link.php links
