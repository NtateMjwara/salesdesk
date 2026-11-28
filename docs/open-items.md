# SalesDesk — open items (28 Nov 2026)

Gathered from every decision doc. Tick items off here, and in the source doc, when they're done.

## Security (do first)

- [ ] **`includes/config1.php` is committed with what look like real database and SMTP credentials.** Rotate the DB
      password and the SMTP mailbox password on the host, delete the file, and purge it from git history
      (`git filter-repo` or BFG). Then confirm `includes/config.php` in the repo only ever holds placeholders, and that
      the live config sits on the server only (ideally outside the web root).
- [ ] Delete the unauthenticated debug scripts `app/dealer/test-fetch.php` and `app/dealer/verify-deployment.php`
      from the server and the repo.
- [ ] Remove stray debug and log files from the repo and server (`app/dealer/motus-debug.log`,
      `includes/importers/motus-debug.log`, `app/dealer/fetch-test-results.txt`), and make sure `.htaccess` blocks `*.log`.
- [ ] Non-admin sessions aren't re-checked on each request, so a suspended user stays signed in until their session
      expires.
- [ ] `reinstateDealer()` reactivates every paused car, including ones under a superadmin listing hold.

## Before going live with billing

- [ ] Real PayFast **sandbox round trip** on the server for broker Pro (steps in `FILEPATHS-billing-payfast.md`), then
      switch to live.
- [ ] Same for **Business Pro**.
- [ ] Check the `salesdesk_bank_*` platform config holds the real banking details (used on business and commission
      invoices).
- [ ] Check no live desk already uses a newly reserved slug (`pricing`, `plans`, `billing`, `salesdesk`, …):
      `SELECT slug FROM salesdesks WHERE slug IN (…)`.
- [ ] Plan taglines are free text. After changing a limit in Admin → Plans, update any tagline that quotes a number.

## Database hygiene

- [x] `leads.attribution_type`: 0027 creates it when it's missing, and also makes `broker_id` / `salesdesk_id` and
      `buyer_name` / `buyer_phone` nullable, so platform leads and the POPIA scrub work on a repo-built database.
- [ ] `commissions.broker_id` is `NOT NULL` in the repo schema, but platform leads have no broker. Check how a
      platform-lead close behaves on the live database.
- [ ] `commissions.dealer_notes` isn't in the repo schema (`suspendDealer()` now skips it). Decide: add it by migration
      or remove the references.
- [ ] Drop the unused `organizations.brands` and `organizations.agent_car_limit`.
- [ ] Build a reproducible local database: the migration chain is confirmed (see `architecture.md` → Migrations).
      Still needed: a dev seed that matches the current model (`db/seed.sql` fails partway).

## Cleanup

- [ ] Remove duplicate files such as `app/exec/leads(001).php`. Search for other `(001)` / `1.php` copies.
- [ ] Rewrite the stale `README.md` setup section.
- [ ] Confirm `browse-additions.css` and `mobile-hero-nav-fix.css` are merged and deleted, and that nothing references them.
- [ ] Move inline styles out of portal pages as you touch them (the leads pages are the biggest offenders).

## Product backlog (not urgent)

- [ ] Broker over the **network** limit: let them pick memberships to keep (today they just can't apply or accept).
- [ ] Let brokers pick which network names their desk (today: the first one joined).
- [ ] Email invitations for people not yet on SalesDesk (token sign-up into a network).
- [ ] Group overview for Enterprise owners (combined stock and leads across dealerships).
- [ ] Slug history + 301s when a business is renamed. (Single-dealership slugs are fixed today; check whether renames still
      break hub URLs.)
- [ ] Replace "first-listed desk" linking on /cars-for-sale/ and in sitemap-cars.
- [ ] Buyer stickiness across desks (deferred by decision).
- [ ] Impersonation ("view as"): deferred.
- [ ] Launch offer for existing brokers: undecided.
