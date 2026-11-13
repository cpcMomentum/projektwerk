# MCP-Endpunkt einrichten (Claude-Connector)

Der Endpunkt ist **ab Werk aus** und antwortet dann mit 404. Eingeschaltet liest und schreibt er
(#343 lesend, #389 schreibend); was genau, steht unter „Was der KI-Zugang darf“.
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

## Was der KI-Zugang darf

Lesen: Boards, Spalten, Mitglieder, Vorgänge mit Schritten, Anhängen und Kommentaren.

Schreiben (dieselben Rechte und Regeln wie im Browser, über dieselben Dienste):

| Werkzeug | Was |
|---|---|
| `create_ticket`, `update_ticket` | Vorgang anlegen/ändern, mit Zuständigem und Fälligkeit |
| `move_ticket`, `close_ticket` | ans Ende einer Spalte schieben; als erledigt/verworfen abschließen |
| `add_step`, `update_step` | Arbeitsschritt anlegen, zuweisen, Frist, abhaken |
| `add_comment` | Kommentar, `@kennung` benachrichtigt |
| `add_attachment` | Datei mitschicken (≤ 1 MB je Anfrage) |
| `attach_file` | Datei aus der eigenen Nextcloud kopieren, **nur an interne und private Vorgänge** |
| `transfer_to_github` | als GitHub-Issue übertragen (nur interne Seite) |

Nicht über den KI-Zugang: löschen, wiederöffnen, Sichtbarkeit ändern, Kommentare bearbeiten,
Mitglieder und Board-Einstellungen.

- **Kundensichtbares ab Werk gesperrt:** Solange in ProjektWerk unter Verwaltung › KI-Zugriff der
  Schalter „KI darf in kundensichtbare Vorgänge schreiben“ aus ist (Standard), lehnt der Server jede
  Änderung an einem öffentlichen Vorgang und das Anlegen eines öffentlichen ab, auch mit
  `confirm_public`. Per `occ`: `occ config:app:set projektwerk mcp_public_write --value=yes`.
- **Kundensichtbares nur mit Bestätigung:** Ist der Schalter an, gilt: Jede Änderung an einem öffentlichen Vorgang (und das
  Anlegen eines öffentlichen) lehnt der Server ohne `confirm_public: true` ab; die Ablehnung fordert
  die KI auf, die Person zu fragen. Der Server kann nicht prüfen, ob sie wirklich gefragt hat.
- **Keine Datei aus der Nextcloud an Kundensichtbares:** `attach_file` lehnt öffentliche Vorgänge
  immer ab, auch mit `confirm_public`. Das Werkzeug liest aus dem ganzen Dateibaum der Person; eine
  Bestätigung, die die KI selbst setzt, reicht dafür nicht. Dateien für den Kunden hängt man im
  Browser an.
- **Kennzeichnung:** Kommentare, angelegte Vorgänge, Schritte, Anhänge und die Benachrichtigungen
  tragen „über KI-Werkzeug“ (`created_via` / `actor_via` = `tool`). Verschieben, Zuweisen und
  Abschließen stehen sonst nur im Audit-Log (Verlauf: #397).
- **Audit:** Jede Änderung erzeugt im Audit-Log von Nextcloud (App `admin_audit`) eine Zeile
  `ProjektWerk MCP: <werkzeug> by <user> on board <id> ticket <id> via client <client-id>`,
  ohne Inhalte.
- **Grenzen je Person und Stunde:** 60 neue Vorgänge, 120 Kommentare, davon 10 an Kundensichtbares,
  60 Anhänge, 120 sonstige Änderungen; lesen 1200.

## Ausschalten

```bash
occ config:app:set projektwerk mcp_enabled --value=no
```

Der Endpunkt und die Metadaten antworten sofort (nach Ablauf des App-Config-Caches) mit 404.
Ausgestellte Tokens werden damit wertlos, bleiben aber in `oidc` bestehen.
