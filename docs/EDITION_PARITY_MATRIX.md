# FoxDesk Edition Parity Matrix

This self-hosted repository follows the same shared helpdesk concepts as
FoxDesk SaaS, but it is not the hosted platform repository.

## Classification Rules

- `shared`: helpdesk workflow that should behave the same in SaaS and
  self-hosted: Work, Inbox, Tickets, Clients, Reports, Search, Notifications,
  and Email rendering.
- `saas`: hosted-only platform, billing, tenants, Stripe, Cloudflare Email, R2,
  and operator console behavior. These must not ship as self-hosted app screens.
- `self-hosted`: local install/update, IMAP fallback, migration source, and final
  cutover behavior.
- `legacy`: compatibility surfaces that should not receive new product
  functionality.

## Product And Flow Matrix

| Area | Owner | Required parity |
| --- | --- | --- |
| Work | shared | Same queue keys: `mine`, `unassigned`, `overdue`, `waiting`, `done_today`. |
| Inbox | shared | Same triage keys: `triage`, `customer_replies`, `email_imports`. |
| Tickets | shared | Same registry views: `open`, `waiting`, `done`, `all`, `archived`. |
| Ticket detail | shared | Same action model: Reply, Start work, Assign, Complete/Edit when allowed. |
| New ticket | shared | No random client fallback. Empty stays empty unless selected or deterministically inferred. |
| Clients | shared | Same client center model: profile, contacts, work history, time, billing rates. |
| Reports | shared | Same billing review model: client + period, editable line items, rates, discounts, live totals, share/export. |
| Search | shared | Done/closed tickets must remain discoverable through global search and `all` views. |
| Notifications | shared | One user action creates at most one meaningful email. |
| Email rendering | shared | Preserve readable paragraphs, lists, links, and strip quoted history/signatures. |
| Team/users | shared | Same staff/client roles and permission concepts. |
| Settings | shared + self-hosted overlays | Shared workflow/security/profile settings plus local update and IMAP diagnostics. |
| Storage | self-hosted overlay | Local disk attachments with the same permission checks as SaaS. |
| Inbound email | self-hosted overlay | IMAP plus pseudo-cron/CLI fallback. |
| Installer | self-hosted | Local/shared-hosting installation and config bootstrap. |
| Public updater | self-hosted | ZIP update channel for the free PHP app. |
| Migration source | self-hosted | API sync client, attachment sync, ZIP fallback, and final cutover controls. |
| Billing | saas | Not part of the self-hosted app flow. |
| Platform console | saas | Not part of the self-hosted app flow. |
| Public SaaS web | saas | Not part of the self-hosted app flow. |
| Legacy dashboard | legacy | May remain as analytics/compatibility view; Work is the daily workflow. |

## Route Ownership

| Route/surface | Owner | Notes |
| --- | --- | --- |
| `pages/work.php` | shared | Must use shared work queue modules. |
| `pages/inbox.php` | shared | Must use shared inbox modules. |
| `pages/tickets.php` | shared | Must use shared ticket list view/status group modules. |
| `pages/ticket-detail.php` | shared | Must keep action semantics aligned across editions. |
| `pages/client.php` and client admin surfaces | shared | Must use client overview/rate concepts consistently. |
| `pages/admin/reports.php` | shared | Must keep item-level billing review parity. |
| `pages/admin/settings.php` | shared + self-hosted overlays | Local updater, backups, and IMAP diagnostics live here. |
| `pages/admin/migration-export.php` | self-hosted | Migration source and ZIP fallback only. |
| `install.php` and `upgrade.php` | self-hosted | Local install/update tools. |

## Self-Hosted Exclusions

The public self-hosted app must not expose these SaaS-only surfaces:

- `pages/platform.php`
- `pages/billing.php`
- `pages/cloud.php`
- `pages/signup.php`
- `pages/stripe-webhook.php`
- platform tenant lifecycle controls
- Stripe customer/subscription administration
- hosted R2 or Cloudflare production secrets
- internal SaaS pricing operations
