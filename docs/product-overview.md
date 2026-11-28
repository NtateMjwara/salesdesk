# SalesDesk — product overview

## What it is

A commission-based car sales distribution platform for the South African market. Dealerships get a sales channel
of independent brokers who are paid only on closed deals. Brokers get stock to sell without holding any.

## How money moves

1. A dealership lists a car (manual upload wizard, CSV, or one of three stock importers, e.g. Motus).
2. A broker adds the car to their desk from the **Marketplace** (open marketplace: any broker can list any dealer's car,
   up to their plan's desk limit).
3. A buyer finds the car on the broker's desk (`/{desk-slug}/` or `/cars-for-sale/{desk-slug}/{car-slug}/`) and
   enquires. The lead is attributed to that desk and locked (last click wins).
4. The dealership works the lead: new → contacted → test_drive → negotiation → closed / lost.
5. On close, a **commission** row is created and a commission invoice is emailed to the dealership. If SalesDesk staff
   closed the deal on a principal-owned dealership, the principal must confirm it first (or dispute it).
6. The commission goes through payout approval (four-eyes rule) and is paid to the **broker's own bank account**.
   SalesDesk keeps a platform fee, the same on every plan. A VIP network bonus may be added on top.

## Roles

| Role | Who | Portal |
|---|---|---|
| **Broker** | Independent seller. Owns one desk (their showroom). | `/app/broker/` |
| **Dealer (principal)** | Owner/manager of a dealership. One principal per dealership. | `/app/dealer/` |
| **Sales exec** | Works at a dealership. Applies or is invited, and is approved by it. No plan. | `/app/exec/` |
| **Admin** | SalesDesk staff, scoped to the dealerships they manage. | `/app/admin/` |
| **Superadmin** | Admin with `users.is_superadmin`. Platform-wide: admins, payouts, plans, audit, database. | `/app/admin/` |
| **Buyer** | Public visitor. No account. Submits enquiries (leads). | public site |

## Glossary

- **Desk / SalesDesk:** a broker's public showroom, `/{slug}/`. Desks still named "My SalesDesk" are noindexed.
- **Organisation (org) = business account:** created automatically for every dealership (or brand/group). It holds
  the plan, the broker network, settings and the owner. One org per business. SalesDesk itself has no org.
- **Network:** a business's brokers (`organization_members`, brokers only). Brokers apply or are invited. Networks
  start closed. Joining adds perks and never restricts listing.
- **VIP perks** (Business Pro/Enterprise): network-only stock, network bonus, network badge, business dashboard
  and announcements.
- **Enterprise / group mode:** a business with several dealerships. The **owner** (one of its principals) switches
  between them in the portal.
- **Admin-managed dealership:** a dealership with no principal, run by SalesDesk admins in **operator** mode. It's also
  how SalesDesk runs a network for a brand before the brand signs. Handed over to a principal later.
- **Dealer workspace modes:** principal, operator, delegate (principal granted 7 or 30 days), viewer (read-only), group.
- **Parked cars:** desk cars over a plan limit, or network-only cars on a non-member's desk. Never deleted, and restored
  when allowed.
- **Platform car page:** `/cars-for-sale/car/{slug}/`, the car without a desk. Noindexed when any live desk carries it.

## Plans (prices exclude VAT; live values are editable in Admin → Plans)

- **Broker:** Free (150 desk cars, 3 networks) · Pro R149/month via PayFast (unlimited).
- **Business:** Free (1 dealership, 5 network brokers) · Pro R3,000/month via PayFast (100 brokers, invites, VIP perks)
  · Enterprise R12,000/month, invoiced by EFT (multiple dealerships, unlimited brokers, VIP perks).

Full detail: `decisions/plans-and-billing.md`.

## Positioning (for any copy you write)

- The broker's desk is **their showroom**. Brokers generate leads by advertising their showroom and the cars on it.
  Don't describe it as "sharing links": brokers may read that as an affiliate programme.
- The public site is deliberately lean (October 2026): no news/reviews, outreach, careers, help centre, contact page or
  newsletter. The focus is operations.

## Go-to-market context

- Sfiso is pitching SalesDesk to OEMs (Suzuki South Africa, Toyota South Africa). A brand network can be run as an
  admin-managed business until the brand signs.
- OEM trademarks in business names: either partner with the brand, or name the business something close.
- Admin-managed dealerships need a signed agreement authorising SalesDesk to list stock, manage leads and confirm sales
  on their behalf, because closing a deal issues a commission invoice.
