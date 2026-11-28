# SalesDesk — Claude Code guide

SalesDesk (salesdesk.co.za) is a commission-based car sales distribution platform for South Africa.
Independent **brokers** run an online showroom (a "desk") and list dealership stock on it. Buyers enquire
through the desk, and the broker earns commission when the **dealership** closes the deal. Dealerships list
stock (uploads or importers), work leads, confirm sales and pay commission invoices. SalesDesk takes a platform
fee on the commission.

Owner: Sfiso (solo founder, user #13, the first superadmin). He makes the product decisions; you build.

**Read before working:** `docs/README.md` indexes everything. Before you touch an area, read its decision doc in
`docs/decisions/`. Those docs record decisions that are already made, so don't re-open them unless Sfiso asks.

---

## Stack

- PHP 8.2, PDO, MySQL/MariaDB (utf8mb4_unicode_ci). No framework. Plain PHP pages plus shared `includes/`.
- Apache 2.4 + mod_rewrite. All routing is in `.htaccess` (extensionless URLs, desk-slug catch-all last).
- Vanilla JS, no jQuery, no framework. CSS layered (see `docs/decisions/public-ux-system.md`).
- PHPMailer (SMTP) in `vendor/`. PayFast for billing. Cron scripts in `cron/` (CLI only).
- Hosting: shared hosting with a web application firewall (ModSecurity/Imunify360). Database migrations are run from
  **Admin → Database updates** (`/app/admin/migrations`), not pasted into a SQL tool (the firewall blocks it with 403).
- Deployment is manual: Sfiso uploads files to the server. GitHub (`NtateMjwara/salesdesk`, branch `main`) is the
  code record. There is no CI and no automated test suite.

## Layout

```
/                     public pages (index.php, pricing.php, brokers.php …)
.htaccess             all routing, see docs/architecture.md
app/admin/            admin + superadmin portal
app/broker/           broker portal (dashboard, inventory = Marketplace, leads, earnings, networks, plan)
app/dealer/           dealer portal (principal / admin workspace / group mode)
app/exec/             sales exec portal
app/billing/          PayFast checkout + printable invoices
api/                  JSON endpoints (exempt from the .php → clean URL redirect)
auth/                 login, registration wizard (auth/authentication.php has the handlers)
broker/index.php      public desk storefront /{desk-slug}/
cars-for-sale/        browse + car detail (platform and desk-attributed URLs)
desks/                desk / business-network directory and hubs
includes/             all shared logic (one module per domain, sd* functions)
views/                layout-app.php, layout-public.php, partials/
assets/css, assets/js shared and per-page CSS/JS
db/                   numbered SQL migrations (idempotent)
cron/                 billing-daily.php etc. (CLI only)
c/                    legacy /c/ redirect shims only
```

## Commands

- Lint a file: `php -l path/to/file.php`, and lint every PHP file you changed before finishing.
- Local database: `mysql -u salesdesk_user -p salesdesk_db < db/NNNN_name.sql`, in order, or use the in-app runner.
- `php -S` ignores `.htaccess`, so clean URLs and desk slugs won't route. Test through Apache (or a local
  Apache/MariaDB container) when routing matters.
- Cron: `php cron/billing-daily.php`.

## Conventions (follow these)

**Security**
- Prepared statements only. Never interpolate user input into SQL.
- Every POST calls `validateCSRF()`. Forms include `csrf_hidden_field()` or the token.
- Every page starts with the right guard: `requireLogin()` / `requireRole('broker'|'dealer'|'sales_exec')`,
  `requireDealerWorkspace()` for every `/app/dealer` page, `requireSuperadmin()` for platform-wide admin pages,
  `sdRequireStepUp()` for sensitive admin actions (admin accounts, payouts, the Database tool).
- `applyCachePolicy('auth')` on authenticated pages.
- Escape output with `htmlspecialchars()`.
- Secrets come from server-only config (`includes/config.php` on the server, `includes/payfast.config.php`) or env vars
  (`BANK_ENCRYPTION_KEY`). Never commit real credentials.

**Code style**
- Domain logic goes in an `includes/<module>.php` file, as `sd`-prefixed functions. Pages stay thin.
- Mutating functions return `[bool $ok, string $message]`. The page puts the message in
  `$_SESSION['flash_ok' | 'flash_error']` and redirects (POST/redirect/GET).
- **Schema-readiness guards:** code must work before its migration has run on the server. Each module has an
  `sdXReady()` check (e.g. `sdBillingReady()`, `sdVipReady()`) that falls back gracefully.
- **Audit everything that changes state:** `writeAuditLog(action, entity_type, entity_id, before, after, actorId)`.
  Actions are dotted (`plan.changed`, `org.broker_invited`). Admin actions inside a dealer workspace are stamped with
  `_workspace`.
- **Notify:** insert into `notifications` (user_id, type, title, body, meta JSON with a same-site `link`); email via
  `includes/mailer.php`.
- **Limits:** only through `includes/entitlements.php` (`entitlement()`, `canDo()`, `sdPlanLimit()`).
  NULL means unlimited, so never use `??` on a limit value.
- Constants are `SD_*`.

**Front end**
- **No inline CSS/JS:** no `<style>`, `<script>` blocks, `style=""` or `on*=""` in pages. Put CSS/JS in external files
  and reuse the shared ones (`global.css`, `public.css`, `public.js`). Many older portal pages (e.g. `app/*/leads.php`) still
  have inline styles. When you rework one of them, move its styles out.
- Extensionless internal links (`/app/dealer/leads`, not `.php`).
- Assets in the app layout use `sdAsset()` (content-hash URLs).
- Orange buttons use ink text (WCAG AA).

**Database**
- New migration = `db/00NN_name.sql`, idempotent (safe to re-run). Register it in `SD_MIGRATIONS` in
  `includes/migrations.php`, and in `SD_MIGRATION_POST` if it needs a PHP backfill step. Latest is **0027** (CRM, on
  branch `claude/crm`).
- Never drop tables holding real data without Sfiso's say-so. The October 2026 lean-up removed features but kept their tables.
- Never run anything against production. Use a local copy.

## Non-negotiable business rules

- **Lead attribution:** the desk in the URL, or a valid `?ref=`, gets the lead. Last click wins.
  `leads.organization_id` is snapshotted at submission. Attribution is locked once set and shown as immutable.
- **Four-eyes on money:** whoever closed a commission can't approve, schedule, process or pay it, superadmins included.
- **Commission is always paid to the broker's own bank account.** The business network takes no cut.
- **Brokers never own or create organisations.** The org is the business account (one per business).
- **Open marketplace:** joining a network adds access and perks. It never restricts what a broker can list.
- **Dealer workspace:** access comes from a person's relationship to a dealership (principal / operator / delegate /
  viewer), not their rank. See `docs/decisions/dealer-workspace.md`.
- Prices exclude VAT (no VAT line) until SalesDesk has to register for VAT.
- POPIA applies to every piece of buyer and user personal data.

## How Sfiso works

- He's the decision-maker, not a code reviewer. Explain changes in plain language: what changed for which user.
- When a choice is genuinely his (pricing, who can see what, money rules), ask before building. Don't guess.
- Work on a branch per feature (`claude/<feature>`), with one migration per feature where needed. Don't push to `main`
  unless he asks.
- Finish every feature with a short handover: files changed (full paths), migrations to run, and setup steps on the server.
  He deploys by uploading files, so list every file that changed.
- Test the way earlier phases were tested: local MariaDB, real page loads in a browser, and a sweep of the affected pages
  in each state (before the migration, plan off/on, each role).
- When a feature is done, update the matching doc in `docs/` (status, decisions, open items) in the same branch.

## Current state (28 Nov 2026)

Built and shipped through migration 0026: dealer workspace (0015–0018), plans and entitlements (0019), business orgs and
networks (0020–0021), PayFast billing for brokers and Business Pro (0022, 0026), downgrade grace (0023), all four VIP
perks (0024–0025), Enterprise invoicing (0026), and the dynamic /pricing page.

In build: the **CRM in the portal** (spec approved, `docs/crm/spec.md`, branch `claude/crm`). Its build progress list
is at the top of the spec.
Before going live, see `docs/open-items.md`. The PayFast sandbox round trips and a credentials rotation are still
outstanding.
