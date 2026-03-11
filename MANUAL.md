# FoxDesk — User Manual

> **Version 0.3.74** | Self-hosted PHP helpdesk & time-tracking platform

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Dashboard](#2-dashboard)
3. [Tickets](#3-tickets)
4. [Time Tracking](#4-time-tracking)
5. [Notifications](#5-notifications)
6. [Client Portal](#6-client-portal)
7. [Organizations & Clients](#7-organizations--clients)
8. [Reporting & Analytics](#8-reporting--analytics)
9. [Recurring Tasks](#9-recurring-tasks)
10. [AI Agent Integration](#10-ai-agent-integration)
11. [Email Integration](#11-email-integration)
12. [User Management](#12-user-management)
13. [Admin Settings](#13-admin-settings)
14. [Activity Tracking](#14-activity-tracking)
15. [Updates & Maintenance](#15-updates--maintenance)
16. [PWA & Mobile](#16-pwa--mobile)
17. [Keyboard Shortcuts](#17-keyboard-shortcuts)
18. [Security](#18-security)
19. [Troubleshooting](#19-troubleshooting)

---

## 1. Introduction

FoxDesk is a self-hosted helpdesk and ticketing system designed for small to mid-size teams. It covers the entire support workflow: ticket management, time tracking, client communication, reporting, and AI automation.

### User Roles

| Role | Access |
|------|--------|
| **Admin** | Full system access — settings, users, organizations, reports, AI agents, activity logs |
| **Agent** | Ticket management, time tracking, internal notes, reports (read) |
| **Client** | Submit tickets, view own tickets, reply to comments |

### Languages

FoxDesk supports English, Czech, German, Spanish, and Italian. Each user can set their preferred language in their profile. The admin sets the default language in Settings.

---

## 2. Dashboard

The dashboard is the main landing page after login. It provides an overview of your helpdesk activity.

### KPI Cards

Top row displays key metrics:
- **Open Tickets** — Total currently open
- **Pending** — Tickets awaiting assignment or action
- **Overdue** — Past due date
- **Completed Today** — Resolved today

### Widgets

The dashboard contains draggable widgets that can be reordered. Widget positions persist across sessions.

Available widgets:
- **Recent Activity** — Latest ticket events
- **Notifications** — Grouped notification feed with read/unread state
- **Agent Workload** — Ticket distribution per agent
- **Organization Breakdown** — Tickets by organization
- **Time Tracking Summary** — Hours logged today/this week

### Tag Filtering

Use the tag filter at the top to narrow dashboard data to specific ticket tags.

### Responsive Layout

- Desktop: 3-column grid
- Tablet: 2-column grid
- Mobile: Single column, full-width

---

## 3. Tickets

### Creating a Ticket

1. Click **New Ticket** in the sidebar
2. Fill in: subject, description, type, priority, assignee
3. Optionally set: organization, tags, due date
4. Attach files if needed
5. Click **Create Ticket**

### Ticket Detail View

The ticket detail page has two areas:

**Main Content (left)**
- Subject and description
- Comment thread (public and internal)
- Attachment grid
- Edit history

**Sidebar (right)**
- Status, priority, type, assignee
- Organization, tags, due date
- Time tracking controls
- Ticket sharing
- Quick edit dropdowns for all fields

### Comments

- **Public comments** — Visible to everyone including clients
- **Internal notes** — Visible only to agents and admins (highlighted background)
- Comments support file attachments
- Edit and delete comments with audit trail
- CC/BCC recipients on replies

### Ticket Statuses & Workflow

Statuses are fully customizable by the admin. Default statuses:
- New, Open, In Progress, Waiting, Resolved, Closed

Drag to reorder statuses in Admin > Statuses. Archive statuses you no longer use.

### Priorities

Priorities are customizable with colors. Default levels:
- Low, Medium, High, Critical

### Tags

Tags provide additional categorization. Add/remove tags from the ticket sidebar. Use tags for filtering on the ticket list and dashboard.

### Bulk Actions

On the ticket list page, select multiple tickets and apply:
- Change status
- Change priority
- Assign to agent

### Ticket Sharing

Share a ticket with anyone via a secure public link:
1. Open ticket > Sidebar > Share section
2. Click **Generate Share Link**
3. Optionally set an expiration date
4. Copy and send the link

Recipients can view the ticket (public comments only) without logging in. Revoke the link at any time.

### Advanced Filtering

The ticket list supports filtering by:
- Status, priority, type
- Assignee, organization
- Tags (multi-select)
- Due date range
- Search by subject/description (full-text)
- Show/hide archived tickets

---

## 4. Time Tracking

### Timer Controls

Each ticket has built-in timer controls in the toolbar:
- **Start** — Begin tracking time
- **Pause** — Temporarily pause (resume later)
- **Stop** — End the session and save time entry

The timer state machine: stopped → running → paused → running → stopped.

### Quick Start

Click **Quick Start** on the dashboard or sidebar to instantly start a timer on any ticket without opening it first.

### Sidebar Timer Widget

When a timer is running, a persistent widget appears in the sidebar showing:
- Current ticket name
- Elapsed time
- Pause/Stop controls

The widget polls the API every 30 seconds to stay in sync. The browser favicon changes to a green clock icon while a timer is active.

### Manual Time Entry

Add time entries without using the timer:
1. Open ticket > Time section
2. Click **Add Manual Entry**
3. Enter: date, duration (hours:minutes), description
4. Mark as billable or non-billable
5. Save

### Billable Time & Rates

- Each user has a **cost rate** (internal cost per hour)
- Each organization has a **billing rate** (charged per hour)
- Time entries can be marked billable or non-billable
- Time rounding is configurable: 0, 15, 30, or 60 minutes

### Time Entry Management

- Edit or delete time entries from the ticket detail
- Human vs. AI agent time is tracked separately
- Each entry records: user, date, duration, description, billable flag

---

## 5. Notifications

### Notification Types

FoxDesk generates notifications for these events:
- New ticket created
- New comment added
- Status changed
- Ticket assigned to you
- Priority changed
- Due date reminder

### Notification Views

**Header Dropdown** — Click the bell icon in the top bar to see recent notifications. Unread count is shown as a badge.

**Full-Page Notification Center** — Click "View All" to see all notifications grouped by date and ticket. Mark individual or all notifications as read.

**Dashboard Widget** — The dashboard shows a notification feed with grouped items per ticket. Expand to see child notifications.

### Cross-View Sync

Marking notifications as read in any view (header dropdown, dashboard widget, notification page) syncs the read state across all views in real time.

### Notification Preferences

Each user can configure in their profile:
- **Email notifications** — Receive email for each event
- **In-app notifications** — Show in the notification center
- **Sound** — Play a sound on new notifications

---

## 6. Client Portal

Clients (end users) have a simplified interface:

- **Dashboard** — List of their own tickets with status overview
- **New Ticket** — Submit a support request
- **Ticket View** — See ticket details and add public comments
- **Profile** — Update name, email, password, language

Clients cannot see:
- Internal notes
- Time tracking data
- Other users' tickets
- Admin settings or reports

---

## 7. Organizations & Clients

### Organizations

Organizations group users and tickets. Each organization can have:
- Name, email, phone, address
- Organization ID (ICO)
- Logo
- Billing rate (per hour)
- Notes
- Active/inactive status

Create and manage organizations in Admin > Organizations.

### Clients

Client accounts are users with the "Client" role:
- Linked to an organization
- Can submit and view their own tickets
- Can be activated/deactivated
- Admin can reset their password and send login credentials via email

Manage clients in Admin > Clients.

### Multi-Organization Support

A single user can be assigned to multiple organizations. Tickets can be filtered by organization across the system.

---

## 8. Reporting & Analytics

### Report Builder

Create custom reports in Admin > Reports:

1. Click **New Report**
2. Set date range and filters (organization, agents, tags)
3. Choose grouping: none, by day, by task
4. Toggle financial columns (billable, cost, profit)
5. Add executive summary
6. Customize theme color and branding
7. Save as draft or publish

### Report Types

- **Time Summary** — Total hours by user, organization, date
- **Detailed Time Entries** — Full log with descriptions
- **Weekly Timesheet** — Grid view by day of week
- **Worklog** — Inline editable time entries

### Sharing Reports

Published reports can be shared via public link:
1. Open a report > Share
2. Generate a share link with optional expiration
3. Recipients view a read-only version without login

### Export

Reports support export to:
- PDF (formatted printable document)
- CSV (spreadsheet import)

---

## 9. Recurring Tasks

Automate repetitive ticket creation with recurring tasks.

### Creating a Recurring Task

Admin > Recurring Tasks > New Task:
- **Title** — Ticket subject that will be created
- **Description** — Ticket body
- **Recurrence** — Weekly, monthly, yearly, or custom interval
- **Day of week/month** — When to create the ticket
- **Assignee** — Pre-assigned agent
- **Type, priority, status** — Defaults for the new ticket
- **Organization** — Optional assignment
- **Active** — Enable/disable without deleting

### Execution

Recurring tasks are processed by:
- System cron job (`bin/process-recurring-tasks.php`) — runs hourly
- Pseudo-cron — triggered on page load if system cron isn't available

When a task triggers, a new ticket is automatically created with the configured values.

---

## 10. AI Agent Integration

FoxDesk provides a REST API for connecting AI models and automation tools.

### Setting Up an AI Agent

1. Go to Admin > Users > AI Agents tab
2. Click **Create Agent**
3. Enter agent name and cost rate
4. Click **Generate Token**
5. Copy the token immediately (it's shown only once)

### Agent Connect

The **Agent Connect** page (Admin > Agent Connect) provides:
- A ready-to-use **system prompt** for your AI model
- Complete **API documentation** with endpoints and examples
- The base URL and authentication format

### API Endpoints

All endpoints use Bearer token authentication:
```
Authorization: Bearer your-api-token
```

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `?page=api&action=agent-create-ticket` | Create a new ticket |
| POST | `?page=api&action=agent-add-comment` | Add a comment to a ticket |
| POST | `?page=api&action=agent-log-time` | Log a time entry |
| GET | `?page=api&action=agent-list-tickets` | List and filter tickets |

### Token Management

- Tokens use prefix-based storage (the full token is never stored)
- Track last usage time per token
- Generate multiple tokens per agent
- Revoke tokens individually

---

## 11. Email Integration

### Outbound Email (SMTP)

Configure SMTP in Admin > Settings > Email to send:
- Ticket assignment notifications
- New comment notifications
- Status change alerts
- Password reset emails
- Welcome emails to new users

Test your SMTP configuration with the built-in "Test SMTP" button.

### Inbound Email (IMAP)

Convert incoming emails to tickets automatically:

1. Configure IMAP settings in `config.php`
2. Set up a cron job for `bin/ingest-emails.php` (or enable pseudo-cron)
3. Manage allowed senders in Admin > Settings

**How it works:**
- New emails from known senders create tickets
- Replies to existing tickets are appended as comments
- Attachments are saved and linked to the ticket
- Processed emails are moved to a designated folder

### Allowed Senders

Control who can create tickets via email:
- Admin > Settings > Allowed Senders
- Add email addresses or domains (e.g., `@company.com`)
- Toggle `IMAP_ALLOW_UNKNOWN_SENDERS` in config.php to allow all senders

---

## 12. User Management

### User Types

| Type | Created in | Authentication |
|------|-----------|----------------|
| Admin | Admin > Users | Email + password |
| Agent | Admin > Users | Email + password |
| Client | Admin > Clients | Email + password |
| AI Agent | Admin > Users > AI Agents | Bearer token |

### User Fields

- First name, last name
- Email address
- Role (admin, agent, client)
- Language preference
- Organization(s)
- Phone, notes
- Cost rate (for time tracking)
- Notification preferences

### Authentication Features

- **Remember Me** — Persistent login cookie for returning users
- **Password Reset** — Self-service via email token
- **Session Security** — HttpOnly, SameSite, Secure cookie flags
- **Login Throttling** — Protection against brute force

### Admin Actions

- Create/edit/archive users
- Reset passwords
- Impersonate users (with audit logging)
- View user activity and ticket history

---

## 13. Admin Settings

### General

- App name, logo, favicon
- Primary brand color
- Default language
- Currency for billing
- Time rounding interval

### Email

- SMTP configuration (host, port, encryption, credentials)
- From name and email
- Test SMTP connection

### Updates

- Check for new versions
- One-click update with automatic backup
- Manual ZIP upload option
- Update history

### Pseudo-Cron

- Enable/disable background task runner
- Runs on page load when system cron isn't available
- Processes: email ingest, recurring tasks, maintenance cleanup

### Debug & Logs

- System logs with filtering
- Security event log (auth attempts, impersonation, settings changes)
- Clear old logs

---

## 14. Activity Tracking

Admin > Activity provides visibility into user behavior.

### Overview Tab

- Total page views (today, this week, this month)
- Active user count
- Most visited pages (top 10)
- User activity breakdown: name, total views, last active, most used page

### Access Log Tab

Paginated raw log with filters:
- Filter by user
- Filter by page
- Filter by date range
- Columns: Time, User, Page, Section

### User Detail

Click any user name to see their activity:
- Recent page views
- Page breakdown (most used pages)
- Daily visit count

### Data Management

- Delete entries older than N days
- Auto-cleanup via cron (entries older than 90 days)

---

## 15. Updates & Maintenance

### Auto-Update System

FoxDesk checks for updates from foxdesk.org (primary) and GitHub releases (fallback).

**Update process:**
1. Admin sees update notification banner
2. Click **Update Available** in Admin > Settings
3. Review changelog
4. Click **Update Now**
5. FoxDesk creates a backup, downloads the update, applies it
6. Verify the update in the dashboard

### Manual Update

If auto-update fails or your server can't reach the internet:
1. Download the update ZIP from foxdesk.org
2. Go to Admin > Settings > Updates
3. Upload the ZIP manually
4. FoxDesk applies the update

### Backup & Recovery

- Automatic backup before every update
- Download backups from Admin > Settings
- Use `rescue.php` to disable maintenance mode if an update fails
- Use `upgrade.php` to run database migrations manually

### Maintenance Mode

During updates, FoxDesk enables maintenance mode. Only admins can access the system. Maintenance mode auto-expires after 10 minutes as a safety net.

---

## 16. PWA & Mobile

FoxDesk is a Progressive Web App (PWA) and can be installed on desktop and mobile devices.

### Installing

- **Chrome/Edge**: Click the install icon in the address bar
- **Safari (iOS)**: Share > Add to Home Screen
- **Android**: Browser menu > Add to Home Screen

Once installed, FoxDesk opens as a standalone app without browser UI.

### Mobile Experience

The responsive layout adapts to mobile screens:
- Single-column layout
- Touch-friendly controls
- Full functionality (tickets, time tracking, notifications)

---

## 17. Keyboard Shortcuts

FoxDesk includes keyboard shortcuts for common actions. View the full shortcut list by pressing `?` or clicking the keyboard icon in the sidebar.

---

## 18. Security

### Built-in Protections

- **CSRF tokens** on all forms
- **Password hashing** with bcrypt
- **Session hardening** (HttpOnly, SameSite, Secure flags)
- **SQL injection prevention** (parameterized queries)
- **XSS prevention** (HTML escaping)
- **File upload validation** (type and size checks)

### Audit Logging

FoxDesk logs security events:
- Login attempts (success and failure)
- Admin impersonation
- Settings changes
- Token generation and revocation

### Recommendations

- Always use HTTPS
- Delete `install.php` after setup
- Use strong passwords for all accounts
- Keep FoxDesk updated
- Review activity logs periodically

---

## 19. Troubleshooting

### Common Issues

**Blank page or 500 error**
- Check PHP error log
- Verify `config.php` exists with correct database credentials
- Ensure PHP 8.1+ with required extensions

**Emails not sending**
- Test SMTP in Admin > Settings > Email
- Check SMTP credentials and port
- Verify your email provider allows app passwords

**Timer not syncing**
- Clear browser cache
- Check for JavaScript errors in browser console
- Ensure the API endpoint is accessible

**Update stuck in maintenance mode**
- Access `rescue.php` directly in browser
- Click "Disable Maintenance Mode"
- Maintenance mode auto-expires after 10 minutes

**Notifications not appearing**
- Check notification preferences in Profile
- Ensure the notification event type is enabled
- Clear browser cache

**File upload fails**
- Check `upload_max_filesize` in php.ini
- Verify `uploads/` directory permissions (755)
- Check `MAX_UPLOAD_SIZE` in config.php

### Recovery Tools

- **rescue.php** — Emergency tool to disable maintenance mode and reset settings
- **upgrade.php** — Run database migrations manually after a failed update

### Getting Help

- Documentation: [foxdesk.org](https://foxdesk.org)
- Source code: [github.com/lukashanes/foxdesk](https://github.com/lukashanes/foxdesk)
