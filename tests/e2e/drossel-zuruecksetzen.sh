#!/usr/bin/env bash
#
# Setzt die Drosselzaehler der lokalen Dev-Instanz zurueck.
#
# `TicketController::create` traegt bewusst `#[UserRateLimit(limit: 60,
# period: 3600)]`. Ein voller E2E-Lauf legt rund neun Vorgaenge an — nach etwa
# sieben Laeufen je Stunde steht der Aufbau mit einem **leeren** HTTP 429, das
# wie ein kaputter Server aussieht.
#
# In der CI passiert das nie: Dort ist die Instanz je Lauf frisch. Das hier ist
# reine lokale Bequemlichkeit und fasst ausschliesslich Zaehler an.
#
# Zwei Dinge, die beim Schreiben Zeit gekostet haben:
#
# 1. **Ein Neustart des Nextcloud-Containers hilft nicht.** Die Zaehler liegen
#    in Redis (`memcache.distributed`), und der laeuft in einem eigenen
#    Container, der dabei stehen bleibt.
# 2. **`xargs` verschluckt sich an den Schluesseln.** Sie enthalten den
#    Klassennamen `OC\Security\RateLimiting\Backend\MemoryCacheBackend` samt
#    Backslashes; `xargs` deutet die als Escapes und loescht dann nichts,
#    meldet aber Erfolg. Deshalb die Schleife mit `read -r`.
#
# Kein FLUSHALL: Auf demselben Redis liegen die Zwischenspeicher anderer
# Projekte.

set -euo pipefail

CONTAINER="${PWERK_REDIS_CONTAINER:-redis-dev}"

vorher=$(docker exec "$CONTAINER" sh -c "redis-cli --scan --pattern '*RateLimiting*' | wc -l")
docker exec "$CONTAINER" sh -c \
	'redis-cli --scan --pattern "*RateLimiting*" | while IFS= read -r k; do redis-cli DEL "$k" >/dev/null; done'
nachher=$(docker exec "$CONTAINER" sh -c "redis-cli --scan --pattern '*RateLimiting*' | wc -l")

echo "Drosselzaehler: $vorher -> $nachher"
