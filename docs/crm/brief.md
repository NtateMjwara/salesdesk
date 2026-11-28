# CRM in the portal — starting brief (28 Nov 2026)

**Status: specced (28 Nov 2026).** The questions below were settled with Sfiso, and the v1 spec is in
`docs/crm/spec.md` (draft, awaiting his sign-off). Don't start building before he has agreed that spec.

## Goal

A more robust CRM inside the portal, so the people who work leads can manage buyers properly instead of moving a status
and overwriting one notes box.

## What exists today

- **Pipeline:** `leads.status` = new → contacted → test_drive → negotiation → closed / lost. "Lost" prompts a reason.
  "Closed" opens the commission modal and creates the commission.
- **Notes:** a single `leads.dealer_notes` text field (500 chars on the exec form), overwritten on each update.
  No history, author or timestamps apart from the audit log.
- **Dealer** (`app/dealer/leads.php`): list + detail panel, filter tabs by status, search, intent badge (Hot / Warm /
  Browsing), locked attribution block, confirm / dispute for staff-closed deals.
- **Sales exec** (`app/exec/leads.php`): leads on cars they uploaded. Can move pre-close stages and add notes, but can't
  close.
- **Broker** (`app/broker/leads.php`): leads from their desk. Read-only pipeline, buyer details shown once the
  status is past "new", network bonus shown.
- **Audit:** `lead.status_changed` on every transition.
- **Business dashboard** (VIP): per-broker leads and sales by period, with CSV.
- The pages have heavy inline styles. A CRM rework is the moment to move them into external CSS/JS.

## Questions for Sfiso (settle these first)

1. **Who is the CRM for?** Dealerships and their sales execs working leads, brokers managing their own buyers, or both
   (and which first)?
2. **Buyer data visibility.** Still undecided: should brokers see less buyer information, since the dealership also
   gets the enquiry? This decides which roles see contact details, notes and activity.
3. **Lead ownership inside a dealership.** Assign leads to a specific sales exec? Round-robin? Can execs see each
   other's leads?
4. **Scope of v1.** Candidates: activity timeline (notes with author and time, calls, emails, WhatsApp, test drives),
   follow-up tasks with due dates and reminders, lead assignment, stale-lead alerts, a buyer record that joins repeat
   enquiries, kanban pipeline view, saved filters, CSV export, lead sources and reporting.
5. **Plan gating.** Is the CRM (or its advanced parts) a Business Pro/Enterprise perk behind `entitlements.php`, the
   same way VIP perks are?
6. **Buyer communication.** Log contact only, or send email/WhatsApp from the portal?

## Constraints (non-negotiable)

- **Attribution stays locked.** The CRM must never change which desk or broker a lead is credited to, or touch
  `leads.organization_id`, network bonus or commission logic. Closing still runs the existing commission flow, including
  staff-close confirmation and the four-eyes rule.
- **Dealer workspace rules apply.** Every write goes through `requireDealerWorkspace()`. Viewers stay read-only.
  Admin actions are stamped `_workspace`.
- **POPIA.** Buyer data is personal information. Respect `consent_given` / `consent_at`, collect only what's needed,
  define retention, support access and deletion requests, and don't expose buyer contact details to roles that
  shouldn't see them.
- **Audit every change.** New actions in the `lead.*` namespace.
- **Schema-ready guards.** Pages must work before the CRM migration has run (`sdCrmReady()`).
- **No inline CSS/JS.** Shared CSS/JS and data-attribute hooks.

## Prerequisites

- Fix `leads.attribution_type` (see `open-items.md`) so the leads table schema matches the code.
- A local MariaDB with realistic leads in every status, for testing.

## Suggested shape (to confirm in the spec)

- New module `includes/crm.php` and migration `db/0027_crm.sql`.
- Likely tables: `lead_activities` (lead_id, type, body, actor, created_at), `lead_tasks` (lead_id, assignee, due_at,
  done_at, created_by), possibly `leads.assigned_exec_id`.
- Migrate the existing `dealer_notes` into the first activity row, so no history is lost.
- Reminders via notifications plus the daily cron.
