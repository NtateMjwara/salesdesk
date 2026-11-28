# SalesDesk docs — index

Start with `/CLAUDE.md` (stack, conventions, rules). Then read what the task needs.

| Doc | Read it when |
|---|---|
| `product-overview.md` | You need the business model, the roles, or the glossary (desk, network, principal, VIP…) |
| `architecture.md` | You're adding a page, a module, a migration, or touching routing |
| `decision-log.md` | You want the history and the reason behind a rule, or what's still undecided |
| `open-items.md` | Before going live, or when picking up loose ends |
| `crm/brief.md` | Working on the CRM (the next project) |
| `sa-locator.md` | Editing the South African location dataset (`sa-locator.js`) |

## Decision docs (`decisions/`)

These are the specs agreed with Sfiso in planning sessions. Each one records what's decided, what's built (with
migration numbers) and what's still open.

| Doc | Covers |
|---|---|
| `decisions/plans-and-billing.md` | Plans, limits, entitlements, business orgs and networks, PayFast, invoicing, grace, VIP perks, pricing page |
| `decisions/dealer-workspace.md` | How admins work inside dealerships: principal / operator / delegate / viewer, money controls, delegation, unplaced execs |
| `decisions/superadmin.md` | Superadmin vs admin powers, Users page, guardrails |
| `decisions/desk-org-seo.md` | Attribution, desk/org titles, directory and hubs, canonical and noindex rules |
| `decisions/public-ux-system.md` | Public site CSS layers, components, partials, JS hooks |

**Keeping them current:** when a feature ships, update its decision doc (status, migration, open items) in the same
branch. Note that `desk-org-seo.md` and the older parts of `superadmin.md` predate the November business-org model.
Where they conflict, `plans-and-billing.md` wins (e.g. "desk organisations" are now business networks, and the 3-brand
rule is gone).
