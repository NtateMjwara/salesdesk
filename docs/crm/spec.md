# CRM in the portal — spec (v1)

**Status: approved by Sfiso (28 Nov 2026). In build.** Built from `docs/crm/brief.md` and the decisions below.
Branch `claude/crm`, migration `db/0027_crm.sql`, module `includes/crm.php`.

**Build progress**
- [x] Migration 0027 + `sdCrmReady()` + `sdCrmBackfill()` (tested locally on a repo-built and a live-shaped database)
- [ ] Timeline, tasks and assignment on the dealer and exec leads pages (shared partial, `crm.css` / `crm.js`)
- [ ] Lead submission: auto-assign and notify
- [ ] `cron/crm-alerts.php`, digest, nudge-check hand-over
- [ ] Broker status-only view and email
- [ ] POPIA scrub + Erase buyer data
- [ ] My day on dashboards

## 1. Decisions (settled 28 Nov 2026)

| Question | Decision |
|---|---|
| Who the CRM is for | **Dealerships and their sales execs first.** Brokers get a cleaner status-only view; broker CRM tools are a later phase. |
| Lead ownership | **Manual assignment with an automatic default.** A lead on a car an exec uploaded goes to that exec. Every other lead lands in an **Unassigned** queue for the principal to assign or reassign. Execs see only leads assigned to them. |
| Broker visibility | **Status only, no contact details.** Brokers see the buyer's first name, the car, the stage and key dates. No phone, email, buyer message, dealer notes or activity. |
| Plan gating | **Core free, advanced paid.** Everything in v1 is free on every plan. Phase 2/3 extras (reports, kanban, templates, group views) become Business Pro / Enterprise perks. |
| Buyer communication | **Click-to-contact and log.** WhatsApp, call and email buttons open the exec's own app with a prefilled message, and the contact is logged on the timeline. SalesDesk sends nothing to the buyer. |
| v1 scope | Activity timeline, follow-up tasks with reminders, assignment, speed-to-lead and stale-lead alerts, and the leads pages moved to external CSS/JS. |
| Alert thresholds | **No first contact within 2 business hours** → "untouched". **No activity for 5 days** on an open lead → "stale". |

## 2. What does not change

- Pipeline stages: new → contacted → test_drive → negotiation → closed / lost, the "lost" reason prompt, and closing.
- Closing still runs the existing commission flow, unchanged: commission row, network bonus, invoice, staff-close
  confirmation (0017) and the four-eyes rule (0016). Execs still can't close.
- Attribution: the CRM never writes `broker_id`, `salesdesk_id`, `organization_id`, `attributed_at`, network bonus
  columns or anything in `commissions`.
- Dealer workspace rules: every dealer write goes through `requireDealerWorkspace()`, viewers are read-only, and admin
  actions are stamped `_workspace`.
- The `leads.dealer_notes` column stays in the table (no data dropped). It becomes read-only once the CRM is live.

## 3. v1 features

### 3.1 Activity timeline (replaces the single notes box)

A newest-first timeline on each lead. Entries are **immutable**: a mistake is corrected with a new note, so the
timeline is a trustworthy record of what happened.

| Type | Logged by | Notes |
|---|---|---|
| `note` | person | Free text, up to 2,000 characters |
| `call` | person / contact button | Outcome: reached · no answer · voicemail · wrong number |
| `whatsapp`, `email` | person / contact button | Optional summary |
| `test_drive` | person | "Test drive done" with optional feedback (appointments with dates come in phase 2) |
| `status_change` | system | Written by the existing status update, e.g. "Contacted → Test drive" (and the lost reason) |
| `assignment` | system | "Assigned to Thabo by Sfiso", "Unassigned" |
| `task` | system | Task created, done or cancelled |
| `system` | system | Imported note, deal confirmed or disputed, buyer data erased |

- The composer has an activity type picker, a text box and an optional "Add a follow-up" shortcut (see 3.2).
- Any person-logged contact (`call`, `whatsapp`, `email`, `test_drive`) on a `new` lead sets `first_contact_at`, and
  offers to move the stage to **contacted** (one click, not automatic).
- Every entry updates `leads.last_activity_at`.
- **Migration of existing notes:** each non-empty `dealer_notes` becomes one `system` entry, "Note carried over
  from the old notes box", dated at the lead's `updated_at`. No history is lost.

### 3.2 Follow-up tasks

- A task has a title, a type (call / WhatsApp / email / test drive / other), a due date and time, and an assignee
  (defaults to the lead's assignee, or the person creating it).
- Actions: mark done (logs to the timeline), reschedule, cancel. Done and cancelled tasks stay visible on the lead.
- **Reminders:** an in-app notification and an email to the assignee when a task falls due (checked every 15 minutes).
  Overdue tasks appear in the daily digest (3.4).
- **My day** block on the dealer and exec dashboards: overdue tasks, tasks due today, untouched leads, stale leads.
  Each row links to the lead.
- Closing or losing a lead offers to cancel its open tasks.

### 3.3 Assignment

- New column `leads.assigned_user_id` (the user who works the lead: a verified sales exec of that dealership, or the
  principal).
- **Automatic default** at lead submission: if the car's `uploaded_by_exec_id` points to a **verified** exec of the
  same dealership, the lead is assigned to that exec. Otherwise it's unassigned.
- **Who can assign / reassign / unassign:** the principal, and admins in operator, delegate or group mode. Viewers and
  execs can't.
- Assigning notifies the new assignee (in-app and email) and logs an `assignment` entry.
- If an exec is suspended, rejected or leaves the dealership, their open leads go back to Unassigned, and the principal
  is notified.
- **Exec visibility changes:** execs see leads **assigned to them** (today: leads on cars they uploaded). With the
  automatic default, that's the same set on day one. A reassigned lead moves with the assignment.
- Existing leads are backfilled with the same rule as new ones.

### 3.4 Speed-to-lead and stale-lead alerts

- **Untouched:** a `new` lead with no `first_contact_at` after **2 business hours**. The assignee is notified (or the
  dealership contacts, if unassigned). At 4 business hours, the principal is notified too.
- **Stale:** an open lead (not closed or lost) with no activity for **5 days**. The assignee is notified.
- **Business hours (default, editable in `platform_config`):** Mon–Fri 08:00–17:00, Sat 08:00–13:00, SAST. Sundays
  and public holidays don't count. A lead that comes in at 16:30 on Friday is untouched at 09:30 on Saturday.
- Each alert fires **once per quiet period**: a new activity resets the clock, so a lead that goes quiet again can
  alert again.
- Leads show **Untouched** / **Stale** / **Overdue task** badges in the list, and they're filter tabs.
- **Daily digest** (08:00) to each dealership's principal: untouched, stale and overdue counts, with links. It's
  skipped when there's nothing to report.
- **Relationship to the existing `cron/nudge-check.php`:** once the CRM is live, the CRM alerts replace its 48-hour
  and 5-day dealer emails, so dealers don't get two sets. The 10-day admin flag stays as it is.

### 3.5 Contact buttons

- On the lead detail: **WhatsApp** (`wa.me` link with a prefilled greeting naming the buyer and the car), **Call**
  (`tel:`) and **Email** (`mailto:` with a subject).
- Clicking a button logs the activity (`whatsapp`, `call` or `email`) and asks for an optional outcome or summary.
  Logging must not block the button: the contact app opens either way.
- Phone numbers are normalised to the `27…` international format for WhatsApp.

### 3.6 Broker view (status only)

- `app/broker/leads.php` shows: buyer **first name**, the car, the stage, the enquiry date, the date the stage last
  changed, intent, and network bonus and commission status as today. It also shows a stage history built from
  `status_change` entries only (dates and stages, no notes).
- Removed for brokers: phone, email, the WhatsApp/call/email buttons, the buyer message, `dealer_notes` and the
  activity timeline.
- The broker's **new-lead email** (`sendNewLeadEmail(..., 'broker')`) stops including the buyer's phone number, and
  says the dealership will contact the buyer.
- This is a visible change for brokers (today they always see contact details). Suggested wording on the page:
  "The dealership contacts your buyer directly. You'll see every stage here, and your commission when it closes."

### 3.7 Page rework

- `app/dealer/leads.php`, `app/exec/leads.php` and `app/broker/leads.php` move their inline styles and scripts to
  `assets/css/crm.css` and `assets/js/crm.js` (data-attribute hooks), loaded with `sdAsset()`.
- The timeline, composer and task list are one shared partial, `views/partials/crm/lead-panel.php`, used by the
  dealer and exec pages so they behave the same.
- Dealer list columns: buyer, car, desk, intent, **assignee**, **next task**, stage, age, badges.
- Dealer filter tabs: All · **Unassigned** · **Untouched** · **Stale** · **Overdue tasks**, plus the existing stage tabs.
  There's an assignee filter too.
- Exec filter tabs: My leads · Untouched · Stale · Overdue tasks, plus stages.

## 4. Who can do what

| Action | Principal / operator / delegate / group | Viewer (admin) | Sales exec | Broker | Superadmin |
|---|---|---|---|---|---|
| See all the dealership's leads | yes | yes (read-only) | own assigned only | own desk, status only | via workspace |
| See buyer contact details | yes | yes | own assigned | **no** | via workspace |
| Add activity / log contact | yes | no | own assigned | no | via workspace |
| Create / complete tasks | yes | no | own assigned leads | no | via workspace |
| Assign / reassign | yes | no | no | no | via workspace |
| Move stage (pre-close) | yes | no | own assigned | no | via workspace |
| Close (commission flow) | yes, as today | no | no | no | as today |
| Erase a buyer's personal data | no | no | no | no | **yes** (step-up) |

## 5. Data model — `db/0027_crm.sql` (idempotent)

**`leads`: schema brought in line with the code** (each step only runs if needed, so a live database that was
changed by hand is left as it is)
- `attribution_type` ENUM('broker','platform') DEFAULT 'broker', added if missing (the prerequisite from
  `open-items.md`). Leads with no broker are set to 'platform'.
- `broker_id`, `salesdesk_id` made nullable (platform leads have neither).
- `buyer_name`, `buyer_phone` made nullable (the POPIA scrub sets them to NULL).

**`leads` additions**
- `assigned_user_id` INT UNSIGNED NULL, FK → users (ON DELETE SET NULL), index (`assigned_user_id`, `status`)
- `assigned_at` DATETIME NULL, `assigned_by_user_id` INT UNSIGNED NULL (NULL = automatic)
- `first_contact_at` DATETIME NULL
- `last_activity_at` DATETIME NULL, index (`dealer_id`, `status`, `last_activity_at`)

**`lead_activities`**
`id, lead_id (FK, cascade), dealer_id, type ENUM(note, call, whatsapp, email, test_drive, status_change,
assignment, task, system), outcome VARCHAR(30) NULL (call outcome, or what a system entry records, e.g.
`notes_carried_over`, `auto`, `unassigned`), body TEXT NULL, meta JSON NULL, actor_user_id NULL, actor_mode
VARCHAR(20) NULL (workspace mode), source_ref VARCHAR(40) NULL UNIQUE (set only by imports, e.g. `audit:123`,
`notes:45`, so the backfill is safe to re-run), created_at`. Index (`lead_id`, `created_at`), (`dealer_id`, `created_at`).

**`lead_tasks`**
`id, lead_id (FK, cascade), dealer_id, title VARCHAR(150), type ENUM(call, whatsapp, email, test_drive, other),
due_at DATETIME, assignee_user_id, created_by_user_id, done_at NULL, done_by_user_id NULL, cancelled_at NULL,
reminded_at NULL, created_at, updated_at`. Index (`assignee_user_id`, `done_at`, `due_at`), (`lead_id`).

**`lead_alerts`** (one row per alert sent, so each fires once per quiet period)
`id, lead_id (FK, cascade), alert_type ENUM(untouched, untouched_escalated, stale), anchor_at DATETIME, sent_at`.
UNIQUE (`lead_id`, `alert_type`, `anchor_at`). `anchor_at` is the `last_activity_at` (or `created_at`) the alert was
measured from.

**`platform_config` keys** (seeded, editable): `crm_untouched_business_hours` = 2, `crm_untouched_escalate_hours`
= 4, `crm_stale_days` = 5, `crm_business_hours` = `mon-fri 08:00-17:00; sat 08:00-13:00`, `crm_extra_holidays`
(one-off dates; the regular South African public holidays, including Easter and Sunday-to-Monday moves, are worked out
in code) and `crm_live_since` (when 0027 ran). Leads that went quiet before `crm_live_since` show their badges and count
in the digest, but don't each send an alert on the first day.

**PHP post-step `sdCrmBackfill()`** (in `SD_MIGRATION_POST`):
1. Write `status_change` entries from the `lead.status_changed` audit history, including the notes typed with each
   change and the workspace mode, so old leads have a timeline.
2. Carry each `dealer_notes` over as a `system` / `notes_carried_over` entry, unless that exact text already came in
   with the history.
3. Apply the default assignment rule to every lead (open and closed, so an exec keeps seeing what they see today).
   Leads that were ever assigned or unassigned by hand are skipped.
4. Set `first_contact_at` from the first audited stage change (or `status_updated_at` when there's no history), and set
   `last_activity_at`. The lead's own `updated_at` isn't changed.

Each step is safe to re-run.

**`sdCrmReady()`** checks the new tables and columns. Before the migration runs, the leads pages work exactly as they
do today.

## 6. Module and files

**New**
- `includes/crm.php`: `sdCrmReady()`, `sdCrmAddActivity()`, `sdCrmLogContact()`, `sdCrmAssign()`,
  `sdCrmAutoAssign()`, `sdCrmCreateTask()`, `sdCrmCompleteTask()`, `sdCrmCancelTask()`, `sdCrmTimeline()`,
  `sdCrmMyDay()`, `sdCrmAlertsRun()`, `sdCrmDigestRun()`, `sdCrmBusinessHoursBetween()`, `sdCrmEraseBuyer()`,
  `sdCrmBackfill()`, and a single `sdCrmCanSeeContact()` / `sdCrmCanWrite()` that holds the visibility rules from §4.
  Mutators return `[bool, string]` and write audit entries.
- `db/0027_crm.sql`
- `cron/crm-alerts.php` (every 15 minutes: untouched, stale and task reminders; at 08:00 it also sends the digest)
- `views/partials/crm/lead-panel.php`, `views/partials/crm/my-day.php`
- `assets/css/crm.css`, `assets/js/crm.js`

**Changed**
- `app/dealer/leads.php`, `app/exec/leads.php`, `app/broker/leads.php` (rework, see 3.6 and 3.7)
- `app/dealer/dashboard.php`, `app/exec/dashboard.php` (My day)
- `api/leads/submit.php` (auto-assign, first activity, notify the assignee)
- `includes/mailer.php` (task reminder, assignment, untouched/stale, digest emails; broker new-lead email without
  the phone number)
- Exec status changes (suspend / reject / leave): unassign their open leads
- `cron/nudge-check.php` (skip the 48-hour and 5-day dealer nudges when `sdCrmReady()`)
- `cron/popia-scrub.php` (also scrubs CRM data, see §7)
- `includes/migrations.php` (register 0027 and its post-step)
- `app/admin/…` lead view: superadmin "Erase buyer data"
- Docs: this spec, `brief.md`, `decision-log.md`, `architecture.md`, `open-items.md`

## 7. POPIA

- Brokers no longer see buyer contact details anywhere (page, email, notification).
- Activity notes and task titles can contain personal information. The weekly `popia-scrub.php` clears them
  along with the lead: it replaces `body`, `outcome` and task titles with "[removed]" on leads past
  `popia_retention_days`, and keeps types and dates for the record.
- **Erase buyer data** (superadmin, step-up, reason required): runs the same scrub straight away on one lead, for an
  access or deletion request. It's audited as `lead.buyer_erased`.
- The lead panel shows the buyer's consent and when they gave it.
- Placeholder text in the composer nudges execs to record only what the sale needs (no ID numbers or banking details).

## 8. Audit actions (new)

`lead.activity_added`, `lead.contact_logged`, `lead.assigned`, `lead.unassigned`, `lead.task_created`,
`lead.task_completed`, `lead.task_rescheduled`, `lead.task_cancelled`, `lead.buyer_erased`. The existing
`lead.status_changed` is kept.

## 9. Testing (before handover)

- A local MariaDB seeded with leads in every stage, with and without notes, on cars with and without an uploading
  exec, plus a principal-owned and an admin-run dealership.
- Before migration 0027: every leads page and dashboard loads and behaves as today.
- After it: the backfill result (notes carried over, timeline from the audit log, assignments), then each role in
  §4, including viewer writes being blocked and an exec opening another exec's lead by URL (must be refused).
- Closing still creates the commission, the invoice and the staff-close confirmation exactly as before.
- Cron: untouched timing across the business-hours edges (Friday evening, Saturday afternoon, a public holiday),
  alerts not repeating, reminders, the digest, and the dealer not getting the old nudges as well.
- Broker page and new-lead email show no contact details.
- `php -l` on every changed file.

## 10. Later phases (not in v1)

- **Phase 2:** buyer record (repeat enquiries joined by phone or email per dealership), kanban board, saved filters,
  CSV export, test-drive appointments with dates.
- **Phase 3 (Business Pro / Enterprise):** reports (funnel, response times, exec leaderboard, lost reasons),
  WhatsApp/email templates, trade-in and finance details, group-wide CRM for Enterprise owners.
- **Broker tools:** to be scoped once the dealer CRM is in use.

## 11. Still to confirm with Sfiso

1. **Business hours:** are Mon–Fri 08:00–17:00 and Sat 08:00–13:00 right for most dealerships?
2. **Can execs claim leads from the Unassigned queue themselves?** The spec says no: only the principal assigns.
3. **Broker wording:** the copy in 3.6 that explains why contact details are gone.
4. **Server:** a cron slot every 15 minutes for `cron/crm-alerts.php`. Check the host allows that frequency.
