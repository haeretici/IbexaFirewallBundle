# Haeretici Firewall Bundle — TODO

Improvements ordered from **easiest** to **hardest**. Each item includes the main files involved.

Review date: 2026-06-19

---

## 1. Easy — documentation, cleanup, and small fixes

### 1.1 Align README with the actual codebase
- [x] Remove `bucket_size` and `bucket_count` from the sample YAML in `README.md` — they are not defined in `DependencyInjection/Configuration.php`.
- [x] Document that admin paths are under **Content → Firewall → Dashboard / Settings** (not a standalone sidebar).
- [x] Note that `javascript-obfuscator` is optional and not used by the bundle today.
- [x] Fix the install section: bundle has no `composer.json` yet; document manual install accurately.

**Files:** `README.md`

### 1.2 Remove dead code and naming leftovers
- [x] Delete or repurpose empty `Resources/views/themes/standard/ne0heretic/challenge.html.twig` (still uses old `ne0heretic` path; challenge HTML is inline in `KernelListener`).
- [x] Remove unused `$cacheDir` property and constructor argument from `Controller/AdminController.php`.
- [x] Remove `Request::createFromGlobals()` from `dashboardAction()` — it is unused.
- [x] Drop unused `content/view` policy from `Security/PolicyProvider.php` or implement the feature it was meant for.

**Files:** `challenge.html.twig`, `AdminController.php`, `PolicyProvider.php`

### 1.3 Symfony API housekeeping
- [ ] Replace deprecated `isMasterRequest()` with `isMainRequest()` in `EventListener/KernelListener.php` (Symfony 6.3+).
- [ ] Add declared typed properties instead of undocumented `$path`, `$config`, `$cache`, `$bucketKey` (PHP 8.2+ dynamic property warnings).

**Files:** `KernelListener.php`, `ConfigService.php`, `AdminController.php`

### 1.4 Default path exemptions for admin UI
- [ ] Add `/haeretici_firewall*` (and optionally `/admin*`) to default exempt paths in `DependencyInjection/Configuration.php` so Ibexa admins are not challenged on bundle pages when JS challenge is enabled.

**Files:** `Configuration.php`, `README.md`

### 1.5 Use request object for cookies
- [ ] Replace `$_COOKIE` reads with `$request->cookies->get()` in `KernelListener.php`.

**Files:** `KernelListener.php`

### 1.6 Menu and translation polish
- [ ] Add more locale files under `Resources/translations/` (e.g. `ibexa_menu.pt.yaml`) or document that only English ships today.
- [ ] Add a firewall icon to the menu group via `extras.icon` if a suitable Ibexa icon exists (`lock`, `settings-block`, etc.).

**Files:** `EventListener/MenuListener.php`, `Resources/translations/`

### 1.7 Stub config files
- [ ] Remove empty `Resources/config/settings.yaml` or implement what `HaereticiFirewallExtension` expects (`settings.yaml.TODO` reference in `DependencyInjection/HaereticiFirewallExtension.php`).

**Files:** `settings.yaml`, `HaereticiFirewallExtension.php`

---

## 2. Easy–medium — forms, validation, and admin UX

### 2.1 Symfony Form constraints
- [ ] Add `Assert\Range`, `Assert\NotBlank`, and similar constraints to `Form/FirewallSettingsType.php` so server-side validation matches HTML `min`/`max` attributes.
- [ ] Validate path textarea lines (non-empty patterns, reasonable length).

**Files:** `FirewallSettingsType.php`, `Controller/AdminController.php`

### 2.2 CSRF protection on settings form
- [x] Ensure the settings form uses Symfony CSRF (default for `AbstractType`) and that `settings.html.twig` renders `form_rest` or `_token` if needed.
- [x] Confirm Ibexa session cookies cover admin POST requests (standard Ibexa admin session; `_token` field rendered before `form_end`).

**Files:** `FirewallSettingsType.php`, `settings.html.twig`, `AdminController.php`

### 2.3 Flash messages and Ibexa notifications
- [x] Settings save uses `TranslatableNotificationHandlerInterface` — flashes render via Ibexa `ibexa-notifications-container` in the standard admin layout (no manual alert markup on the settings page).
- [x] Standardized success/error/validation copy in `haeretici_firewall` translation domain; POST-redirect-GET on successful save.

**Files:** `AdminController.php`, `settings.html.twig`

### 2.4 Move challenge HTML into Twig
- [ ] Populate `challenge.html.twig` (or a new `Resources/views/themes/admin/haeretici/challenge.html.twig`) and render from `KernelListener` via Twig environment instead of a heredoc in PHP.
- [ ] Allows branding, CSP nonces, and easier iteration.

**Files:** `KernelListener.php`, Twig templates, possibly `app.yml` (inject `twig`)

### 2.5 Wire `verified_ttl` into client cookies
- [x] `challenge_verified_ttl` drives the HttpOnly `challengeVerified` cookie issued server-side after a successful solve.
- [x] One-time solver cookies (`challengeToken`, `challengeId`) use `challenge.ttl` from config in `getObfuscatedSolverJs()`.

**Files:** `ChallengeService.php`, `Configuration.php`

### 2.6 Dashboard UX improvements
- [ ] Add pagination or “load more” for recent requests table.
- [ ] Link dashboard request rows to a detail view or filter by IP.
- [ ] Show human-readable duration for ban/TTL values on dashboard cards.

**Files:** `dashboard.html.twig`, `AdminController.php`

### 2.7 Settings form theme
- [ ] Register `@ibexadesign/ui/form_fields.html.twig` as form theme on settings page for native Ibexa input styling (`ibexa-input`, toggles, help icons).

**Files:** `settings.html.twig`

---

## 3. Medium — architecture and dependency injection

### 3.1 Inject services instead of manual `new`
- [ ] Register `BotValidator` and `ChallengeService` in `Resources/config/app.yml` and inject into `KernelListener` instead of instantiating per request inside `onKernelRequest()`.
- [ ] Fixes testability and allows shared logger/config refresh.

**Files:** `app.yml`, `KernelListener.php`, `BotValidator.php`, `ChallengeService.php`

### 3.2 ConfigService lifecycle
- [ ] Declare missing `$cache` property on `ConfigService`.
- [ ] After `updateConfig()`, long-lived workers still hold stale config in injected `ConfigService` instances used by listeners — reload from Redis on each request or use a lightweight config accessor.
- [ ] Consider cache tag invalidation across workers when settings are saved from admin.

**Files:** `ConfigService.php`, `KernelListener.php`, `AdminController.php`

### 3.3 Replace `error_log()` with PSR-3 logging
- [ ] Inject `LoggerInterface` into `KernelListener`, `BotValidator`, and commands.
- [ ] Use structured context (IP, path, UA hash) and configurable log level; avoid raw PII in production logs by default.

**Files:** `KernelListener.php`, `BotValidator.php`, `StoreDataCommand.php`, `app.yml`

### 3.4 Split `StoreDataCommand` responsibilities
- [ ] Inline TODO at `Command/StoreDataCommand.php:146`: extract retention cleanup (7-day request logs, 90-day metrics) into `ibexa:firewall:prune` or a shell script.
- [ ] Keep `ibexa:firewall:store` focused on Redis → DB flush and metrics collection.

**Files:** `StoreDataCommand.php`

### 3.5 Safer Redis key scanning
- [ ] `Lib/CacheInspector.php` uses `KEYS` (blocking) and reflection into `RedisTagAwareAdapter` private properties — fragile across Symfony Cache versions.
- [ ] Replace with `SCAN`, or maintain a Redis list/set of pending log keys when writing `request_time_*` entries.

**Files:** `CacheInspector.php`, `KernelListener.php`, `StoreDataCommand.php`

### 3.6 Ibexa design / theme registration
- [ ] Add `Resources/config/ibexa.yaml` so `HaereticiFirewallExtension::prepend()` registers `@ibexadesign/haeretici` template overrides (file is referenced but missing).
- [ ] Document Encore merge step for `Resources/encore/ibexa.config.js` in README.

**Files:** `Resources/config/ibexa.yaml`, `HaereticiFirewallExtension.php`, `README.md`

### 3.7 Package metadata
- [ ] Add `composer.json` (name `haeretici/firewall-bundle`, PSR-4 autoload, Symfony bundle class, Ibexa version constraint).
- [ ] Ship `config/packages/haeretici_firewall.yaml` dist and route import in recipe or README.

**Files:** new `composer.json`, `README.md`

---

## 4. Medium–hard — security hardening

### 4.1 Trust client IP correctly
- [ ] `KernelListener` constructor prefers raw `X-Forwarded-For` leftmost IP over `$request->getClientIp()`, bypassing Symfony `trusted_proxies` / Ibexa reverse-proxy setup.
- [ ] Use `$request->getClientIp()` only, with documented requirement to configure trusted proxies in the host app.

**Files:** `KernelListener.php`, `README.md`

### 4.2 Challenge lifecycle improvements
- [x] Per-browser verified state: after a successful one-time solve, issue an HttpOnly HMAC cookie (`challengeVerified`) bound to **IP + User-Agent** with TTL `challenge.verified_ttl`; skip re-challenge while valid. Other browsers on the same IP are not whitelisted. Redis key `verified_client_{sha256(ip|ua)}` is written for audit/revocation (hot path validates cookie only, no Redis read).
- [x] Delete challenge secret from Redis after successful verification (one-time use).
- [x] HMAC-signed verified cookie binds expiry to IP + User-Agent via `%kernel.secret%`; one-time challenge cookies cleared on promotion.

**Files:** `ChallengeService.php`, `KernelListener.php`, `Resources/config/app.yml`

### 4.3 Bot validation strictness
- [ ] Twitter/Meta validators accept CIDR match without reverse DNS (`Lib/BotValidator.php` fast path) — spoofed UA + IP in range could pass.
- [ ] Require forward/reverse DNS confirmation even when IP is in published CIDR blocks, or fetch official IP lists periodically.

**Files:** `BotValidator.php`

### 4.4 Challenge anti-bot gaps
- [ ] Inline TODO in `ChallengeService.php:82`: language/WebGL/headless checks silently `return` with no captcha fallback — users on privacy browsers or old Safari may be blocked with no recovery.
- [ ] Add fallback UI message or optional CAPTCHA provider hook.

**Files:** `ChallengeService.php`, challenge Twig template

### 4.5 Admin and API scope
- [ ] Audit which siteaccesses and routes the listener should run on (admin, GraphQL, REST, static assets).
- [ ] Add config flag to disable firewall for admin siteaccess or specific route prefixes.
- [ ] Ensure metrics AJAX endpoint respects same session/auth as dashboard.

**Files:** `KernelListener.php`, `Configuration.php`, `routing.yaml`

### 4.6 Rate limit edge cases
- [ ] `onKernelResponse` may call `$this->botValidator->checkRateLimit()` when `$this->botValidator` was never set (exempt short-circuit in constructor left stale state from prior request — see §5.1).
- [ ] Reset per-request flags at start of each request.

**Files:** `KernelListener.php`

---

## 5. Hard — critical correctness refactor

### 5.1 Fix per-request state in `KernelListener` (production blocker)
**Problem:** `KernelListener` is a shared Symfony service, but the **constructor** reads `Request::createFromGlobals()` and sets `$this->path`, `self::$clientIp`, `self::$exempt`, and other **static** fields once per worker. Subsequent requests in the same PHP-FPM process reuse wrong IP, path, and exemption status.

**Tasks:**
- [ ] Move path, IP, exemption, and timing initialization into `onKernelRequest()` / `onKernelResponse()`.
- [ ] Remove static mutable state (`$clientIp`, `$exempt`, `$isBotAgent`, etc.) — use instance properties reset each request.
- [ ] Add integration tests that simulate two consecutive requests with different IPs and paths on the same listener instance.

**Files:** `KernelListener.php`, new `tests/`

---

## 6. Hard — testing, quality, and operations

### 6.1 Automated test suite
- [ ] PHPUnit tests for `BotValidator` (DNS mocked, CIDR, ban TTL, rate limit windows).
- [ ] Unit tests for `ChallengeService` (`breakString`, verify, expiry).
- [ ] Integration tests for `ConfigService` merge and cache invalidation.
- [ ] Functional tests for admin routes (policy denied, settings save, metrics JSON).
- [ ] Command tests for `StoreDataCommand` flush and prune.

**Files:** new `tests/`, `phpunit.xml.dist`

### 6.2 Static analysis and CI
- [ ] Add PHPStan/Psalm at level 6+.
- [ ] Add PHP-CS-Fixer or ECS aligned with Symfony standards.
- [ ] GitHub Actions (or equivalent): lint, static analysis, tests.

**Files:** new config at bundle root

### 6.3 Database migrations
- [ ] Replace raw SQL in README with Doctrine migrations for `http_request_logs`, `server_metrics`, `firewall_config`.
- [ ] Version schema changes with the bundle.

**Files:** new `migrations/`, `README.md`

### 6.4 Non-blocking DNS lookups
- [ ] `gethostbyaddr()` in bot validation blocks the request thread.
- [ ] Consider async DNS, timeout limits, or pre-resolved official crawler IP lists with periodic refresh.

**Files:** `BotValidator.php`

### 6.5 Real proof-of-work or external CAPTCHA
- [ ] README describes “Proof-of-Work”; implementation is reversed Base64 + cookie — trivially scriptable.
- [ ] Either implement actual PoW (e.g. hashcash-style client work), integrate hCaptcha/Cloudflare Turnstile, or correct marketing/docs to “JS challenge”.

**Files:** `ChallengeService.php`, `README.md`

### 6.6 HTTP cache / CDN integration
- [ ] Document interaction with Varnish/CDN when challenge responses return 200 HTML.
- [ ] Add `Vary` headers, cache bypass rules, and exempt static assets at edge.

**Files:** `KernelListener.php`, `README.md`

### 6.7 Operational dashboard features
- [ ] IP ban list management (view/revoke) in admin UI.
- [ ] Export request logs (CSV/JSON).
- [ ] Configurable retention periods in settings instead of hardcoded 7/90 days in `StoreDataCommand`.

**Files:** `AdminController.php`, new admin pages, `StoreDataCommand.php`

---

## Priority summary

| Priority | Item | Effort |
|----------|------|--------|
| **P0** | §5.1 Per-request state / static leak in `KernelListener` | Hard |
| **P0** | §4.1 Client IP / X-Forwarded-For trust | Medium |
| **P1** | ~~§4.2 Challenge verified TTL + one-time use~~ | Done |
| **P1** | §4.3 Bot CIDR fast-path without DNS | Medium |
| **P1** | §3.1–3.2 DI and config refresh | Medium |
| **P2** | §2.1–2.2 Form validation + CSRF | Easy–medium |
| **P2** | §3.6–3.7 Ibexa yaml + composer.json | Medium |
| **P3** | §6.1–6.3 Tests, PHPStan, migrations | Hard |
| **P3** | §6.5–6.7 PoW/CAPTCHA, ops features | Hard |

---

## Inline TODOs already in source

| File | Line | Note |
|------|------|------|
| `Lib/ChallengeService.php` | 82 | Language/WebGL checks should fall back to captcha |
| `Command/StoreDataCommand.php` | 146 | Use a separated script for retention cleanup |
| `DependencyInjection/HaereticiFirewallExtension.php` | 50 | References `settings.yaml.TODO` — file not shipped |

---

## Strengths to preserve

- Sliding-window rate limiting with Redis `getItems()` batching (`BotValidator`).
- DNS-based validation for major crawlers.
- Honeypot trap paths with configurable ban duration.
- Ibexa RBAC policy + native admin menu integration (`MenuListener`).
- Admin dashboard with Chart.js metrics and structured settings UI.
- `hash_equals()` for challenge secret comparison.