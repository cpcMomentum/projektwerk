# ProjektWerk Remote MCP Server - Technical Plan

> **Created:** 2026-09-28
> **Based on:** the code in this repo (app v0.4.16, `appinfo/info.xml`), `docs/nextcloud-fallstricke.md` and the existing REST API (`appinfo/routes.php`)
> **Tech baseline:** 2026-09-28. MCP spec, `mcp/sdk`, H2CK `oidc`, Claude connector behaviour and Nextcloud core were checked against primary sources (links in section 9).
> **Must be read before implementation:** `docs/nextcloud-fallstricke.md`

> **Stand nach Phase 0 (2026-10-06):** Der Plan steht hier unverändert wie in Issue #343. Was der
> Spike und der Abgleich mit den Primärquellen daran geändert haben, steht in
> `docs/mcp-spike.md` unter „Änderungen am Plan“. Bei Widerspruch gilt der Spike.

---

## 1. Übersicht

### 1.1 Vision

Claude (claude.ai, Desktop, Cowork, mobile and Claude Code) connects to ProjektWerk as a **remote MCP connector**. It authenticates with OAuth2 **as the individual Nextcloud user** and sees and does exactly what that user can see and do in the web UI, no more.

### 1.2 Problem today

Today Claude can reach ProjektWerk only through the REST API with an app password. That has several problems:

- It uses a Login Flow v2 app password, which gives full access to the account and has no scopes.
- It only works from a local shell, so it cannot run in claude.ai, Desktop or Cowork.
- Any protection for public tickets can only live in the client, where it can be bypassed. Closing is the sharpest case: it e-mails everyone involved, customers included (`TicketService::update` fires `EVENT_TICKET_CLOSED`).

### 1.3 MVP scope

- **Phase 1 is read-only.** It covers boards, columns, ticket lists and ticket details for internal and external members.
- **Phase 2** adds internal writes.
- **Phase 3** adds the one tool that sends e-mail to customers. It sits behind its own switch and needs its own approval.

### 1.4 Target architecture

The MCP endpoint is **part of the ProjektWerk Nextcloud app**:

- a hand-written, stateless JSON-RPC controller on `/apps/projektwerk/mcp` that always answers `application/json`;
- the token is checked **in-process** against the H2CK `oidc` app, which acts as the OAuth authorization server;
- execution goes through the one existing access door, `BoardAccess::contextFor()` → `ViewerContext`.

### 1.5 Success metric

One internal and one external test account each:

1. connect from claude.ai **and** Claude Code;
2. see exactly what the web UI shows them, proven by the leak matrix (REST id set = MCP id set);
3. create, move, step and comment on internal tickets;
4. are refused on a public comment until they confirm, after which the e-mail reaches the test customer inbox (Phase 3).

### 1.6 Assumptions

- One MCP endpoint per Nextcloud instance.
- Users are ProjektWerk members with full Nextcloud accounts, both internal and external. Guests are excluded (section 5).
- Tens of users and low request volume.
- PHP-FPM behind nginx or Apache.

---

## 2. Tech-Stack

| Layer | Technology | Version | Reason |
|-------|-----------|---------|--------|
| Runtime | PHP | >= 8.3 (`composer.json`, `info.xml`) | Existing app baseline |
| Platform | Nextcloud | 33–35 (`info.xml` min 33, max 35) | Existing app baseline |
| OCP stubs | `nextcloud/ocp` | ^33 \|\| ^34 (dev) | Existing |
| Tests | phpunit | ^12 | Existing |
| MCP protocol | MCP specification | **2026-07-28** (current), plus **2025-11-25** for compatibility | Claude's connector docs still cite 2025-11-25, so we serve both. |
| MCP transport | Streamable HTTP, POST only, JSON responses, no SSE, no sessions | as per 2026-07-28 | PHP-FPM and proxy buffering; nothing needs streaming |
| Authorization server | H2CK `oidc` ("OpenID Connect Provider" Nextcloud app) | **2.4.0** | Has PKCE, public clients, JWT access tokens (RFC 9068), `offline_access`, discovery and `TokenValidationRequestEvent`. Source: GitHub release 2.4.0. It is an optional runtime dependency, needed only when MCP is enabled. |
| MCP SDK | `mcp/sdk` (official PHP SDK) | 0.8.1 (Packagist) | **Not adopted.** Used only as a reference and for comparison in tests (see 2.1). The SDK docs confirm it supports 2025-11-25 and 2026-07-28 and requires PHP 8.1+. |
| JSON Schema check | Own `SchemaValidator` (a small subset) | n/a | No runtime dependency. CI checks the tool schemas against JSON Schema 2020-12 with a dev-only validator. |

**No new composer runtime dependencies.**

### 2.1 Abweichungen von den Projektstandards

**Authentication:**
- **Standard:** the project standards forbid custom auth logic.
- **Chosen:** ProjektWerk does **not** issue, store or sign tokens. H2CK `oidc` is the authorization server. ProjektWerk only does two things: it asks H2CK whether a token is valid (in-process event), and it checks that the token's client belongs to the MCP allowlist.
- **Reason:** MCP requires an OAuth resource server. Nextcloud's built-in `oauth2` app cannot fill the role: it has no PKCE (nextcloud/server PR #59930 targets NC 36), no discovery, no scopes and no audience.
- **Trade-offs:**
  - A third-party app sits on the critical path.
  - `info.xml` cannot declare a dependency on another app, so a SetupCheck guards it at runtime.

**MCP protocol handled by our own code instead of an SDK:**
- **Standard:** use maintained libraries.
- **Chosen:** a hand-written dispatcher for `initialize`, `notifications/initialized`, `ping`, `tools/list` and `tools/call` only.
- **Reason:** `mcp/sdk` 0.8.1 is pre-1.0 and experimental. It pulls in `opis/json-schema`, `symfony/uid`, `phpdocumentor/reflection-docblock`, `php-http/discovery` and PSR packages, which can clash with Nextcloud's vendor tree unless isolated with php-scoper.
- **Trade-offs:**
  - We follow spec changes ourselves.
  - We revisit this once `mcp/sdk` reaches 1.0.

---

## 3. Architektur

### 3.1 System diagram

```
 claude.ai / Desktop / Cowork          Claude Code (loopback redirect)
            │  (Anthropic egress 160.79.104.0/21)      │
            └──────────────┬───────────────────────────┘
                           │ HTTPS
┌──────────────────────────▼──────────────────────────────────────────────┐
│ Nextcloud 33–35 (PHP-FPM)                                               │
│                                                                         │
│  H2CK oidc 2.4.0 (authorization server)                                 │
│   /.well-known/openid-configuration · /authorize · /token (PKCE S256)   │
│         ▲  TokenValidationRequestEvent (in-process, no HTTP self-call)  │
│         │                                                               │
│  ProjektWerk app                                                        │
│   GET  /apps/projektwerk/mcp/oauth-protected-resource  (RFC 9728 PRM)   │
│   POST /apps/projektwerk/mcp ── McpController::handle                   │
│          │ BearerAuthenticator ── McpRateLimiter ── JsonRpcDispatcher   │
│          │                                             │                │
│          │                               ToolRegistry → Tools/*         │
│          │                                             │                │
│          │                    PublicGuard (writes only, stricter only)  │
│          ▼                                             ▼                │
│   BoardAccess::contextFor(userId, boardId) → ViewerContext  (ONE door)  │
│          ▼                                                              │
│   TicketReadModel / BoardReadModel  ◀── shared with REST controllers   │
│   TicketService / StepService / CommentService (existing)               │
│          ▼                                                              │
│   TicketScope / TicketMapper → DB     admin_audit ◀ audit event          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Components (all new files in this app)

| Component | File | Responsibility |
|-----------|------|----------------|
| MCP endpoint | `lib/Controller/McpController.php` | `handle()` for POST, `methodNotAllowed()` for GET and DELETE (405). Attributes: `#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 600, period: 60)]`. Bodies over 1 MB → 413. |
| PRM endpoint | `lib/Controller/McpMetadataController.php` | `protectedResource()` returns RFC 9728 JSON: `resource` (the pinned canonical URL), `authorization_servers` (one entry, the H2CK issuer), `bearer_methods_supported: ["header"]`, `scopes_supported: ["openid","profile","offline_access"]`. Returns 404 when MCP is off. |
| Bearer auth | `lib/Mcp/BearerAuthenticator.php` | The checks in 3.4. Returns `AuthenticatedCaller{userId, clientId}` or throws `McpAuthException` (carries HTTP status and challenge). |
| JSON-RPC core | `lib/Mcp/JsonRpcDispatcher.php` | See 3.3. |
| Tool contract | `lib/Mcp/Tool.php` (interface) | `name()`, `description()`, `inputSchema()`, `annotations()`, `isWrite()`, `call(AuthenticatedCaller $caller, array $args): ToolResult`. |
| Tool registry | `lib/Mcp/ToolRegistry.php` | Lists the enabled tools. Phase gating: `add_public_comment` is registered only when `mcp_public_writes=yes`. |
| Tools | `lib/Mcp/Tools/*.php` | One class per tool (section 3.5). Tools only call `BoardAccess`, the read models and the existing services. They **never** touch the session. |
| Input validation | `lib/Mcp/SchemaValidator.php` | Supports `type`, `required`, `enum`, `const`, `minLength`, `maxLength`, `minimum`, `additionalProperties:false`, `oneOf` (only for `ticket_id` vs `ticket_number`). Bad arguments → JSON-RPC -32602. |
| Result shaping | `lib/Mcp/ToolResult.php` | `structuredContent` plus a text rendering. The result is capped at 64 KiB; over the cap it adds `truncated: true` and a `next_cursor`. List rows always carry `visibility`. |
| Public gate | `lib/Mcp/PublicGuard.php` | The server-side gate for public tickets (section 5.2). It resolves the ticket only through `TicketMapper::findVisible($viewer, $id)`. |
| Read models (refactor) | `lib/Service/TicketReadModel.php`, `lib/Service/BoardReadModel.php` | The payload assembly moves out of `TicketController::index/show` and `BoardController::index/show` (mappers, `WaitStateCalculator`, `ChangeHighlighter`). REST and MCP call the same methods, so there is exactly one read path. |
| Ticket number lookup | `TicketMapper::findVisibleByNumber(ViewerContext, int $number)` | One scoped query through `scopedQuery()`, keyed on `project_id` + `number` (unique index `pwerk_tickets_pn_uidx`, migration 17). It avoids a client-side scan of the whole board. |
| Rate limiter | `lib/Mcp/McpRateLimiter.php` | Per-user budgets after authentication, plus a failure counter per token hash (section 5.4). |
| Audit | `lib/Mcp/McpAudit.php` | Dispatches `OCP\Log\Audit\CriticalActionPerformedEvent` for every write. |
| Setup checks | `lib/SetupCheck/McpAuthorizationServerCheck.php`, `lib/SetupCheck/McpDistributedCacheCheck.php` | These sit next to the existing `GuestsWhitelistCheck` and `InstanceConfigCheck`. The first warns when `mcp_enabled=yes` but `oidc` is missing or disabled, `mcp_allowed_clients` is empty, or `mcp_resource_url` is unset. The second warns when no distributed cache is configured, because per-node APCu makes the limits wrong on multi-node setups. |
| Config (IAppConfig) | `mcp_enabled` (`no`), `mcp_allowed_clients` (comma-separated oidc client ids), `mcp_resource_url` (pinned canonical URL), `mcp_public_writes` (`no`) | Phases 1–2 set them with `occ config:app:set projektwerk …`. Phase 3 adds an admin UI next to the reply-mailbox settings (`replyMailbox#config`). |

### 3.3 Routes and protocol handling

`appinfo/routes.php`:

```php
['name' => 'mcp#handle',                    'url' => '/mcp',                          'verb' => 'POST'],
['name' => 'mcp#methodNotAllowed',          'url' => '/mcp',                          'verb' => 'GET'],
['name' => 'mcp#methodNotAllowed',          'url' => '/mcp',                          'verb' => 'DELETE', 'postfix' => 'delete'],
['name' => 'mcpMetadata#protectedResource', 'url' => '/mcp/oauth-protected-resource', 'verb' => 'GET'],
```

- **No OCS route.** Claude clients send no `OCS-APIRequest` header, and Nextcloud answers such requests with 412 "CSRF check failed".
- **Read-path registry.** Both GET routes go into `ReadPathRegistry::ROUTES_WITHOUT_DATA`, each with a stated reason.

**Canonical URL.**
- When an admin sets `mcp_enabled=yes`, the canonical URL is computed once with `IURLGenerator::linkToRouteAbsolute('projektwerk.mcp.handle')` and pinned in `mcp_resource_url`.
- The PRM `resource` field emits this string byte for byte.
- It is also the URL the admin gives to users. Claude requires the PRM `resource` to equal the URL the user types in.

**Dispatcher (`JsonRpcDispatcher`).** Verified against the 2026-07-28 Streamable HTTP spec.

- **Protocol revision:**
  - `MCP-Protocol-Version: 2026-07-28` means **modern mode**:
    - No handshake.
    - The body must carry `params._meta["io.modelcontextprotocol/protocolVersion"]` equal to the header. A mismatch → HTTP 400.
    - `Mcp-Method` is required and must equal `method`. For `tools/call`, `Mcp-Name` is required and must equal `params.name`. A mismatch → HTTP 400 with JSON-RPC error `-32020` ("Header mismatch: …").
  - `MCP-Protocol-Version: 2025-11-25`, or an `initialize` request without the header, means **handshake mode**:
    - `initialize` negotiates `2025-11-25` and returns `capabilities: {tools: {listChanged: false}}` plus short `instructions` that explain the visibility rules.
    - `notifications/initialized` → HTTP 202 with an empty body.
  - Any other or missing version on a non-`initialize` request → HTTP 400 with an unsupported-protocol-version error that lists `2026-07-28` and `2025-11-25`.
- **Methods:** `ping`, `tools/list` and `tools/call`. Any other method → `-32601`.
- **Batching:** none. A JSON array body → `-32600`.
- **Sessions:** none. The server never emits `Mcp-Session-Id` and ignores it and `Last-Event-ID` when a client sends them.
- **Errors:**
  - Protocol errors use `-32700`, `-32600`, `-32601` and `-32602`.
  - Tool failures come back as `result.isError: true` with a text message.
  - "Not found" is **byte-identical** for hidden, deleted and nonexistent boards and tickets. This holds for a Nextcloud admin who is not a member too.

### 3.4 Auth flow

**Admin setup, once per instance (Phase 0 test instance, Phase 3 production):**

1. Install and enable H2CK `oidc` 2.4.0. Do **not** enable `user_oidc` bearer validation (`oidc_provider_bearer_validation`), because core would then take over bearer handling, without `resource_metadata` and without our client check.
2. Create client **"Claude"**:
   - confidential, PKCE, JWT access tokens;
   - redirect URIs `https://claude.ai/api/mcp/auth_callback` and `https://claude.com/api/mcp/auth_callback`.
3. Create client **"Claude Code"**:
   - public, PKCE, JWT access tokens;
   - loopback redirect on `http://localhost` and `http://127.0.0.1` (spike S6 checks whether H2CK matches any port).
4. Run `occ config:app:set projektwerk mcp_allowed_clients --value="<claude-id>,<claude-code-id>"`, then `mcp_enabled yes`.
5. Allowlist `160.79.104.0/21` in the WAF for both the MCP route and the oidc endpoints (`/.well-known/openid-configuration`, authorize, token).
6. In claude.ai, the org admin adds a custom connector with the canonical URL, plus the client ID and secret under the advanced settings. Pre-registered clients are the primary path. DCR stays off and becomes an opt-in only if spike S5 proves it is needed.

**Discovery and login:**

1. Claude POSTs `/mcp` without a token.
2. The server answers `401` with `WWW-Authenticate: Bearer resource_metadata="<PRM URL>", scope="openid profile offline_access"`.
3. Claude reads the PRM, then `/.well-known/openid-configuration` at the web root, which H2CK serves.
4. Authorization code flow with PKCE S256. The user logs in with Nextcloud (2FA included) and gives consent.
5. Claude receives an access token and a refresh token (`offline_access`). Every token belongs to one user.

**Per-request checks in `BearerAuthenticator`, in this order:**

1. If `mcp_enabled` is not `yes`, return **404**.
2. If `Authorization: Bearer` is missing, return **401** with the challenge above.
3. If the token-hash failure counter is over its limit, return **429** (section 5.4).
4. Dispatch `OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent($token)` **in-process**. Never make an HTTP self-call to introspection: it holds two FPM workers per request and can deadlock the pool.
   - If `getIsValid()` is false, return **401** with `error="invalid_token"` and increment the failure counter.
5. **Bind the token to this resource:**
   - Decode the JWT payload. The authorization server has just vouched for this exact string, so the signature does not need checking again.
   - If `aud` is present, it must contain `mcp_resource_url` byte for byte (RFC 8707, used if H2CK supports it per spike S2).
   - Otherwise `client_id` (or `azp`) must be in `mcp_allowed_clients`.
   - The scope must include `openid` in both cases.
   - On failure, return **401** `invalid_token`. This stops tokens minted for other OIDC relying parties on the same instance from being replayed here.
6. If `AccountType::isGuest($userId)` is true, return **403**. Guests are not supported (section 5.1). This avoids a group allowlist, so the `IGroupManager` ban in `lib/Mcp` holds.
7. The user must exist and be enabled (`IUserManager`). Otherwise return **401** `invalid_token`.

**Execution.**
- Tools receive `userId` and call `BoardAccess::contextFor($userId, $boardId)`.
- The code never calls `IUserSession::setUser`, has no admin branch and no system user.
- The MCP bearer token is never forwarded. `transfer_to_github` uses the user's stored GitHub token.

### 3.5 Tools (mirroring the existing REST API)

Every schema has `"additionalProperties": false`. Ids are integers ≥ 1.

| Tool | Phase | Input (required in **bold**) | Annotations | Maps to |
|------|-------|------------------------------|-------------|---------|
| `list_boards` | 1 | `include_archived: boolean` | readOnlyHint | `BoardReadModel::listFor($userId)` (`board#index` logic) |
| `list_columns` | 1 | **`board_id`** | readOnlyHint | `BoardReadModel::show($viewer)`, `.columns` (id, title, position, finalOutcome) |
| `list_tickets` | 1 | **`board_id`**, `column_id`, `cursor: string` | readOnlyHint | `TicketReadModel::index($viewer, $columnId)`. Compact rows: id, number, columnId, **visibility**, title, githubIssueUrl, waiting, counts. |
| `get_ticket` | 1 | **`board_id`**, exactly one of `ticket_id` / `ticket_number`, `comments_cursor: string` | readOnlyHint | `TicketReadModel::show($viewer, $id)`. The number is resolved with `findVisibleByNumber`. Returns the latest 20 comments plus `next_cursor`. |
| `create_ticket` | 2 | **`board_id`**, **`column_id`**, **`title`** (1–255), `description` (≤ 20000), **`visibility`** enum `internal`\|`private`\|`public`, `confirm_public: {const: true}` | destructiveHint:false, idempotentHint:false | `TicketService::create`. `visibility` has **no default**. `public` requires `confirm_public`. |
| `move_ticket` | 2 | **`board_id`**, **`ticket_id`**, **`target_column_id`**, `version`, `confirm_public` | idempotentHint:true | `TicketService::move`. Conflict handling in 3.6. |
| `add_step` | 2 | **`board_id`**, **`ticket_id`**, **`title`** (1–255), `confirm_public` | destructiveHint:false | `StepService::create` |
| `complete_step` | 2 | **`board_id`**, **`ticket_id`**, **`step_id`**, `confirm_public` | idempotentHint:true | `StepService::update(['done' => true])`. The server checks that the step belongs to the ticket. |
| `close_ticket` | 2 | **`board_id`**, **`ticket_id`**, **`outcome`** enum `done`\|`discarded`, `version`, `confirm_public` | destructiveHint:true | `TicketService::update` (closed and outcome). Conflict handling in 3.6. |
| `add_comment` | 2 | **`board_id`**, **`ticket_id`**, **`body`** (1–20000) | destructiveHint:false | `CommentService::create`. **Always refuses public tickets. No override exists.** |
| `transfer_to_github` | 2 | **`board_id`**, **`ticket_id`** | openWorldHint:true, destructiveHint:false | `TicketService::transferToGithub` with the user's stored GitHub token |
| `add_public_comment` | 3 | **`board_id`**, **`ticket_id`**, **`body`** (1–20000), **`expected_visibility`** `{const: "public"}`, **`confirm_public`** `{const: true}` | destructiveHint:true | `CommentService::create`. It accepts **only** public tickets, re-reads the ticket immediately before writing, and says in its description, its response and the audit event that it sends e-mail to N recipients, the customer included. It is registered only when `mcp_public_writes=yes`. |

**Deliberately out of scope for Phases 1–3:**
- deleting or restoring tickets;
- changing visibility;
- editing or deleting comments;
- attachments;
- member and board settings;
- notification preferences;
- anything under `my/*`;
- MCP resources and prompts.

### 3.6 Main flows

1. **Read:** `tools/call list_tickets` → auth → per-user read budget → `BoardAccess::contextFor` → `TicketReadModel::index` (the same code as REST) → shaped result (≤ 64 KiB).
2. **Internal write:** `tools/call move_ticket` → auth → write budget → `contextFor` → `findVisible` → `PublicGuard` (checks only whether the ticket is public) → `TicketService::move` → audit event.
3. **Optimistic locking:**
   - If `version` **is** given and conflicts (`ConflictException`, 409), the tool error carries `current_version` and a short state summary.
   - If `version` is **omitted**, the tool reads the current version and retries **once**. That is last-writer-wins, and the tool description says so.
4. **Public comment (Phase 3):**
   - `add_comment` on a public ticket → refused with "use add_public_comment".
   - The model asks the user.
   - `add_public_comment` with `confirm_public: true` and `expected_visibility: "public"` → re-read → write → mail.
   - Response and audit name the recipient count.

---

## 4. Phasen-Übersicht

Every phase ends with an explicit **go/no-go** from Leon and Axel before the next one starts.

| Phase | Title | Scope | Exit criterion | Effort | Depends on |
|-------|-------|-------|----------------|--------|------------|
| 0 | Spike | NC 34 test instance plus H2CK `oidc` 2.4.0, checks S1–S8 below, result in `docs/mcp-spike.md` | Every check has a written verdict; kill criteria evaluated | 2–3 days | none |
| 1 | Read-only MCP | Routes, PRM, `BearerAuthenticator`, dispatcher, `SchemaValidator`, `ToolResult`, the 4 read tools, `TicketReadModel`/`BoardReadModel` refactor, `findVisibleByNumber`, `McpRateLimiter`, the two SetupChecks, config keys, the CSRF/PublicPage exception documented in `docs/nextcloud-fallstricke.md`, unit tests, architecture tests, leak matrix | claude.ai and Claude Code list and read boards as the internal and the external test user; the leak matrix is green, with REST id set = MCP id set | 5–7 days | Phase 0 go |
| 2 | Internal writes | `create_ticket`, `move_ticket`, `add_step`, `complete_step`, `close_ticket`, `add_comment`, `transfer_to_github`, `PublicGuard`, `McpAudit` | The public-gate matrix is green; the audit entries show up in admin_audit; a live internal write works from both clients | 4–5 days | Phase 1 go |
| 3 | Public comment and operations | `add_public_comment` behind `mcp_public_writes` (off by default), admin UI (toggles, pinned URL with a copy button, allowed clients), admin setup doc (clients, WAF) | A live claude.ai test shows the refusal, then the confirmation path, and the e-mail reaches a test customer account. **Needs a separate approval from Leon.** | 2–3 days | Phase 2 go plus a separate approval |

**Total: about 13–18 working days.**

### 4.1 Phase 0: spike checks

| # | Check | Kill criterion |
|---|-------|----------------|
| S1 | `TokenValidationRequestEvent` validates an H2CK **JWT** access token in-process and returns `userId` | **Kill** → option C (proxy) for the auth layer only |
| S2 | The JWT carries `client_id`/`azp`; does H2CK bind the RFC 8707 `resource` to `aud`? | No client claim **and** no `aud` → **kill** → option C |
| S3 | `/.well-known/openid-configuration` is reachable at the web root and lists `code_challenge_methods_supported: ["S256"]`, `offline_access` and RFC 9207 `iss` support | Discovery unreachable and not fixable with a web-server rewrite → **kill** → option C. A missing `iss` is logged and followed up with H2CK, not a kill. |
| S4 | Core `tryTokenLogin` does **not** reject, and does not IP-throttle or count as a brute-force failure, a foreign bearer token before a `#[PublicPage]` controller runs | Core rejects or throttles → **kill** → option C |
| S5 | The claude.ai pre-registered client flow works end to end (connector with client ID and secret) | Fails → evaluate DCR as an opt-in with a cleanup job (option F) |
| S6 | The Claude Code loopback redirect works (any port, or a fixed port) | Fails → Claude Code keeps using the REST API with an app password until H2CK supports it |
| S7 | Refresh works after 1 h and the refresh token rotates for the public client | No rotation → a finding recorded in the spike result and reported upstream to H2CK |
| S8 | `ILimiter` and `CriticalActionPerformedEvent`/admin_audit behave as expected on NC 33 | `ILimiter` missing → use a counter in `ICacheFactory::createDistributed` |

If a kill criterion fires, the tool semantics, `PublicGuard`, the read models and the leak matrix in this plan all stay. Only the authentication layer moves to the proxy.

---

## 5. Security model

### 5.1 Identity and access

- Everything runs as the authenticated Nextcloud user. Access rules come unchanged from `BoardAccess`, `TicketScope` and the services.
- The MCP layer adds **no** access rules of its own apart from `PublicGuard`, which can only restrict further.
- **Guests** (`AccountType::isGuest`) get 403. The Guests app blocks apps that are not on its allow-list, which likely includes the oidc authorize endpoint. Allowing guests later needs `oidc` on the Guests allow-list plus a security review (see decision E5).

### 5.2 Public gate (`PublicGuard`)

**Threat model:** prompt injection through ticket text written by customers, for example text that arrives through the reply-mailbox intake. A model that reads a public ticket must not be able to reach the customer without the user noticing.

- `add_comment` refuses every ticket with `visibility === 'public'`, with or without `confirm_public`. It answers: "Ticket #N is customer-visible. Use add_public_comment after asking the user."
- `add_public_comment`:
  - refuses non-public tickets, so the model cannot use it as a general-purpose comment tool;
  - requires `confirm_public === true` **and** `expected_visibility === 'public'`;
  - re-reads the ticket right before writing;
  - has its own budget of **10 per hour** per user.
- Every other write that touches a public ticket (`move_ticket`, `add_step`, `complete_step`, `close_ticket`), and `create_ticket` with `visibility=public`, requires `confirm_public: true`. The refusal says: "Ticket #N is customer-visible; this can e-mail the customer. Ask the user, then call again with confirm_public: true."
- The design does not rely on MRTR or elicitation, because client support is not verified.
- **Upgrade path to a real human gate:** `add_public_comment` creates a **draft** that a human publishes in the ProjektWerk UI (decision E3).

### 5.3 CSRF and PublicPage

- `#[NoCSRFRequired]`: bearer requests carry no cookie session, so there is no CSRF exposure.
- `#[PublicPage]`: auth happens in the app, which is needed for the `resource_metadata` challenge and the client binding.
- Both exceptions are added to `docs/nextcloud-fallstricke.md` in Phase 1, with the reason. Until now the house rule allowed `#[NoCSRFRequired]` only on page and deep-link routes.
- The architecture test allows these two attributes only on `McpController` and `McpMetadataController`.

### 5.4 Rate limits

- **No IP-keyed limit except a generous ceiling.** `#[AnonRateLimit(600/60s)]` only guards against floods, because all claude.ai users share Anthropic's IP range.
- **Per-user budgets** are keyed on the validated `userId` and applied after authentication in `McpRateLimiter`. They use `OCP\Security\RateLimiting\ILimiter::registerUserRequest`, or a counter in `ICacheFactory::createDistributed` if spike S8 fails:

  | Class | Limit | Why |
  |-------|-------|-----|
  | read | 1200 per hour | |
  | `create_ticket` | 60 per hour | Same as REST `ticket#create` |
  | `add_comment` | 120 per hour | Same as REST `comment#create` |
  | other writes | 120 per hour | |
  | `add_public_comment` | 10 per hour | |

  These budgets replace the controller `#[UserRateLimit]` limits that MCP bypasses.
- **Invalid tokens:**
  - They register **no** IP-keyed brute-force attempt.
  - A failure counter keyed on `sha256(token)` returns 429 after 10 failures in 10 minutes.

### 5.5 Tokens, input, audit

- **Tokens:**
  - Never logged and never forwarded.
  - The audit log records only the client id and the user id.
- **Input:**
  - Strict schemas, capped text lengths, unknown properties rejected.
  - Bodies over 1 MB → 413.
- **Audit:**
  - Every write dispatches `CriticalActionPerformedEvent` with `"ProjektWerk MCP: %s by %s on board %d ticket %d via client %s"`.
  - `add_public_comment` also records the recipient count.
  - Comment bodies are never logged.
- **Switch:** with `mcp_enabled=no` (the default), both `/mcp` and PRM answer 404.

---

## 6. Test strategy

**phpunit ^12 unit tests**

- **`JsonRpcDispatcherTest`:**
  - parse error, invalid request, batch array, unknown method;
  - an unknown tool or bad arguments → -32602;
  - a header or `_meta` mismatch → HTTP 400 / -32020;
  - GET and DELETE → 405;
  - the handshake on 2025-11-25 and modern mode on 2026-07-28;
  - an unsupported version → 400;
  - no `Mcp-Session-Id` is ever emitted.
- **`BearerAuthenticatorTest`** (event mocked, fixed JWT fixtures):
  - disabled → 404;
  - no token → 401 with the exact `WWW-Authenticate` string;
  - an invalid token → 401 `invalid_token` and the counter increments;
  - the counter is exceeded → 429;
  - a foreign `client_id` or `aud` → 401;
  - a guest → 403;
  - a disabled user → 401.
- **`PublicGuardTest`:** the full matrix of visibility (public, internal, private) × all 8 write tools × `confirm_public` (absent or true). It must include:
  - `add_comment` refuses public tickets **even with** `confirm_public`;
  - `add_public_comment` refuses internal and private tickets.
- **`SchemaValidatorTest`**, plus a CI test that every `inputSchema()` is valid JSON Schema 2020-12, checked with a dev-only validator.
- **`ToolResultTest`:** the 64 KiB cap, `truncated`, `next_cursor`, and `visibility` present on every list row.

**Architecture tests** (extend `tests/Unit/Access/ArchitectureTest.php`)

- `lib/Mcp` never references `IUserSession`, `setUser`, `IGroupManager`, `isAdmin` or `forMember(`. `forMember(` stays exclusive to `BoardAccess`.
- Every `Tool` declares annotations.
- Every write tool routes through `PublicGuard`.
- `#[PublicPage]`/`#[NoCSRFRequired]` on API routes appear only on the two MCP controllers.
- Every `ToolRegistry` entry with `readOnlyHint` is registered in `ReadPathRegistry::MCP_TOOLS`, with expectations for each viewer role.

**Leak matrix**

- `list_boards`, `list_columns`, `list_tickets` and `get_ticket` go into `ReadPathRegistry::MCP_TOOLS`. Each gets the same per-viewer expectations as its REST twin: internal member, external member, non-member, private owner, and an admin who is not a member.
- The test asserts **REST id set = MCP id set** for every fixture viewer, which closes the gap where the matrix only covered GET routes.
- Not-found responses are byte-identical for hidden, deleted and nonexistent tickets.

**End to end (staging with H2CK)**

- Run MCP Inspector in CLI mode against `/mcp`, with a token from a manual PKCE flow.
- Run the MCP conformance suite server scenarios for 2025-11-25, and for 2026-07-28 where the suite covers it. The suite version used is recorded in `docs/mcp-spike.md`.

**Live acceptance** (claude.ai org connector and Claude Code, each with the internal and the external account)

- Reading works.
- An internal write works.
- A public comment is refused; after confirmation it succeeds and reaches the test customer inbox (Phase 3).
- After MCP is switched off, the endpoint answers 404.
- After 1 h, refresh works without the user logging in again.

---

## 7. Offene Entscheidungen

| # | Decision | Recommendation | Who | By |
|---|----------|----------------|-----|----|
| E1 | Should `move_ticket` on public tickets require `confirm_public`? It sends no mail, but the customer sees the change. | Yes, so there is one simple rule. | Product owner (Leon) | Phase 2 start |
| E2 | Does the canonical URL keep `/index.php` on the production instance (pretty URLs)? | Pin whatever `linkToRouteAbsolute` returns once pretty URLs are configured, and never change it afterwards. | Axel (ops) | Phase 1 |
| E3 | Is the Phase 3 public comment sent directly (`confirm_public`) or as a draft a human publishes in the UI? | Start with direct send behind `mcp_public_writes=no`, and build the draft flow if the injection risk proves real. | Leon | Phase 3 approval |
| E4 | Is DCR enabled if S5 shows that Desktop, Cowork or Claude Code cannot use a pre-registered client? | Only as an opt-in, with a cleanup job for unused clients. | Axel | After Phase 0 |
| E5 | Do guests get MCP access in a later phase? | No for Phases 1–3. Revisit only with a security review. | Leon | After Phase 3 |
| E6 | Fixed-port `--callback-port` or any port for Claude Code? | Follow what S6 finds. | Axel | After Phase 0 |

---

## 8. Risiken

Rated as impact × uncertainty. Every risk names the phase or spike check that retires it.

| # | Risk | Impact | Uncertainty | Mitigation | Retired by |
|---|------|--------|-------------|------------|------------|
| R1 | Confidentiality leak: MCP shows a customer a ticket it must not see | Very high (irreversible) | Low | One read path (read models), `BoardAccess` as the only door, leak matrix with REST = MCP, architecture tests | Phase 1 leak matrix |
| R2 | Prompt injection makes Claude e-mail a customer | High | Medium | `PublicGuard`, split comment tools, `confirm_public` + `expected_visibility`, a 10/h budget, `mcp_public_writes` off by default, the draft upgrade path | Phase 2 gate matrix, Phase 3 approval |
| R3 | H2CK event does not validate JWTs, or the token has no client/aud claim | High (blocks the design) | Medium | Kill criterion → proxy for the auth layer only | S1, S2 |
| R4 | Nextcloud core intercepts or IP-throttles the foreign bearer before the controller, slowing Anthropic's shared IP range for every user | High | Medium | `#[PublicPage]`, no `user_oidc` bearer validation, no IP-keyed brute-force registration | S4 |
| R5 | Token replay from another OIDC relying party on the same instance | High | Low | `aud` binding when present, otherwise the `mcp_allowed_clients` allowlist | S2, `BearerAuthenticatorTest` |
| R6 | H2CK 2.x changes behaviour or is uninstalled (third party on the critical path) | Medium | Medium | SetupCheck, the version is recorded in the setup doc, fallback to the proxy | Phase 1 SetupCheck |
| R7 | The MCP spec moves (two revisions served, DCR deprecated in favour of CIMD) | Medium | High | Stateless JSON-only core, conformance suite in CI/staging, revisit `mcp/sdk` at 1.0 | Ongoing, every release |
| R8 | Claude Code loopback does not match H2CK redirect rules | Low | Medium | Claude Code keeps using the REST API with an app password as a fallback | S6 |
| R9 | Rate limits wrong on multi-node setups (APCu per node) | Low | Low | Distributed-cache SetupCheck | Phase 1 |
| R10 | The last-writer-wins retry on move and close overwrites a concurrent human change | Low | Medium | One retry only; explicit `version` returns the conflict; stated in the tool description | Phase 2 |
| R11 | PRM `resource` differs from the URL the user typed, so Claude refuses | Medium | Medium | Pinned `mcp_resource_url`, copy button in admin, byte-for-byte comparison | Phase 1 live test |

### 8.1 Rejected alternatives

| Option | Why rejected |
|--------|--------------|
| B: `mcp/sdk` 0.8.1 | Pre-1.0, experimental, and at risk of vendor collisions (php-scoper would add 3–5 days). Revisit at 1.0. |
| C: external proxy (e.g. `cbcoutinho/nextcloud-mcp-server`) | Second trust boundary, extra infrastructure, the proxy holds credentials that cover the user, and the public gate would live outside the app. **Kept as the fallback for the auth layer only.** |
| D: our own authorization server inside ProjektWerk | Forbidden by the project standards (no custom auth) and a large security surface |
| E: Nextcloud core `oauth2` | No PKCE (PR #59930, NC 36), no discovery, no scopes, no audience |
| F: DCR from the start | One new client per fresh connection plus an open registration endpoint. It stays an opt-in (E4). |
| SSE or streaming responses | PHP-FPM and proxy buffering, and nothing needs it |
| Calling the REST controllers internally or `setUser` from the bearer | Brittle DI (`$userId` is fixed when the controller is constructed) and an implicit session. The read models are the clean way to share one path. |
| One `add_comment` with a public flag | The two tools must not be able to stand in for each other; a flag makes the customer-reaching path too easy to hit |

---

## 9. Sources

- MCP Streamable HTTP 2026-07-28: https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http
- MCP authorization 2025-11-25: https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
- MCP 2026-07-28 release post: https://blog.modelcontextprotocol.io/posts/2026-07-28/
- MCP PHP SDK: https://github.com/modelcontextprotocol/php-sdk, https://packagist.org/packages/mcp/sdk
- Claude connectors authentication: https://claude.com/docs/connectors/building/authentication
- H2CK oidc 2.4.0: https://github.com/H2CK/oidc/releases/tag/2.4.0, `TokenValidationRequestEvent`: https://github.com/H2CK/oidc/wiki/User-Documentation
- Nextcloud core `oauth2`: https://docs.nextcloud.com/server/stable/admin_manual/configuration_server/oauth2.html, PKCE PR: https://github.com/nextcloud/server/pull/59930
- Nextcloud controllers: https://docs.nextcloud.com/server/stable/developer_manual/basics/controllers.html
- Nextcloud logging and audit: https://docs.nextcloud.com/server/stable/developer_manual/basics/logging.html
- Proxy fallback reference: https://github.com/cbcoutinho/nextcloud-mcp-server

