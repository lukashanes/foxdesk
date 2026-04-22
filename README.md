# FoxDesk

Open-source helpdesk and ticketing system built with PHP, Tailwind CSS, and Alpine.js.

**Website:** [foxdesk.org](https://foxdesk.org)
**Current Version:** `0.3.107` (`2026-04-22`)

---

## Features

**Ticket Management**
- Create, assign, comment, resolve, and archive tickets
- Custom statuses, priorities, and ticket types
- Tags, due dates, and organization assignment
- Internal notes (agent-only) and public comments
- Public ticket sharing via secure links with expiration
- Inline ticket-list editing for subject, type, status, priority, due date, company, and assignee
- Compact Quick Add row for creating tickets directly from the list
- Bulk actions (status, priority, assignment)
- Advanced filtering and full-text search
- Edit history tracking on all fields

**Time Tracking**
- Built-in timers with start, pause, resume, stop
- Quick Start mode for instant timer launch
- Sidebar timer widget with global visibility
- Manual time entry with descriptions
- Billable vs. non-billable hours
- Configurable rounding (0/15/30/60 min)
- Cost rates per user, billing rates per organization
- Time reports with PDF and CSV export

**Notifications**
- In-app notification center with full-page view
- Notifications grouped by ticket
- Header dropdown with real-time badge count
- Dashboard notification widget
- Email notifications for ticket events
- Browser push notifications with one-click opt-in
- Per-user notification preferences (email, in-app, sound)

**Reporting & Analytics**
- Report builder with date ranges and filters
- Financial reports (billable, cost, profit)
- Public shareable report links with expiration
- Dashboard KPI cards and activity feed
- User activity tracking (admin)

**AI Agent Integration**
- REST API for AI automation
- Agent Connect page with system prompt generator
- Bearer token authentication with usage tracking
- Endpoints for ticket creation, lookup, status updates, comments, time logs, and metadata lists

**Email Integration**
- IMAP email-to-ticket ingest with sender whitelist
- Inbound email attachments linked directly to ticket threads
- SMTP notifications with customizable templates
- CC/BCC recipients on ticket replies

**User Roles**
- **Admin** — Full access, settings, user management, reports, AI agents
- **Agent** — Ticket handling, time tracking, internal notes
- **Client** — Submit and view own tickets, reply

**Organizations & Clients**
- Organization management with contact info and billing rates
- Client portal with limited ticket access
- Multi-organization user assignment

**Recurring Tasks**
- Scheduled ticket creation (weekly, monthly, yearly, custom)
- Configurable assignee, type, priority, organization

**PWA Support**
- Installable as desktop and mobile app
- Dynamic app manifest with custom icons

**Multi-language**
- English, Czech, German, Spanish, Italian
- Per-user language preference

**Auto-Updates**
- One-click update from admin panel
- Automatic backup before each update
- Manual ZIP upload for offline environments
- Dual-source checking (foxdesk.org + GitHub)

**Security & Ops**
- TOTP 2FA with backup codes and optional per-role enforcement
- Attachment downloads respect ticket access and public share permissions
- Database-backed sessions survive app or container restarts
- Public health check endpoint for uptime monitoring
- Post-update health checks and automatic maintenance recovery

**More**
- Dark mode with CSS variable theming
- Responsive design (mobile, tablet, desktop)
- Keyboard shortcuts
- Pseudo-cron (works without system cron)
- Remember-me persistent login
- Allowed senders management for email ingest

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP         | 8.1     |
| MySQL       | 5.7+ / MariaDB 10.2+ |
| Disk space  | 50 MB   |

Required PHP extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`

---

## Quick Start

1. Upload files to your web server
2. Create a MySQL database
3. Copy `config.example.php` to `config.php` and edit credentials
4. Open `https://your-domain.tld/install.php`
5. Follow the installer (database setup + admin account)
6. Delete `install.php`
7. Log in and start using FoxDesk

See [INSTALL.md](INSTALL.md) for detailed instructions including shared hosting, VPS, Nginx, cron jobs, and email setup.

---

## Tech Stack

- **Backend:** PHP 8.1+ (no framework)
- **Database:** MySQL / MariaDB
- **Frontend:** Tailwind CSS, Alpine.js
- **Styling:** Custom `theme.css` with dark mode support

---

## Project Structure

```
index.php              Entry point and router
config.example.php     Configuration template
install.php            Web installer
upgrade.php            Database migration tool
rescue.php             Emergency recovery
theme.css              Custom styles + dark mode
tailwind.min.css       Tailwind CSS
version.json           Version info for auto-updates
assets/js/             JavaScript modules
includes/              Core PHP (auth, DB, functions, API, languages)
includes/api/          REST API handlers
includes/components/   Reusable UI components
includes/lang/         Translation files
pages/                 Page controllers
pages/admin/           Admin panel pages
bin/                   CLI scripts (cron, email ingest, maintenance)
```

---

## Cron Jobs

```bash
# Email ingest (every 5 min, if IMAP enabled)
*/5 * * * * php /path/to/bin/ingest-emails.php

# Recurring tasks (hourly)
0 * * * * php /path/to/bin/process-recurring-tasks.php

# Maintenance (daily)
0 3 * * * php /path/to/bin/run-maintenance.php
```

FoxDesk also includes a **pseudo-cron** system that runs tasks on page load, so cron jobs are optional on shared hosting.

---

## API

FoxDesk includes a REST API for automation and AI agent integrations.

**Endpoints:**
- `GET agent-me` — Current token identity
- `GET agent-list-statuses` — List ticket statuses
- `GET agent-list-priorities` — List priority levels
- `GET agent-list-users` — List users / agents
- `POST agent-create-ticket` — Create tickets
- `GET agent-get-ticket` — Fetch full ticket detail
- `POST agent-update-status` — Change ticket status
- `POST agent-add-comment` — Add comments
- `POST agent-log-time` — Log time entries
- `GET agent-list-tickets` — List and filter tickets

Authentication via Bearer token (generated in Admin > Users > AI Agents).

The **Agent Connect** page provides a ready-to-use system prompt and API documentation for connecting AI models.

---

## License

GNU Affero General Public License v3 (AGPL-3.0). See [LICENSE.md](LICENSE.md).

Created by [Lukas Hanes](https://lukashanes.com).
