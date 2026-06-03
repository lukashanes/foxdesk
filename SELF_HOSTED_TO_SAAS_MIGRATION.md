# Self-hosted FoxDesk to FoxDesk Cloud migration

This guide is for the public/self-hosted PHP FoxDesk application. The preferred
path is an API migration bridge: sync the self-hosted app to one SaaS workspace,
run a final delta sync, then cut over so only the SaaS instance remains active.

The ZIP export is still available as an offline fallback.

## API sync path

1. Update the self-hosted FoxDesk to version `0.3.129` or newer.
2. In the FoxDesk SaaS Platform console, open the target workspace and create a
   **Migration bridge** token.
3. Log in to the self-hosted FoxDesk as an admin.
4. Open:

   `index.php?page=admin&section=migration-export`

5. Enter the SaaS URL and migration token.
6. Click **Connect**, then **Analyze sync**.
7. Review the migration plan and expected rows/files.
8. Run data and attachment sync:

   `php bin/sync-to-cloud.php --cloud-url=https://app.foxdesk.net --token=fdmig_...`

9. Verify users, tickets, time entries, comments, email messages, and attachments
   in the SaaS workspace.
10. Click **Final cutover** with the SaaS workspace URL.

After cutover, the self-hosted app redirects users to SaaS and disables local
IMAP/email notification processing. This prevents two active helpdesk instances
from processing the same customers and emails.

Sync includes attachments through a streaming API upload. API tokens and global
email/settings secrets are not activated during migration; API tokens are rotated
and email credentials are re-entered in SaaS.

## Fallback package path

Use this only when API sync is not available.

1. Open:

   `index.php?page=admin&section=migration-export`

2. Click **Download migration package**.

The downloaded ZIP contains:

- users and password hashes
- organizations/clients
- statuses, priorities, and ticket types
- tickets, comments, time entries, and reports
- notifications and activity metadata
- settings and email templates
- attachment files
- a `manifest.json` with source version, package format, counts, and file map

## SaaS import

1. Log in to the FoxDesk SaaS platform admin.
2. Open the Platform console.
3. Open the target workspace detail.
4. Prefer **Migration bridge** for API sync, or use ZIP import only as fallback.
5. Verify users, tickets, clients, reports, and attachments before final cutover.

## Cutover rule

Do not switch production traffic until the SaaS workspace has been tested. The
final self-hosted action is **Final cutover**, which disables the old instance as
an active source. Keep the old files and database as rollback backup, not as a
second running helpdesk.
