# SalesDesk — architecture notes

A map for finding your way around, compiled from the code as of 28 Nov 2026. When a file and this note disagree,
the file is right. Fix the note.

## Request flow

1. Apache + `.htaccess` (web root). Rule order matters:
   security → external redirects (legacy `/c/` → `/cars-for-sale/`, strip `index.php`, strip `.php` on GET/HEAD
   except `/api/`, drop trailing slash on pages) → real files/dirs pass through → named routes (sitemaps, car detail)
   → extensionless `{page}` → `{page}.php` → reserved top-level words 404 → `/{desk-slug}/` catch-all
   (`broker/index.php`).
2. POST/PUT/DELETE are **never** redirected (the browser would drop the form body).
3. `MultiViews` must stay off.
4. Reserved desk slugs: `SD_RESERVED_DESK_SLUGS` / `sdIsReservedDeskSlug()` in `includes/functions.php`. Keep it in step
   with the reserved-words rule in `.htaccess`.

Route shapes:

```
/cars-for-sale/                         browse
/cars-for-sale/car/{car-slug}/          platform-attributed car detail
/cars-for-sale/{desk-slug}/{car-slug}/  desk-attributed car detail (?ref= kept)
/desks/  /desks/{org-slug}/  /desks/independent/   directory + business hubs
/{desk-slug}/                           broker storefront (catch-all, last)
/pricing                                dynamic pricing page
/sitemap.xml, /sitemap-*.xml            sitemap/*.php
```

## A typical portal page

```php
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/dealer_context.php';   // or exec_guard.php, superadmin.php …

applyCachePolicy('auth');
$ws = requireDealerWorkspace();                      // or requireLogin(); requireRole('broker');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    [$ok, $msg] = sdSomething(...);                  // logic lives in includes/
    $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    redirect('/app/dealer/page');
}

$pageTitle = '…';
ob_start();
// … markup …
// then the page hands its buffer to views/layout-app.php (role-based nav, workspace banner, group switcher)
```

`Database::getInstance()` returns a PDO singleton (exceptions on, assoc fetch, real prepares, collation pinned to
utf8mb4_unicode_ci).

## includes/ modules (main ones)

| Module | Responsibility |
|---|---|
| `config.php` | Constants. The template is in the repo; the real one is server-only. |
| `database.php` | PDO singleton |
| `security.php`, `session.php`, `csrf.php` | Headers and cache policy, sessions, CSRF |
| `functions.php` | Shared helpers: `redirect()`, `writeAuditLog()`, reserved slugs … |
| `authentication.php` (in `auth/`) | Registration wizard, login, rate limiting |
| `dealer_context.php` | `requireDealerWorkspace()`, workspace modes, group mode, switcher |
| `principal_controls.php` | Delegation grants, deal confirm/dispute, staff-changes feed |
| `superadmin.php` | `isSuperadmin()`, `requireSuperadmin()`, step-up codes |
| `admin_scope.php` | Admin scoping SQL, schema health (`adminSchemaProblems()`) |
| `user_admin.php` | Users page, bulk actions (`UA_BULK_ACTIONS`) |
| `entitlements.php` | Plans, limits, `entitlement()`, `canDo()`, `sdPlanChangedRecheck()` |
| `business.php` | Business orgs: ensure/sync from dealership, attach/detach (Enterprise), owner |
| `org_membership.php` | Networks: apply, invite, review (`sdNetworkReview` is the only place review rules live) |
| `billing.php` | PayFast checkout, ITN, cancel, lapse |
| `invoicing.php` | Enterprise invoices (EFT) |
| `plan_grace.php` | 7-day downgrade windows, parked cars, paused dealership listings |
| `vip_network.php` | Network-only stock, network bonus |
| `network_dashboard.php` | Business dashboard, announcements, badges |
| `pricing.php` | /pricing data |
| `desk-seo.php`, `desk-directory.php`, `structured-data.php` | Titles, canonicals, JSON-LD, directory |
| `mailer.php` | All outgoing email (PHPMailer) |
| `encryption.php` | AES-256-CBC for `bank_accounts.account_number` (key from env `BANK_ENCRYPTION_KEY`) |
| `migrations.php` | In-app migration runner (`SD_MIGRATIONS`, `SD_MIGRATION_POST`) |
| `importers/` | Stock importers (each `insertCar` calls `sdAssertDealerCanList()`) |

## Core tables (not exhaustive)

`users` (role: broker | dealer | sales_exec | admin; status; `is_superadmin`) · `profiles` · `addresses` ·
`salesdesks` (desks) · `dealers` (`user_id` = principal, UNIQUE, nullable; `organization_id`) · `sales_executives` ·
`cars` · `broker_inventory` / `broker_inventory_parked` · `car_views` · `leads` · `commissions` · `payouts` ·
`bank_accounts` · `organizations` · `organization_members` · `dealer_managers` / `organization_managers` ·
`dealer_delegations` · `exec_invitations` · `plans` / `plan_limits` / `subscriptions` / `account_limit_overrides` ·
`billing_checkouts` / `billing_events` · `business_invoices` · `plan_grace` · `network_announcements` ·
`notifications` · `audit_logs` · `schema_migrations` · `platform_config`.

### leads (relevant to the CRM work)

Known columns: `id, uuid, car_id, dealer_id, broker_id, salesdesk_id, organization_id, buyer_name, buyer_phone,
buyer_email, buyer_intent (within_30d | one_to_3mo | browsing…), buyer_message, status (new | contacted | test_drive |
negotiation | closed | lost), dealer_notes (one text field), consent_given, consent_at, attribution_locked,
attributed_at, status_updated_at, network_bonus_type, network_bonus_value, created_at, updated_at`.
`api/leads/submit.php` also writes `attribution_type`, **but no migration in the repo creates it**. Check the live
database.

Lead screens: `app/dealer/leads.php` (full pipeline, close → commission modal, confirm/dispute),
`app/exec/leads.php` (exec moves pre-close stages only), `app/broker/leads.php` (read-only pipeline for the broker's
desk). Every status change writes `lead.status_changed` to the audit log.

## Migrations

- `db/` holds numbered SQL files. `README.md` lists 0002–0005 for a fresh install. The in-app runner covers
  **0012–0026**. Confirm what sits between them before building a fresh local database.
- All are idempotent. The runner stops at the first failure, because later migrations depend on earlier ones.
- PHP backfills run straight after their SQL via `SD_MIGRATION_POST` (e.g. 0020 → `sdBackfillBusinessOrgs`).
- Some admin pages call `adminRequireSchema()` and show a "run database updates" screen when the schema is behind.

## Cron

`cron/billing-daily.php` (CLI only): PayFast lapse, expired checkouts, grace windows (`sdGraceDaily`), invoices
(`sdInvoiceDaily`), VIP repair (`sdVipDaily`). Access to paid features also stops on its own at
`current_period_end` via `sdSubscriptionGrants()`, even if cron doesn't run.

## Legacy and stale things to know about

- `README.md` (setup) is out of date: old `.htaccess` snippet, "broker (org owner)" seed account. Trust `CLAUDE.md`
  and these docs instead, and update the README when you do the local setup.
- `T1`–`T4 owns this file` comments come from an earlier team split. Ignore them: it's one codebase, one owner.
- Duplicate copies such as `app/exec/leads(001).php` and `includes/config1.php` are leftovers. See `open-items.md`.
- Old desk-org columns `organizations.brands` and `agent_car_limit` are unused and still to be dropped.
- `profiles.car_limit` is legacy (read only before 0019).
- News, outreach, newsletter, careers and contact code was removed in October 2026. Their tables were kept.
