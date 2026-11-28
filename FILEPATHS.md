# Claude Code handover pack: file paths and setup

Unzip into the **root of the `salesdesk` repo** (the folder that holds `.htaccess`, `includes/`, `app/`). Nothing
here changes code. It's documentation only, so nothing needs uploading to the server.

| File in this zip | Goes to (repo path) | What it is |
|---|---|---|
| `CLAUDE.md` | `/CLAUDE.md` | Loaded automatically by Claude Code in every session: stack, conventions, rules, current state |
| `docs/README.md` | `/docs/README.md` | Index of all docs |
| `docs/product-overview.md` | `/docs/product-overview.md` | Business model, roles, glossary, plans, positioning |
| `docs/architecture.md` | `/docs/architecture.md` | Routing, page pattern, includes modules, tables, migrations, legacy notes |
| `docs/decision-log.md` | `/docs/decision-log.md` | Decisions from chat sessions not covered elsewhere, plus what's undecided |
| `docs/open-items.md` | `/docs/open-items.md` | Every open item in one checklist (security first) |
| `docs/crm/brief.md` | `/docs/crm/brief.md` | Starting brief for the CRM project |
| `docs/sa-locator.md` | `/docs/sa-locator.md` | Notes on the SA location dataset |
| `docs/decisions/plans-and-billing.md` | `/docs/decisions/plans-and-billing.md` | From the claude.ai Project |
| `docs/decisions/dealer-workspace.md` | `/docs/decisions/dealer-workspace.md` | From the claude.ai Project (adds a "group" mode line) |
| `docs/decisions/superadmin.md` | `/docs/decisions/superadmin.md` | From the claude.ai Project (email removed) |
| `docs/decisions/desk-org-seo.md` | `/docs/decisions/desk-org-seo.md` | From the claude.ai Project (adds a note on business networks) |
| `docs/decisions/public-ux-system.md` | `/docs/decisions/public-ux-system.md` | From the claude.ai Project (scope updated) |

## Setup steps

1. **Rotate the leaked credentials first.** `includes/config1.php` in the repo holds what look like the real database
   and SMTP passwords. Change both on your host, delete the file, and remove it from git history
   (Claude Code can do the history clean-up with you).
2. **Make GitHub match production.** If the server has files newer than `main`, commit them first, so Claude Code
   starts from what's actually live.
3. **Unzip this pack into the repo root and commit** (e.g. "Add Claude Code docs").
4. **Install Claude Code and open the repo** (`cd salesdesk && claude`). It reads `CLAUDE.md` on its own.
5. **Set up a local copy:** PHP 8.2, Apache with mod_rewrite, MariaDB, and an export of the production database
   with personal data scrubbed, or a fresh install from `db/`. Claude Code can script this for you. It should never connect to production.

## Suggested first prompt in Claude Code

> Read CLAUDE.md and everything in docs/. Then: (1) help me remove includes/config1.php and purge it from git history,
> (2) set up a local dev environment (Apache + PHP 8.2 + MariaDB) and update the README setup section,
> (3) work through the Security and Database hygiene sections of docs/open-items.md, including leads.attribution_type.
> After that, we'll spec the CRM from docs/crm/brief.md. Ask me the questions in it before writing the spec.
