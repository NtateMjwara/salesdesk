# Plans & billing — spec (Nov 2026)

## Decided
- **Two paying account types: Broker and Business.** Sales execs work under their dealership and have no plan. SalesDesk is the platform and owns no org.
- **The org *is* the business account.** Registering a business (a single dealership's company, or a brand/group) creates its organisation automatically. One org per business, one business per org.
- **Brokers never own or create orgs, on any plan.** They only join. This reverses the broker-created orgs still in the data, and confirms Sep 2026's removal of the broker org wizard.
- **Open marketplace with VIP networks.** Any broker can still list any dealer's car. Joining a business's network *adds* access and perks; it never restricts what a broker can list. The old "agent mode" (catalogue limited to the org's 1–3 brands) and the 3-brand rule are gone (2a).
- **SalesDesk running a network for a brand** (e.g. before Toyota SA signs) = an **admin-managed business**, using the existing no-principal dealerships, operator mode and handover. It is never a separate "platform org".
- **Prices exclude VAT.** Add VAT when SalesDesk has to register. Then decide whether shown prices become VAT-inclusive.
- The org takes no commission cut (unchanged). The platform fee on commission is the same on every plan. **Commission is always paid to the broker's own bank account.**
- **VIP perks list confirmed (26 Nov 2026)**, for Pro and Enterprise businesses.
- **The two production orgs are deleted, to start over (26 Nov 2026)** — done by 0020.
- **Enterprise (2d): one principal per dealership stays** (`dealers.user_id` UNIQUE). The business **owner** (`organizations.owner_user_id`, one of its dealerships' principals) switches between all the business's dealerships in the portal ('group' mode).
- **Payment provider: PayFast (decided 26 Nov 2026).** Sfiso registered a PayFast account and enabled the recurring-billing passphrase. Brokers pay Pro by PayFast monthly subscription.
- **Business billing is hybrid (decided 27 Nov 2026, BUILT same day):** **Business Pro pays by PayFast** subscription (self-serve, same plumbing as brokers); **Enterprise is invoiced** monthly by SalesDesk and paid by EFT (its own monthly invoice, not a line on the commission invoice).
- **VIP perks ship one by one (27 Nov 2026)** behind the single `vip_perks` limit (Pro and Enterprise businesses). 4a = network-only stock + network bonus; 4b = network badge, business dashboard, announcements.

## Account model
```
Organisation (= business account: plan, broker network, settings, owner)
 ├─ Dealership(s)   Free/Pro: 1 · Enterprise: multiple (each with its own principal or admin-managed)
 │    └─ Sales execs (users of the dealership, no plan)
 └─ Network members = brokers (apply / are invited; pending|invited → verified)

Broker (person) ── 1 desk ── lists any car (open marketplace)
                └─ member of N business networks (plan-limited)
```
- `organization_members` holds **brokers only**. Business staff (principal, execs, managing admins) get access through their dealership relationship (dealer workspace rules), not membership rows.

## Plans

### Broker
| | Free | Pro |
|---|---|---|
| Price | R0 | R149 / month (seeded at R99; editable in Admin → Plans — online checkout and /pricing use the current price) |
| Desk cars | 150 | Unlimited |
| Networks they can join | 3 | Unlimited |
| Own / create an org | Never | Never |

### Business (dealership / brand)
| | Free | Pro | Enterprise |
|---|---|---|---|
| Price | R0 | R3,000 / month | R12,000 / month |
| Dealerships | 1 | 1 | Multiple |
| Cars | Unlimited | Unlimited | Unlimited |
| Brokers in network | 5 | 100 | Unlimited |
| Brokers apply → accept / decline | ✓ | ✓ | ✓ |
| Browse brokers | ✓ | ✓ | ✓ |
| Invite brokers | — | ✓ | ✓ |
| Commission settings | ✓ | ✓ | ✓ |
| Sales execs | Unlimited | Unlimited | Unlimited |
| VIP perks (see below) | — | ✓ | ✓ |

### VIP network perks (confirmed — Pro and Enterprise)
- **Network-only stock:** the business marks cars as visible and listable only by its verified network brokers.
- **Network bonus:** an extra commission amount or % for network brokers on that business's cars.
- **Badge:** network badge on the broker's desk and on the org hub (`/desks/{org}/`).
- **Business dashboard:** announcements to the network, and per-broker views, leads and sales.

## Rules
- **Lead org attribution (built, 2a):** `leads.organization_id` = the org that owns the car's dealership **if** the broker is a verified member of it at submission. Otherwise NULL (independent). Snapshotted at submission. No per-listing org column: the car's dealership decides it (`sdLeadOrgSubquery`). Works across all dealerships of an Enterprise business.
- **Effective limit** = superadmin custom limit (`account_limit_overrides`) if set, else the plan's value. NULL = unlimited.
  - Agent mode is retired (2a): no org cap on desk cars any more. The `agent_car_limit` and `brands` columns are still in the schema, unused.
  - `profiles.car_limit` is legacy: it's read only before 0019 has run.
- **Who approves brokers into a network:** the principal (dealer portal → Network, 2c) — in a **multi-dealership business only the owner** (2d) — plus the admins managing the dealership (Approvals, Business tab, 2b). An admin with write access in the dealer workspace (operator / delegate) can use the Network page; viewers and non-owner principals of a multi-dealership business see it read-only. Sales execs: no access. Applications notify the principal (owner in a multi-dealership business) and the managing admins; no manager → superadmins.
- **Networks start closed** (`accepting_applications = 0`). The principal / owner or a managing admin opens one. Only an online dealership's network can open.
- **Network limits:** pending + verified memberships count towards the broker's `max_networks` (invitations count once accepted). A full network (`max_network_brokers` verified) shows "Network full" to brokers and refuses approve / reinstate / accept-invite.
- **Invitations (2c, 0021):** Pro / Enterprise (`can_invite_brokers`) invite existing brokers from Network → Find brokers. Row status `invited`, `invited_by`, `joined_at` = sent. Open invitations + members can't exceed the network size; max 25 invitations per business per 24 h (`SD_NETWORK_INVITES_PER_DAY`). The broker accepts (→ verified, `verified_by = invited_by`) or declines (row deleted). Re-inviting after a decline is allowed. Email invitations to people not yet on SalesDesk: not built.
- **Find brokers** shows public desk details only (name, desk, city/province, cars, closed deals, network count) — no email or phone until a broker applies or joins.
- **Enterprise (2d):**
  - Superadmin attaches dealerships on the Business tab, up to `max_dealerships`. One business = one plan, one network, one owner.
  - **Attaching merges** the dealership's own business when it has no other dealership: members move (a broker in both keeps the stronger status), leads / commissions / payouts are re-tagged, legacy managers copied, the empty business deleted. A business on a paid/comped plan must be moved to Free first. **The surviving business is the one you attach *into*** — the merged business's network page goes away.
  - Multi-dealership businesses keep their own name (superadmin renames; slug never changes); `sdSyncOrgFromDealer` only mirrors single-dealership businesses.
  - **Owner** = one of its dealerships' principals (superadmin picks). The owner's dealership can't be moved out until another owner is chosen.
  - **Move out** gives a dealership its own business again (Free, closed network); the network stays with the business it leaves.
  - **Group mode** in the portal: the owner can run every dealership in the business; company / verification / address settings stay principal-only where the sister dealership has its own principal; deals they close are `closed_via = 'principal'` (business), not staff. Switching is re-checked on every request.
  - Plan / custom-limit changes can't take `max_dealerships` below the current dealership count (`sdDealershipLimitGuard`).
- **Online billing (Phase 3, PayFast):**
  - Broker clicks **Upgrade to Pro** → `billing_checkouts` row (our `m_payment_id`) → `/app/billing/pay` posts a signed form to PayFast `/eng/process`: `subscription_type=1`, `frequency=3` (monthly), `cycles=0` (until cancelled), `amount` = `recurring_amount` = the plan's current price. Prices exclude VAT.
  - **ITN** (`/api/billing/payfast-notify`): four checks — signature (with passphrase), PayFast source host, amount (first payment vs checkout, renewals vs `subscriptions.amount_cents`), server confirmation at `/eng/query/validate` — then COMPLETE first → active (token stored in `provider_ref`); COMPLETE renewal → paid-through moves on; FAILED → `past_due` (plan kept to the paid-through date); CANCELLED → `cancel_at_period_end`. Every ITN is logged in `billing_events`; repeats are ignored (`pf_payment_id` + status).
  - **Paid-through:** each payment covers 1 month + `SD_BILLING_GRACE_DAYS` (3) → `current_period_end`. Access stops after it via `sdSubscriptionGrants()` even without cron.
  - **Cancel:** broker or superadmin → PayFast API `PUT /subscriptions/{token}/cancel` (api.payfast.co.za, v1, signed headers; `?testing=true` in sandbox) → plan runs to the paid-through date. Re-subscribing waits until it ends.
  - **Manual changes:** a superadmin can't change the plan of a broker who pays through PayFast until the online subscription is cancelled (`sdSetAccountPlan` guard).
  - **Lapse:** `cron/billing-daily.php` removes subscriptions past `current_period_end` (any provider), audits `plan.changed`, notifies the account (the message explains the 7-day grace); expires unfinished checkouts after 2 days. A late renewal after a lapse restores the plan.
  - **Settings:** `includes/payfast.config.php` on the server only (template `payfast.config.example.php`): `PAYFAST_MODE` (sandbox|live), merchant ID, key, passphrase, `PAYFAST_TRUST_PROXY`. Without it, billing is off and brokers see "Request Pro". Sandbox has its own credentials.
- **Desk title with several networks:** the first network joined names the desk (`sdDeskOrg`, ordered by `verified_at`). A broker-chosen primary network can come later.
- **Downgrade / over limit** (nothing is ever deleted):
  - **Broker over the car limit — BUILT (3b, 0023):** 7-day window, then the cars not chosen are **parked** (details below).
  - **Broker over the network limit:** picks which memberships to keep; the rest are paused, not deleted. *(Not built. Today: can't apply or accept until under the limit; existing memberships stay.)*
  - **Business over the broker limit:** can't accept or invite until under the limit; existing members stay.
  - **Enterprise over its dealership limit — BUILT (3b, 0023):** a paid plan lapses with several dealerships → 7-day window → the extras stop adding new cars (existing cars stay live). A manual plan change below the dealership count is still refused (`sdDealershipLimitGuard`).
- **Downgrade grace (3b) — `includes/plan_grace.php`, migration `0023_plan_grace.sql`:**
  - **Triggers:** `sdPlanChangedRecheck()` (entitlements.php) after `sdSetAccountPlan` / `sdSetLimitOverride` / `sdClearLimitOverride`; PayFast activation and lapse (billing.php); attach / detach (business.php, both businesses). `sdGraceDaily()` in `cron/billing-daily.php` re-checks everyone and enforces windows that ended. `SD_GRACE_DAYS = 7`.
  - **Plan cache:** `sdAccountPlan(type, id, $fresh)`. The recheck re-reads the plan first; the per-request cache otherwise hid a lapse from the same cron run.
  - **Broker:**
    - The window opens when live cars exceed `max_desk_cars`: a notification links to `/app/broker/keep-cars`, and a banner shows on Dashboard / Plan / Inventory.
    - Default keep = most leads, then newest. The broker can **Save choice** (`plan_grace.keep_ids`) or **Pause the rest now**.
    - At the deadline: chosen cars first, then the ranking, up to the limit. The rest move to `broker_inventory_parked` with the same id, tracking code, views and added date, so every `broker_inventory` read ignores them. Tracked links of parked cars 404.
  - **Restore:**
    - Only after a plan / limit change (upgrade, higher custom limit) do parked cars return automatically, best first. The daily run and the broker's own removals never refill a slot.
    - On My Desk the broker can **Bring back** (needs a slot) or **Remove** a paused car. Re-adding it from the Marketplace restores the same row.
    - `car_views` links are rebuilt by tracking code.
  - **Back under the limit** during the window → window resolved. Resolutions: `under_limit`, `applied`, `enforced`.
  - **Enterprise:**
    - The window opens when dealerships exceed `max_dealerships`. The owner and superadmins are notified; the owner dashboard and the admin Dealerships card show the deadline.
    - At the deadline the owner's dealership, then the oldest, keep listing; the rest get `dealers.listings_paused_at`.
    - Guards: `sdAssertDealerCanList()` in the three importers' `insertCar` (the row is reported as failed) and the upload wizard's publish step.
    - The pause lifts as soon as the business fits (renew, custom limit, move out).
  - **UI:** `views/partials/grace-banner.php`; `views/partials/dealer-listings-paused.php` (dealer Inventory and imports); the admin users-view plan card; Business tab "Listings paused" badges. Notifications now render `meta.link` (same-site paths only).
- **VIP perks 4a — `includes/vip_network.php`, migration `0024_vip_network.sql`:**
  - **Gate:** `vip_perks` on the business (`sdOrgVipActive`). Off → settings kept but not in force.
  - **Network bonus:** `organizations.network_bonus_type` (fixed | percentage of price) / `_value` (max 10% or R100,000; `sdSetOrgBonus`). Locked onto the lead at submission when `leads.organization_id` is set (the broker is a verified member of the car's business) and the business has VIP perks (`leads.network_bonus_*`). On close: `sdBonusAmount()` → added to `gross_amount` and stored in `commissions.network_bonus`; platform fee on the whole gross. Shown in the Marketplace (members), My networks, the close preview, the dealer invoice, broker Leads and Earnings.
  - **Network-only stock:** `cars.network_only` (choice) and `cars.network_exclusive` (in force = choice AND VIP). Marketplace: `sdMarketplaceNetworkSql()` (members of the car's business only) + add-to-desk check `sdBrokerMayListCar()`. Public pages: `sdPublicCarSql()` — an exclusive car only through a desk row (non-member rows are parked, so any desk row is a member's); the platform URL 302s to the first network desk or 404s; direct (platform) leads refused.
  - **Parking:** non-members' desk rows → `broker_inventory_parked` reason `network` (notification). Restored automatically when allowed (car opened, VIP lost, broker joins / reinstated) while the desk has room; rows that don't fit become `plan_limit` pauses. Plan restores and "Bring back" skip cars the broker may not list.
  - **Sync points:** `sdVipSyncCars` (dealer marks cars), `sdVipSyncOrg` (plan / limit change via `sdPlanChangedRecheck`, attach), `sdVipSyncDealer` (detach), `sdVipSyncBroker` (membership changes via `sdNetworkMembershipChanged`), `sdVipDaily` (cron).
  - **UI:** dealer Network → **VIP perks** tab (`views/partials/dealer-network-perks.php`, `assets/js/dealer-perks.js`); upload wizard "Network only" tick box (principal / owner); dealer inventory badge; broker Marketplace badge, bonus chip and **Networks** filter (`net=mine|orgId`); My networks perk chips; admin Business plan card summary.
  - Audit: `org.network_bonus_changed`, `car.network_only_changed`.
- **VIP perks 4b — `includes/network_dashboard.php`, migration `0025_network_dashboard.sql`:**
  - **Network badge:** `sdBrokerVipNetworks(userId)` → gold "{Business} network" tag on the broker's public desk (`broker/index.php`) for each VIP network they're verified in (non-VIP networks keep the plain tag). VIP business hub (`desks/org.php`): "VIP broker network" pill + "{Business} network broker" badge on member cards (`sdDeskCard` opt `network_badge`).
  - **Business dashboard** (dealer Network → **Dashboard**, everyone in the workspace can read): `sdNetworkPerformance(orgId, days)` per verified / suspended broker — cars of the business on their desk now, views (`car_views` by the broker's tracking codes, live or parked rows), leads (`leads.car_id` in the business's cars), sales + commission (`commissions` on its dealerships). Periods 30 / 90 / 365 / all (`SD_NETWORK_DASH_PERIODS`); brokers outside the network roll into one line; CSV download.
  - **Announcements** (`network_announcements`): posted by `sdCanManageNetwork` (principal / owner) via `sdPostAnnouncement` — title 3–120, message ≤ 2,000; in-app notification (type `network_announcement`, link `/app/broker/networks#net-{org}`) to every verified broker; optional email (`sendNetworkAnnouncement`) when the network has ≤ 250 brokers; max 3 per business per 24 h counted from the audit log (removing doesn't free a slot). Brokers read them on My networks (`sdBrokerAnnouncements`, latest 3 per network). Admin Business tab lists the latest 3.
  - Audit: `org.announcement_posted`, `org.announcement_removed`.
- **Business billing (0026) — `includes/billing.php` + `includes/invoicing.php`:**
  - **Who pays for a business:** `sdCanManageBilling(ws, orgId)` — the business owner, or the principal of a single-dealership business (modes principal / group, write access). Staff, viewers and sister principals: read-only. Notices go to `sdBillingOrgContacts()` (owner, else principals).
  - **Business Pro online:** dealer portal **Plan** page (`/app/dealer/plan`) → `sdBillingStartCheckout('organization', …)` → `/app/billing/pay` (brokers or dealers) → same ITN / renew / fail / cancel / lapse as brokers; return / cancel URLs from `sdBillingUrls($type)`. Plans in `SD_INVOICED_PLAN_CODES` (`business_enterprise`) are refused online.
  - **Enterprise invoiced:** superadmin **Start invoicing** on the Business tab (`sdStartInvoicing`) → subscription `provider = 'invoice'`, first invoice due in `SD_INVOICE_DUE_DAYS` (7). Monthly: `sdInvoiceDaily()` issues the next invoice `SD_INVOICE_LEAD_DAYS` (7) before the period ends, due when it starts; overdue → `past_due` + one reminder (business + superadmins). `current_period_end` = last paid period end + `SD_INVOICE_GRACE_DAYS` (7) (before the first payment: first due + 7), so an unpaid invoice lapses through `sdBillingLapse()` 7 days after its due date. **Mark paid** (EFT reference) extends it, or restores a lapsed plan; **Void** (reason), **Resend**, **Stop invoicing** (plan runs to the end of the last paid month). Invoice numbers `SDB-YYYY-00001` (`business_invoice_prefix`); EFT details from the `salesdesk_bank_*` platform config. Printable invoice `/app/billing/invoice?id=` (superadmins; principals / owner of the business). Prices exclude VAT, no VAT line.
  - **Asking:** "Talk to us about Enterprise" / "Request Pro" (billing off) → `sdRequestBusinessUpgrade()` notifies superadmins (one per 7 days per business).
  - **Guards:** manual plan changes refused while a business pays online or is invoiced (stop / cancel first).
  - **Admin:** Business tab — PayFast status + cancel, Invoices section; Admin → Plans — Business invoices (invoiced MRR, open / overdue, paid 30 days, open list), business links in the PayFast log.
  - Audit: `invoice.issued`, `invoice.paid`, `invoice.voided`, `plan.changed` / `plan.cancelled` (via `invoicing_stopped`), `plan.upgrade_requested` (organization).
- **Superadmin** can comp any plan, set custom limits per account, and see MRR. Plan and custom-limit changes are superadmin-only and audited (`plan.*`).

## Data model
- `plans` (code, audience `broker|organization`, name, tagline, price_cents, billing_interval, is_default, is_active, sort_order). **Built (0019).**
- `plan_limits` (plan_id, limit_key, limit_value; NULL = unlimited, booleans 0/1). Keys: `max_desk_cars`, `max_networks`, `max_dealerships`, `max_network_brokers`, `can_invite_brokers`, `vip_perks`. **Built (0019).**
- `subscriptions`: one current row per account (account_type, account_id, plan_id, status `active|comped|past_due|cancelled`, current_period_end, provider, provider_ref, note, updated_by). No row = default (Free). History is in audit_logs. **Built (0019).** **0022 adds** `amount_cents`, `cancel_at_period_end`, `last_payment_at`, `last_failed_at` and index (provider, provider_ref).
- `account_limit_overrides` (account_type, account_id, limit_key, limit_value, reason, set_by, set_at). **Built (0019).**
- **Built (0020):** `organizations.owner_user_id` nullable, FK `ON DELETE SET NULL` (admin-managed businesses). Kept: `accepting_applications`, slug, logo, verification. Still to drop later: `brands`, `agent_car_limit`.
- **Built (0020):** `dealers.organization_id`, nullable with FK `ON DELETE SET NULL`. It stays nullable so a deleted org never blocks a dealership; the app heals a missing org with `sdEnsureDealerOrganization`. Free/Pro are capped at 1 dealership by entitlement, not by schema.
- **Built (0020):** `organization_members` `UNIQUE (user_id)` dropped (multi-network); `uq_org_member (organization_id, user_id)` stays. Role is effectively always `agent`.
- **Built (0021):** `organization_members.status` gains `invited` (enum: pending, verified, rejected, suspended, invited).
- **2d:** no schema change. `dealers.user_id` stays UNIQUE (one principal per dealership). Session key `dealer_active_id` holds the owner's chosen dealership.
- **Built (0022):** `billing_checkouts` (m_payment_id UNIQUE, account, plan, amount_cents, recurring_cents, user_id, status started|paid|cancelled|failed|expired, pf_payment_id, token) and `billing_events` (every ITN: ids, token, payment_status, amounts, account, signature_ok / source_ok / amount_ok / server_ok, outcome, remote_ip, payload JSON).
- **Built (0023):** `plan_grace` (account_type, account_id, limit_key, limit_value, used_at_start, started_at, ends_at, keep_ids JSON, resolved_at, resolution); `broker_inventory_parked` (id = the original broker_inventory id, salesdesk_id, car_id, tracking_code, views, added_at, parked_at, reason; unique desk+car and tracking_code; FKs to cars and salesdesks cascade); `dealers.listings_paused_at`.
- **Built (0024):** `cars.network_only`, `cars.network_exclusive` (+ index with status); `organizations.network_bonus_type` / `network_bonus_value`; `leads.network_bonus_type` / `network_bonus_value`; `commissions.network_bonus`.
- **Built (0025):** `network_announcements` (organization_id, author_user_id, title, body, recipients, emailed, created_at).
- **Built (0026):** `business_invoices` (invoice_number UNIQUE, organization_id NULL on delete + billed_to snapshot, plan_id, plan_name, period_start / period_end (exclusive), amount_cents, status open|paid|void, issued_at, due_at, paid_at, paid_reference, marked_by, emailed_at, reminded_at, void_reason); `platform_config.business_invoice_prefix` = SDB. `subscriptions.provider` gains `invoice`.

## One gate for every limit — `includes/entitlements.php`
- `entitlement(type, id, key)` → ?int: custom limit, then plan, then fallback. Unknown keys return 0 (deny).
- `canDo(type, id, key)` → bool. `sdHasRoom(limit, used)`.
- `sdPlanLimit(plan, key)`: always use this instead of `??` on limit values, because NULL means unlimited.
- `sdDealershipLimitGuard(orgId, newLimit)` (2d): used by `sdSetAccountPlan`, `sdSetLimitOverride` and `sdClearLimitOverride`.
- **Enforcement points:**
  - Add car to desk ✅ (Phase 1; 3b: re-adding a paused car restores it).
  - Apply to a network ✅ (2b, `max_networks`, also at signup) · approve / reinstate a broker ✅ (`max_network_brokers`) · invite a broker ✅ (2c, `can_invite_brokers` + `max_network_brokers` incl. open invites) · accept an invitation ✅ (2c, both) · add a dealership ✅ (2d, `max_dealerships`).
  - New listings of a paused Enterprise dealership ✅ (3b: upload publish + the three importers).
  - Network-only stock ✅ (4a: Marketplace, add to desk, public pages, direct leads) · network bonus ✅ (4a: locked on the lead, paid on close).

## Business org module — `includes/business.php` (2a/2d)
- `sdBusinessReady()`: true once `dealers.organization_id` exists.
- `sdEnsureDealerOrganization(dealerId, actorId)`: creates the org if missing (owner = principal or NULL, `accepting_applications = 0`, mirrors address/logo/verification). Audited as `org.business_created`.
- `sdSyncOrgFromDealer(dealerId)`: keeps a single-dealership org in step (name, logo, address, owner, verification). **The slug is fixed once real**: only a placeholder `my-dealership…` slug is renamed, so `/desks/{slug}/` never moves. Enterprise orgs with several dealerships keep their own name and logo.
  - Called from: admin create/edit dealership, dealer settings (company, address), signup company and address steps, CIPC approve/reject, principal handover.
- `sdBackfillBusinessOrgs()`: run by the migration runner straight after 0020's SQL (`SD_MIGRATION_POST` in `includes/migrations.php`).
- `sdLegacyOrgSql(alias)`: leaves business orgs out of the superadmin orphan/assignable/portfolio lists, the dashboard's pending-org count and the Verifications queue (a business org is verified through its dealership).
- **2d:** `sdOrgDealerships(orgId)`, `sdAttachableDealerships(orgId)`, `sdOrgDealerLimit(orgId)`, `sdAttachDealership(orgId, dealerId, actor)`, `sdDetachDealership(dealerId, actor)`, `sdRenameBusiness(orgId, name, actor)`, `sdSetBusinessOwner(orgId, userId, actor)` — all superadmin-only (checked inside). Audit: `org.dealership_attached|detached`, `org.business_merged`, `org.business_renamed`, `org.owner_changed`. Attach / detach re-check the downgrade grace (3b).
- **Portal access (`includes/dealer_context.php`, 2d):** `sdGroupDealerAccess(userId, dealerId)` → 'group' workspace row; `sdPrincipalDealerships(userId)` (own + owned business's); `sdInGroupWorkspace()`. Switch endpoint `POST /app/dealer/switch`; switcher bar in `views/layout-app.php`. `sdInStaffWorkspace()` is true in group mode (so the sister principal's personal profile and SalesDesk-access settings stay hidden), but group mode is not "staff" for deal closing or the staff banner.

## Network module — `includes/org_membership.php` (2b/2c/2d)
- **Broker side:** `brokerNetworks(userId)` (invitations first, then verified, pending, suspended, rejected); `getBrokerMembership()` = first non-invitation; `listOpenNetworks(userId?, province?)`; `applyToNetwork()` (refuses closed/full/duplicate/over-limit; applying where invited = accept); `brokerLeaveNetwork()` (withdraw / leave / clear / decline invite); `brokerRespondInvite(userId, orgId, accept)`; `brokerNetworkAllowance()`.
- **Business side (shared by dealer portal + admin):** `sdNetworkReview(orgId, memberId, action, reason, actorId)` — the ONLY place review rules live (approve / reject / suspend / reinstate / remove / cancel, size cap, notifications, emails); `sdSetNetworkOpen(dealerId, open, actorId)`; `sdNetworkSummary(orgId)`; `sdNetworkRoster(orgId)` (desk, cars, leads on this business's cars, other networks); `sdBrowseBrokers(orgId, q, province, page)`; `sdBrokerProvinces()`; `sdInviteBroker(orgId, brokerId, actorId)`; `sdCanManageNetwork(ws, orgId)` (2d: owner-only in multi-dealership businesses).
- **Who-may-act checks stay with the caller:** dealer portal → `requireDealerWorkspace()` + `sdCanManageNetwork()` (+ member must belong to the dealership's org); admin → `adminNetworkScopeSql()` in `includes/admin_scope.php` (`adminReviewAgent`, `adminSetNetworkOpen` are thin wrappers).
- `sdNetworkReviewers()` (managing admins, else superadmins) and `sdNetworkPrincipals()` (principal, or the owner of a multi-dealership business) receive application notices; principals' email links to `/app/dealer/network`.
- Audit actions: `org.agent_applied|approved|rejected|suspended|reinstated|removed|withdrew|left|cleared`, `org.network_opened|closed`, `org.broker_invited`, `org.invite_accepted|declined|cancelled`.

## Billing module — `includes/billing.php` (Phase 3)
- **Settings:** `sdPayfastLoadConfig()`, `sdPayfastConfigured()`, `sdPayfastSandbox()`, `sdPayfastBaseUrl()`; readiness `sdBillingReady()` (0022 tables), `sdBillingAvailable()` (ready + configured); `sdBillingUrls()` (notify / return / cancel).
- **Signatures:** `sdPayfastFormSignature()` (documented field order `SD_PAYFAST_FORM_FIELDS`, empty values skipped, trim + urlencode, `&passphrase=` last, MD5 — verified against an independent implementation); `sdPayfastItnParamString()` (posted order up to `signature`); `sdPayfastApiSignature()` (headers + body, ksort, passphrase included).
- **Flow:** `sdBillingStartCheckout()` → `sdBillingCheckoutForm()` (owner, unused, < 2 h old) → `sdBillingProcessItn()` → `sdBillingActivate()` / `sdBillingRenew()` / `sdBillingMarkFailed()` / `sdBillingMarkCancelled()`; `sdBillingCancel()` via `sdPayfastApi()`; `sdPayfastUpdateCardUrl()` (live only); `sdBillingLapse()` (cron). Activation and lapse re-check the downgrade grace (3b).
- **Reporting:** `sdBillingRecentEvents()`, `sdBillingSummary()` (paying online, online MRR, received 30 days, cancelling, past due) on Admin → Plans → Billing.
- **Pages:** `app/broker/plan.php` (Upgrade / Cancel / Update card / payment states), `app/billing/pay.php`, `api/billing/payfast-notify.php`, `cron/billing-daily.php` (CLI only), `app/admin/plans.php` (Billing section), `app/admin/users-view.php` (PayFast status + cancel).
- Audit: `billing.checkout_started`, `plan.changed` (activation and lapse; automatic entries show "SalesDesk (automatic)"), `plan.cancelled`. 3b adds `plan.grace_started`, `plan.cars_paused`, `plan.listings_paused`, `plan.listings_resumed`.
- **Test harness only:** `PAYFAST_TEST_SKIP_REMOTE` (honoured in sandbox mode only) skips the PayFast source and server-confirmation network calls. Never set it on the server.

## Migration of existing data (Phase 2)
1. **Backfill — done (0020):** one org per dealership (name = company_name, owner = principal or NULL), `dealers.organization_id` set.
2. **Existing orgs — done (0020): both production orgs deleted.** FKs checked on a copy of the schema:
   - `leads`, `commissions`, `payouts`, `bank_accounts`: `organization_id` is `ON DELETE SET NULL`. Rows stay; they're just untagged.
   - `organization_members`, `organization_managers`: `ON DELETE CASCADE`.
   - Their brokers become independent.
   - **Bank accounts:** 0020 sets org bank accounts to non-primary, and payouts now always use the broker's own primary account. A broker whose only saved account was the org's must add their own on Earnings (it prompts them).
3. **Screens:**
   - **Admin — done (2a–2d):** `app/admin/desk-orgs*` retired (redirect to Dealerships). **Dealerships → dealership → Business**: business account, **Dealerships** (2d: add / move out / name / owner), **Broker network**, **Business plan**. Approvals tab **Network brokers**.
   - **Broker side — done (2b/2c):** **My networks** at `/app/broker/networks` (invitations with Accept / Decline). Old org routes redirect there.
   - **Dealer portal — done (2c/2d):** **Network** at `/app/dealer/network` (Brokers tab + Find brokers tab); dashboard notice for waiting applications; **switcher bar** for business owners.
   - **Users pages — done (2b):** one row per broker with "Network · name +N"; the user page lists every network.
4. **SEO:** with several networks, the first one joined names the desk (2b). Org hubs stay (now for business networks) and 404 until they have a verified broker with an active desk. Merging a business removes its hub (2d).
5. **Public copy — done (27 Nov 2026, pricing page):** how-it-works (brokers, dealers), the legacy broker landing and the desk directory no longer say "no subscription" / "no monthly fee". Their cost lines and FAQs read the live plans and link to /pricing. Still to decide: a launch offer for existing brokers.

## Phases
1. **Plan model + entitlements, everyone on Free — BUILT (26 Nov 2026), migration `0019_plans.sql`.**
   - **Free 150 replaces the old 10-car default.** Custom car limits above 150 carry over as custom limits; lower ones are superseded.
   - **Admin → Plans** (superadmin): MRR, accounts per plan, edit prices and limits, recent changes.
   - **Users → broker → Edit → Plan & limits:** Free/Pro, Paid or Comped, optional end date (then back to Free), note, custom limits with a reason. The users list has a "Plan · cars" column.
   - **Broker → Plan:** usage meters, Free vs Pro, and "Request Pro" (kept as the fallback when PayFast isn't configured).
   - **Desk limit enforced in Marketplace "Add to desk".** The Dashboard uses the same limit.
   - **Also:** `layout-app.php` CSS/JS now use `sdAsset()` content-hash URLs.
2. **Business = org restructure — BUILT (26 Nov 2026), in slices:**
   - **2a Foundation — migration `0020_business_orgs.sql` + `includes/business.php`:** old desk orgs deleted; one business org per dealership, kept in step; agent mode retired; new lead attribution; payouts to the broker's own account; admin Business tab; Desk orgs screens retired.
   - **2b Broker side — no migration:** My networks; signup network checkboxes; Business tab Broker network card; Approvals → Network brokers; fixed business orgs in Verifications.
   - **2c Business side — migration `0021_network_invites.sql`:** dealer portal Network page (review, members, invitations, Find brokers + Invite for Pro+); broker invitations; shared `sdNetworkReview`.
   - **2d Enterprise — no migration:** Dealerships card (add / merge / move out / name / owner); group mode + switcher; owner-only network management; dealership limit guard.
3. **Billing:**
   - **3a Brokers pay online with PayFast — BUILT (26 Nov 2026), migration `0022_billing.sql` + `includes/billing.php`:** Upgrade → PayFast monthly subscription; ITN with four checks + log; renewals, failed payments (past due), cancellations (API + ITN); daily lapse cron; Admin → Plans → Billing; admin cancel + manual-change guard. Tested with simulated ITNs (rejections, activation, duplicate, renewal, failure, recovery, cancellation, lapse, late renewal restore). **A real sandbox round trip is still to be done on the server** before going live.
   - **3b Downgrade grace — BUILT (27 Nov 2026), migration `0023_plan_grace.sql` + `includes/plan_grace.php`:** brokers over the car limit get a 7-day window and choose which cars stay live; the rest are parked (never deleted) and restored on upgrade. A lapsed Enterprise's extra dealerships stop adding new cars after 7 days. Tested on MariaDB + Chrome: window, choose, enforce (page and real cron), storefront hides parked cars, resume / re-add keep the link, upgrade restores, a Pro lapse caught in the same run, Enterprise lapse → pause → upload / import guard → move out lifts it; 31-page sweep in three states.
   - **3c Hybrid business billing — BUILT (27 Nov 2026), migration `0026_business_billing.sql` + `includes/invoicing.php`:** Business Pro pays online (PayFast) from the dealer portal Plan page; Enterprise is invoiced monthly by EFT (start / mark paid / void / resend / stop on the Business tab, automatic monthly invoices, overdue reminders, lapse after 7 days' grace, printable invoices). Tested with simulated ITNs and a full invoice cycle; 26-page sweep in five states. **A real sandbox Business Pro payment is still to be done on the server.**
4. **VIP perks + public copy + launch:**
   - **4a Network-only stock + network bonus — BUILT (27 Nov 2026), migration `0024_vip_network.sql` + `includes/vip_network.php`.** Tested on MariaDB + Chrome: plan gating, marking → pause / restore on non-member desks, membership changes, public visibility (grid, search, sitemaps, redirects, 404), bonus end to end (perks tab → locked on lead → close → commission R8,000 + R1,749.50), downgrade / upgrade, room-limited restore, daily repair; 48-page sweep with perks off / on and before 0024.
   - **4b Network badge + business dashboard + announcements — BUILT (27 Nov 2026), migration `0025_network_dashboard.sql` + `includes/network_dashboard.php`.** Tested on MariaDB + Chrome: dashboard numbers per period + CSV, announcements (validation, daily limit incl. after removal, notifications, My networks, sister principal read-only), badges on desk and hub, Free fallback, admin list; 54-page sweep with VIP on, Free, and before 0025. All four confirmed VIP perks are now built.
   - **Pricing page — BUILT (27 Nov 2026), no migration: `pricing.php` (/pricing) + `includes/pricing.php`.**
     - **Fully dynamic.** Plan names, taglines, prices, limits and "Offered" all come from Admin → Plans on every request (`Cache-Control: private, no-cache`). An unticked plan disappears from the page.
     - **Driven by limit keys.** Each key in `SD_LIMIT_KEYS` becomes a feature line and a comparison row.
     - **Highlighted plan:** "Most popular" goes to the cheapest plan sold online. Invoiced plans get "Talk to us" (mailto `platform_config.sales_contact_email`, default sales@salesdesk.co.za).
     - **Buttons follow the visitor:**
       - guest → sign up;
       - broker → `/app/broker/plan`, with a "Your plan" tag;
       - dealer → `/app/dealer/plan`.
     - **Content:** Brokers / Dealerships switch (`?for=dealerships`, any anchor such as `#vip-perks`; both show without JS), every-plan-includes, VIP perks block, platform fee band, FAQ with live values (fee, grace days, invoice due days).
     - **JSON-LD:** a Service per audience with an Offer per plan, plus FAQPage.
     - **Links:** in the Sell & earn menu, the account panel, the mobile drawer, the footer and the sitemap.
     - **Reserved URLs:** `SD_RESERVED_DESK_SLUGS` / `sdIsReservedDeskSlug()` in `includes/functions.php` is now the single list used by the slug check, desk rename and sign-up. Before this, sign-up didn't check at all: a desk named "Help" would take /help.
     - **Tested:** admin edits (price, name, tagline, a limit, untick Enterprise) show on /pricing, in its JSON-LD and on how-it-works at once; guest / broker / dealer states; no-JS; 390 / 820 / 1280 px; fallback before 0019.

## Also shipped (26 Nov 2026)
- Marketplace "Add to Desk" works in place: no reload, Undo, live slot counter, "Desk full" flip. Without JS it returns to the same Marketplace page (`assets/js/broker-inventory.js`, `assets/css/broker-inventory.css`).

## Open items
- Plan taglines are free text: after changing a limit in Admin → Plans, update any tagline that quotes the number (e.g. Free "up to 150 cars").
- Check no live desk already uses a newly reserved slug (`pricing`, `plans`, `billing`, `salesdesk`, …): `SELECT slug FROM salesdesks WHERE slug IN (…)`. Existing desks keep working; only new ones are blocked.
- Run the PayFast sandbox round trip on the server (setup steps in FILEPATHS-billing-payfast.md), then switch to live.
- Broker over the **network** limit: pick memberships to keep (today: can't apply / accept until under it).
- Check the `salesdesk_bank_*` platform config values are the real banking details before invoicing anyone (invoices and commission invoices use them).
- Run a sandbox Business Pro payment on the server alongside the broker one.
- Check the live DB has `leads.attribution_type` (written by `api/leads/submit.php`; no migration in the repo creates it — the test DB lacked it).
- Drop the unused `organizations.brands` and `agent_car_limit` columns.
- Optional: let brokers pick which network names their desk (today: first joined).
- Optional: email invitations for people not yet on SalesDesk (token sign-up into a network).
- Optional: a group overview for business owners (combined stock / leads across dealerships); today they switch dealership by dealership.
