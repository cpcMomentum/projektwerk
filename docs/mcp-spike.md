# MCP-Spike (Phase 0 von #343)

**Gemessen am 2026-10-06** auf der lokalen Dev-Instanz `nextcloud-dev`: NC 34.0.0.12, PHP 8.4,
H2CK `oidc` **2.5.2** (App Store; der Plan nennt 2.4.0, im Store ist 2.5.2 aktuell).
Prüfnutzer: `pw-e2e-intern` (intern) und `pw-e2e-kunde` (extern), beide ohne Adminrechte.

Grundlage ist der Plan in `docs/mcp-plan.md`, Abschnitt 4.1.

## Ergebnis in einem Satz

Kein Abbruchkriterium greift. Die Anmeldung über `oidc` trägt die geplante Architektur; zwei
Befunde ändern den Plan (Tokentyp prüfen, Verlängerungsdauer einstellen), drei weitere kommen aus
dem Abgleich mit der aktuellen MCP-Spezifikation.

## Prüfpunkte

| # | Prüfung | Ergebnis | Beleg |
|---|---|---|---|
| S1 | `TokenValidationRequestEvent` prüft ein JWT-Zugriffstoken in-process und liefert `userId` | **bestanden** | Event im Container gefeuert: echtes Token → `valid=true`, `user='pw-e2e-intern'`; manipuliertes, unsinniges Token → `valid=false` |
| S2 | JWT trägt `client_id`/`azp`; bindet `oidc` RFC 8707 `resource` an `aud`? | **bestanden** | Token-Kopf `typ: at+jwt`, RS256. Nutzlast: `aud` = `http://localhost:8080/apps/projektwerk/mcp`, `client_id` = `azp` = Client-ID, `scope` = `openid profile offline_access`. Ohne `resource`-Parameter setzt `oidc` `aud` auf die `resource_url` des Clients. |
| S3 | Discovery an der Web-Wurzel, S256, `offline_access`, RFC 9207 `iss` | **bestanden** | `/.well-known/openid-configuration` → 200; `code_challenge_methods_supported: [S256, plain]`, `offline_access` in `scopes_supported`, `authorization_response_iss_parameter_supported: true`. Kein `registration_endpoint` (DCR aus), kein CIMD. |
| S4 | Core lässt ein fremdes Bearer-Token bis zum `#[PublicPage]`-Controller durch, ohne Brute-Force-Zählung | **bestanden** | Quelltext: `Session::tryTokenLogin` gibt bei unbekanntem Token still `false` zurück, ohne Throttler. Live: drei Anfragen mit fremdem Token → je 401 mit `error="invalid_token"` aus **unserem** Controller in ~40 ms; `occ security:bruteforce:attempts` danach 0. |
| S5 | claude.ai verbindet sich mit vorregistriertem Client | **offen** | Braucht eine öffentlich erreichbare Instanz. Erst nach dem Design-Review auf nc.cpcmomentum.com, mit eigener Freigabe (Eingriff in den Produktivserver). |
| S6 | Loopback-Rückleitung für Claude Code (beliebiger Port) | **bestanden (skriptgesteuert)**, Live-Test mit Claude Code offen | `oidc` erlaubt `http://localhost:*/callback` und `http://127.0.0.1:*/callback` als Port-Platzhalter. PKCE-Durchlauf mit den Ports 53682, 41234 und 5000 → Code und Token. Claude Code bringt `--client-id` und `--callback-port` mit (`claude mcp add --help`). |
| S7 | Verlängerung funktioniert, Refresh-Token rotiert beim öffentlichen Client | **bestanden, mit Befund** | Refresh → 200, neues Refresh-Token, altes danach `invalid_grant` („already been used“), altes Zugriffstoken danach ungültig. **Befund:** `refresh_expire_time` steht ab Werk auf **900 s**. Siehe unten. Den 1-Stunden-Fall habe ich nicht abgewartet; die Gültigkeit hängt allein an diesem Wert. |
| S8 | `ILimiter` und `CriticalActionPerformedEvent` auf NC 33 | **bestanden** | Beide `@since` 28 bzw. 22 in `nextcloud/ocp`; `ILimiter::registerUserRequest(string, int, int, IUser)`. Audit wird erst in Phase 2 gebraucht. |

## Befunde, die den Plan ändern

### 1. Das Event erkennt auch ID-Tokens als gültig an

`TokenValidationRequestListener` prüft zuerst die Tabelle der Zugriffstokens und danach **ID-Tokens**
(`typ: JWT`, Audience = Client-ID). ID-Tokens gehen an jede Anwendung, die sich über diese
Nextcloud anmeldet. Hinge ProjektWerk nur an „gültig laut Event“, könnte eine fremde Anwendung mit
ihrem ID-Token hier lesen.

→ `BearerAuthenticator` verlangt zusätzlich: Kopf `typ` = `at+jwt`, `client_id` in
`mcp_allowed_clients`, `aud` enthält `mcp_resource_url`, `sub` = Nutzer des Events, `scope`
enthält `openid`. Der Plan sah `aud` **oder** Client-Liste vor; da `oidc` `aud` immer setzt,
gilt jetzt **beides**. Getestet in `BearerAuthenticatorTest::testTokensThatOidcAcceptsButAreNotForUsAreRejected`.

In der Kommandozeile lehnte das Event das ID-Token ab, weil der Issuer dort aus
`overwrite.cli.url` kommt (`http://localhost`) statt aus dem Request (`http://localhost:8080`).
Im Web-Request stimmt der Issuer; der Schutz darf sich darauf nicht verlassen.

### 2. Die Verlängerung läuft ab Werk nach 15 Minuten ab

`oidc` setzt `refresh_expire_time` = 900 s. Wer Claude eine Viertelstunde nicht nutzt, muss sich
neu anmelden. Das ist eine Instanz-Einstellung von `oidc`, kein Fehler im Plan.

→ Setup-Anleitung setzt 30 Tage; `McpSetupCheck` warnt unter einem Tag.

### 3. Abgleich mit der MCP-Spezifikation 2026-07-28 und der Claude-Doku

Geprüft am 2026-10-06 an
[Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http),
[Versioning](https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning),
[server/discover](https://modelcontextprotocol.io/specification/2026-07-28/server/discover),
[Tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools) und
[Claude: Authentication](https://claude.com/docs/connectors/building/authentication).

| Punkt | Plan | Spezifikation / Doku | Umgesetzt |
|---|---|---|---|
| `server/discover` | fehlt | Server **MUSS** es implementieren | ja |
| `Origin`-Header | fehlt | Server **MUSS** prüfen, ungültig → 403 | fremder `Origin` → 403, fehlender erlaubt |
| Ungültige Argumente | JSON-RPC -32602 | Werkzeugfehler (`isError: true`), damit das Modell korrigieren kann; -32602 nur für unbekanntes Werkzeug | ja |
| Unbekannte Methode (modern) | -32601 | HTTP **404** + -32601 | ja; in der älteren Epoche 200, weil dort 404 „Sitzung weg“ heißt |
| Nicht unterstützte Version | 400 | 400 + **-32022** mit `data.supported` / `data.requested` | ja |
| Moderne Ergebnisse | – | tragen `resultType: "complete"` | ja |
| Ältere Epoche | 2025-11-25 | Claude-Doku zitiert 2025-11-25; ältere Clients senden auch 2025-06-18 | beide angenommen |
| Callback hosted Claude | claude.ai **und** claude.com | nur `https://claude.ai/api/mcp/auth_callback` | Setup nennt claude.ai; claude.com schadet nicht |
| Claude Code | vorregistrierter öffentlicher Client | nutzt ohne Angabe sein eigenes CIMD, sonst DCR; mit `--client-id` den vorregistrierten | Setup nutzt `--client-id` |

### 4. Gruppenfreigabe der App gilt auch für MCP

Für eine `#[PublicPage]`-Route prüft Nextcloud **nicht**, ob die App für die Person freigegeben
ist (`SecurityMiddleware`: `!$isPublicPage && !isEnabledForUser(...)`). Ist ProjektWerk per Gruppe
beschränkt, wäre jemand außerhalb der Gruppe im Browser ausgesperrt, über MCP aber nicht.

→ `BearerAuthenticator` verlangt nach dem Token: Konto existiert, ist aktiv, und
`IAppManager::isEnabledForUser('projektwerk', $user)`. Sonst 401 `invalid_token` (kein 403, das
verriete das Konto). Außerdem prüft er `exp` selbst und führt die Formprüfung (Tokentyp, Client,
Audience, Ablauf) **vor** dem oidc-Aufruf aus; der Fehlerzähler zählt nur noch Tokens, die diese
Prüfung bestehen, und wächst damit nicht mit beliebigem Müll.

## Offene Entscheidungen für das Review

| # | Frage | Stand | Vorschlag |
|---|---|---|---|
| R1 | `#[AnonRateLimit(600/60)]` am Endpunkt gilt je IP. Alle claude.ai-Nutzer teilen sich Anthropics Adressbereich: ca. 30 aktive Nutzer à 20 Lesungen/Minute füllen die Grenze, dann bekommen alle 429. | 600/60 wie im Plan | Für eine Instanz mit wenigen Dutzend Nutzern belassen; bei mehr anheben. Das Budget je Person (1200/h) bleibt die eigentliche Grenze. |
| R2 | `get_ticket` begrenzt nur die Kommentare auf 64 KiB, nicht Beschreibung/Schritte/Anhänge. | wie REST | Belassen; dieselben Daten wie im Browser, nur größer. |
| R3 | `list_tickets` blättert über einen Offset und rechnet je Seite das ganze Board. Verschobene Vorgänge zwischen zwei Seiten können doppelt oder gar nicht erscheinen. | akzeptiert | Belassen bis zu großen Boards. |
| R5 | Die Formprüfung vor dem oidc-Aufruf liest die unsignierte Nutzlast. Wer Client-ID und Endpunkt-URL kennt, kann sie bestehen; jedes neue Fantasie-Token kostet dann einen oidc-Datenbankabruf und landet im Fehlerzähler. Brute Force ist bei der Token-Entropie kein Thema, nur Last. | durch das IP-Limit (R1) gedeckelt | Belassen. |
| R4 | `list_boards` liefert `yourRole: null` für das zweite und dritte Board eines Projekts. | **schon so in REST** (`board#index` → `viewerRole`), nicht durch MCP entstanden | Eigenes Issue; MCP übernimmt die Korrektur automatisch, weil es denselben Lesepfad nutzt. |

## Nicht geprüft

- **S5** (claude.ai live) und der **Live-Test mit Claude Code**: siehe oben.
- MCP Inspector / Conformance-Suite: gehört zum Akzeptanztest auf Staging.
- Mehrere Nextcloud-Knoten (APCu je Knoten): nur über den Setup-Check abgedeckt.

## Testaufbau zum Nachmachen

1. `occ app:install oidc` (2.5.2).
2. Clients anlegen, siehe `docs/mcp-setup.md`.
3. PKCE-Durchlauf per Skript: Basic-Auth-Sitzung statt Formular-Login
   (das Formular lehnt Skript-Logins auf NC 34 ab), `GET /apps/oidc/authorize` mit
   `code_challenge_method=S256` und `resource=<mcp_resource_url>`, Code am Loopback-Redirect
   abgreifen, `POST /apps/oidc/token`.
4. Event direkt prüfen: PHP-Skript im Container, `lib/base.php` laden,
   `IEventDispatcher::dispatchTyped(new TokenValidationRequestEvent($token))`.
