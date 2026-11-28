# SalesDesk — decision log

Decisions Sfiso made in planning sessions (Sep–Nov 2026) that aren't already spelled out in `decisions/`. Newest
decisions win. Undecided items are marked **(undecided)**: ask before building on them.

## Front end and URLs
- Systematic front-end UX/UI cleanup. All CSS and JS in external files, using shared resources as much as possible.
- Cleanup order: layout-public header nav → homepage → cars-for-sale index → car detail → the rest.
- `browse-additions.css` was meant to be merged into `browse.css`, and `mobile-hero-nav-fix.css` into another
  stylesheet. Check they're merged and gone.
- Extensionless URLs site-wide. Legacy `/c/` URLs 301 to `/cars-for-sale/`.

## Lean MVP (Oct 2026)
- Working toward a market-ready MVP focused only on operations.
- Removed entirely (pages, admin screens, code): News & Reviews, Our Outreach, Our Story, Careers, Help Center,
  Contact Us, newsletter. **Database tables kept, not dropped.**
- Broker-facing copy rewritten. Positioning: the desk is the broker's showroom, not "sharing links".

## Admins and superadmin
- Admins can create dealerships with no principal (waiting for principals was costing them), and approve or decline
  sales exec applicants. Each admin is scoped to what they manage.
- Superadmins can deactivate users (Suspend / Reactivate), and import or refresh inventory on principal-owned
  dealerships even while the principal is active (dealers don't always refresh stock).
- Full user deletion: **(undecided)**. The proposal is permanent delete for accounts with no history, and erasing
  personal data for the rest.
- Settled by the dealer workspace model: admins are read-only on principal-owned dealerships unless the principal
  delegates. They operate fully only on no-principal dealerships.

## Organisations, networks and attribution
- September's "desk organisation" model (admin-run online dealerships with a 3-brand rule) was **replaced** in November by
  the business-org model: the org is the business account, brokers never create orgs, open marketplace with VIP networks.
  The two production orgs were deleted (0020).
- Organic search: whichever desk (or the platform) a Google result lands the buyer on gets the lead. Marketing a desk
  is the broker's own job.
- Last-click attribution kept. Buyer stickiness across desks is deferred.

## Plans and billing (Nov 2026)
- Full model in `decisions/plans-and-billing.md`. Key points: two paying account types (Broker, Business);
  broker Free/Pro only (Enterprise dropped); Business Free/Pro/Enterprise; PayFast for online payment; Business Pro pays
  online and Enterprise is invoiced (EFT); VIP perks shipped one by one; prices exclude VAT.
- Launch offer for existing brokers: **(undecided)**.

## Leads and buyer data
- Hiding some buyer information from brokers on leads: **(undecided)**. Reasoning: enquiries also go directly to the
  dealership, and brokers don't have to engage the buyer. This directly affects the CRM design.

## Content admin
- Whether content pages stay open to all admins: **(undecided)**. Most content features were removed in October,
  so this may be moot.
