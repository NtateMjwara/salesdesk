# Dealer workspace — design & status (Sep 2026)

## Principle
Access comes from a person's relationship to a dealership, not their rank. Superadmin means managing people and money, not owning other businesses.

## Relationships
- **Principal**: full access to their own dealership.
- **Operator**: an admin with `dealer_managers.access = 'operate'` on a dealership with **no** principal. Superadmins can operate any no-principal dealership. Gets full principal powers.
- **Viewer**: every other managing admin (the default), and **all** staff on principal-owned dealerships unless the principal has delegated. Read-only.
- **Delegate**: the dealership's managing admins and superadmins while the principal's grant is active. Everything except company, verification and address settings.
- **Group** (added Nov 2026, see plans-and-billing.md 2d): the owner of a multi-dealership business running a sister dealership.

## Phase 1 — DONE (branch `claude/dealer-workspace`, migration 0015)
- `requireDealerWorkspace()` gates every /app/dealer page and the Motus batch API. It re-checks access per request and blocks every viewer write centrally.
- Admins enter through /app/admin/workspace. The dealer portal shows a banner, Switch and Exit.
- The audit log is stamped with `_workspace {dealer_id, mode, admin_user_id}`.
- Commission invoices go to `getDealerContacts()`.
- Staff never edit the principal's personal profile.
- The car wizard is bound to its dealership.

## Phase 2 — DONE (branch `claude/money-controls`, migration 0016)
- `commissions.closed_by_user_id` and `closed_via`, backfilled from the audit log.
- Four-eyes rule: the closer can't approve, schedule, process or pay that commission, superadmins included. Enforced in payouts.php and in `transitionCommissionStatus()`.
- "Closed by SalesDesk" labels.
- Mark paid fix.
- Open question: `leads.attribution_type` is not created by any migration in the repo.

## Phase 3 — DONE (branch `claude/principal-dealerships`, migration 0017)
- `dealer_delegations`: the principal grants 7 or 30 days from Settings → SalesDesk access, and can revoke at any time. Delegates are blocked from company, verification and address settings (`requireDealerWorkspace(['principal_only' => true])`).
- The principal sees a "Changes by SalesDesk" feed (audit `_workspace` stamp) and their grant history.
- `commissions.dealer_confirmation`: a staff-closed deal on a principal-owned dealership is 'pending', with no invoice and no payout transitions.
  - Confirm: sends the invoice and notifies the closer.
  - Dispute (reason required): lead goes back to negotiation, the commission row is deleted (audited), and the closer and broker are notified.
- `cars.hold_*`: superadmin listing holds with a required reason; the dealer and exec can't resume and CSV re-import can't reactivate. Release keeps the listing paused.
- Handover (superadmin, step-up): links an existing dealer account with no dealership, or invites a new one. Sets `dealers.user_id`, sets managers to view, and sends a summary notification and email.
- UI: dealer Settings → SalesDesk access; Leads → Confirm / Dispute panel; admin dealership → Platform controls tab; delegate banner.

## Phase 4 — DONE (branch `claude/unplaced-execs`, migration 0018)
- `exec_invitations` table (exec_user_id, dealer_id, invited_by_user_id, message, status pending/accepted/declined/cancelled, expires_at 14 days, responded_at).
- "Unplaced" = active, email-verified sales_exec with no `sales_executives` row, or a rejected one. Pending applicants and verified execs are excluded.
- **Admin → Unplaced execs** (all admins can view): search and province filter, reason (skipped / declined by whom and why), and an invite form per row.
  - Invites only into dealerships the admin can write to: operator or delegate mode. Admins with none can view but not invite.
  - No duplicate open invitation per exec + dealership.
  - The sent-invitations list (superadmins see all) shows Waiting / Accepted / Declined / Withdrawn / Expired, with Withdraw by the inviter or a superadmin.
- **Exec → Invitations** (`requireRole('sales_exec')`, reachable without a dealership). The exec status screens (no record / rejected / pending) show "View invitation(s)".
  - Accept: upserts `sales_executives` as verified with `invited_by` = `verified_by` = inviter; cancels the exec's other pending invitations and replaces any pending application; notifies the inviter and the dealership contacts.
  - Decline: notifies the inviter.
  - Verified or suspended execs can't accept.
- Email `sendExecInvitation`, in-app notifications, and every step audited.

## Contract note
Admin-managed dealerships need a signed agreement authorising SalesDesk to list stock, manage leads and confirm sales on their behalf, because closing a deal issues a commission invoice.
