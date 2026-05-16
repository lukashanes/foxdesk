# Self-hosted FoxDesk to FoxDesk Cloud migration

This guide is for the public/self-hosted PHP FoxDesk application. It creates an
export package that can be imported by the separate FoxDesk SaaS platform admin.

## Source FoxDesk

1. Update the self-hosted FoxDesk to version `0.3.115` or newer.
2. Log in as an admin.
3. Open:

   `index.php?page=admin&section=migration-export`

4. Click **Download migration package**.

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
3. Use **Import self-hosted FoxDesk**.
4. Upload the migration ZIP.
5. Choose the hosted workspace name and billing/lifecycle state.
6. Verify users, tickets, clients, reports, and attachments before switching DNS.

## Cutover rule

Do not move a production domain until the imported workspace has been tested.
Keep the old self-hosted installation available as rollback during the first
days after cutover.
