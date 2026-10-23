# MCP-Endpunkt einrichten (Claude-Connector)

Der Endpunkt ist **ab Werk aus** und antwortet dann mit 404. Er ist nur lesend (Phase 1 von #343).
Diese Anleitung gilt für eine Admin-Person mit `occ`-Zugang.

## Voraussetzungen

- Nextcloud 33–35, ProjektWerk installiert.
- App **OpenID Connect Provider** (`oidc`, H2CK) ≥ 2.5 aus dem App Store. Sie stellt die Tokens
  aus; ProjektWerk prüft sie nur.
- **Nicht** die Bearer-Prüfung von `user_oidc` einschalten (`oidc_provider_bearer_validation`):
  Dann übernähme der Core die Bearer-Anmeldung, ohne unsere Client- und Endpunkt-Prüfung.
- Ein verteilter Cache (`memcache.distributed`), sonst greift die Sperre nach fehlgeschlagenen
  Anmeldungen nicht über Anfragen hinweg.

## 1. Kanonische URL festlegen

Die URL, die Nutzer in Claude eintragen. Sie muss **Byte für Byte** in den Metadaten stehen,
sonst lehnt Claude ab. Einmal festlegen, nie mehr ändern:

```bash
URL="https://cloud.example.com/apps/projektwerk/mcp"   # mit /index.php, falls die Instanz keine Pretty-URLs hat
occ config:app:set projektwerk mcp_resource_url --value="$URL"
```

Prüfen: `curl -s -o /dev/null -w '%{http_code}' -X POST "$URL"` muss nach Schritt 4 `401` liefern.

## 2. oidc einstellen und Clients anlegen

```bash
occ app:install oidc
# Verlängerung 30 Tage statt ab Werk 15 Minuten
occ config:app:set oidc refresh_expire_time --value=2592000

# claude.ai, Desktop, Mobil, Cowork
occ oidc:create "Claude" https://claude.ai/api/mcp/auth_callback \
  -t confidential --token_type=jwt \
  --allowed_scopes="openid profile offline_access" --resource_url="$URL"

# Claude Code (Loopback, beliebiger Port)
occ oidc:create "Claude Code" "http://localhost:*/callback" "http://127.0.0.1:*/callback" \
  -t public --token_type=jwt \
  --allowed_scopes="openid profile offline_access" --resource_url="$URL"
```

Die Ausgabe nennt je `client_id` (und beim ersten `client_secret`). Aufbewahren.

## 3. Clients zulassen und einschalten

```bash
occ config:app:set projektwerk mcp_allowed_clients --value="<claude-client-id>,<claude-code-client-id>"
occ config:app:set projektwerk mcp_enabled --value=yes
```

Danach unter **Verwaltung → Übersicht** den Eintrag „ProjektWerk: MCP-Endpunkt für Claude“ prüfen.

## 4. Firewall / WAF

Anthropic ruft aus `160.79.104.0/21` an. Freigeben für:

- `POST /apps/projektwerk/mcp` und `GET /apps/projektwerk/mcp/oauth-protected-resource`
- `/.well-known/openid-configuration`, `/apps/oidc/authorize`, `/apps/oidc/token`, `/apps/oidc/jwks`

## 5. In Claude verbinden

- **claude.ai** (Organisations-Admin): Einstellungen → Connectors → Custom Connector hinzufügen,
  URL aus Schritt 1, unter „Erweitert“ Client-ID und Client-Secret des Clients „Claude“.
- **Claude Code**:
  ```bash
  claude mcp add --transport http projektwerk "$URL" --client-id <claude-code-client-id>
  ```
  dann in Claude Code `/mcp` → projektwerk → anmelden.

Beim ersten Verbinden öffnet sich die Nextcloud-Anmeldung (mit 2FA) und die Einwilligung von `oidc`.

Wer sich verbinden kann: jedes aktive Konto, dem ProjektWerk freigegeben ist (gleiche
Gruppenfreigabe wie im Browser). **Gäste** (Guests-App) bekommen 403. Gesehen wird genau das, was
die Person auch in der Weboberfläche sieht.

## Ausschalten

```bash
occ config:app:set projektwerk mcp_enabled --value=no
```

Der Endpunkt und die Metadaten antworten sofort (nach Ablauf des App-Config-Caches) mit 404.
Ausgestellte Tokens werden damit wertlos, bleiben aber in `oidc` bestehen.
