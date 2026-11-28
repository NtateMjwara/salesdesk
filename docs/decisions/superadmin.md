# Superadmin — decisions (Sep 2026)

## Model
- Superadmin is a flag on an admin account (`users.is_superadmin`), not a new role.
- User #13 (Sfiso) is the first superadmin, set by migration 0014.
- Until 0014 has run, every admin is treated as a superadmin, so nobody can be locked out.

## Superadmin only
- Admin accounts: invite, suspend, reinstate, remove, grant or revoke superadmin.
- Assignment: give dealerships and orgs to admins, hand over or share a whole portfolio, and assign unmanaged entities.
- Sees every dealership and org.
- Payout actions: approve, mark paid, mark failed, retry.
- Dashboard, Audit, Database, Migrations and Listing check.
- Adding or removing an entity's managing admins.
- **Plans (Nov 2026, 0019):** Admin → Plans (catalogue, prices, limits, MRR); a broker's plan and custom limits (Users → Edit → Plan & limits). See `plans-and-billing.md`.

## Admins (scoped to their dealerships and orgs)
- Their own dealerships, orgs and approvals.
- Users connected to them. Admin accounts never appear on the Users page.
- CIPC reviews for their own dealerships and orgs.
- Payouts: read-only.
- Content pages (blog, careers, newsletter, outreach) are still open to all admins. Not decided yet. (Most of these were removed in the Oct 2026 lean-up.)

## Users page (Oct 2026)
- Suspend / Reactivate tested and kept as the way to deactivate users.
- The list opens on **Current** (everyone except suspended). **Pending** and **Suspended** are separate views, so suspended accounts never show in the everyday list.
- Role chips, quick filters (email unverified, stuck in signup, joined this week), search and sort.
- Bulk actions (UA_BULK_ACTIONS in `includes/user_admin.php`):
  - Admins (scoped): Suspend, Reactivate.
  - Superadmins also: Activate, Activate + verify, Finish onboarding, Mark email verified / unverified, Resend verification code, Send password reset code (max 25 at a time), Clear lockout, Set custom car limit (Nov 2026), Export selected (step-up).
  - A confirmation shows how many users will change and how many will be skipped, with a dealer-cascade warning. Each user runs through the single-user function, and a `users.bulk_action` summary is audited.
- Fixed: `suspendDealer()` / `reinstateDealer()` wrote to `commissions.dealer_notes`, which isn't in the repo schema, so they crashed halfway through. They now skip that marker when the column is missing.

## Guardrails
- There must always be at least one active superadmin.
- No self-demotion, self-suspension or self-removal.
- An entity can't lose its only active manager.
- Admin sessions are re-checked against the database on every request.
- Step-up: an emailed code, valid for 15 minutes, for admin-account actions, payouts and the Database tool.
- Everything is audited. Removed admins are suspended, not deleted, so the audit log stays complete.

## Fixed along the way
- `/app/admin/database` had no access check.
- `payouts.php` crashed with "Cannot redeclare csrf_hidden_field".
- **Car limit consistency (Nov 2026):** the users list let scoped admins set a car limit while users-view treated it as superadmin-only. Car limits are now superadmin-only everywhere and stored as plan custom limits (0019).

## Open items
- `app/dealer/test-fetch.php` and `app/dealer/verify-deployment.php` are unauthenticated debug scripts and should be deleted from the server.
- Impersonation ("view as") is deferred.
- Non-admin sessions aren't re-checked on each request, so a suspended user stays signed in until their session expires.
- `reinstateDealer()` reactivates every paused car, including ones under a superadmin listing hold.
- User deletion (permanent delete for accounts with no history; erase personal data for the rest): proposed, not decided.
