## FoxDesk v0.3.82

Released: 2026-03-13

### What's new since v0.3.75

36 features + 1 bonus across 4 areas. 42 files changed, +5350 / -671 lines.

---

#### Notifications (9 features + 2 extras)

1. **Per-type notification preferences** — 7 toggleable types on Profile page
2. **Better unread indicators** — blue tint, dot indicator, pulsing badge
3. **Avatars in bell panel** — photo or initials fallback per notification
4. **Mark-as-read on mobile** — always-visible checkmark button per item
5. **Bell panel capped at 20** — with "View all" link
6. **Group dismiss** — mark all notifications for same ticket as read
7. **Notifications page parity** — same mark-read + group dismiss as panel
8. **Due date reminders** — cron-based overdue/due-soon alerts with dedup
9. **Browser push notifications** — VAPID Web Push, one-click enable in panel, works with tab closed
10. **Sound toggle** — mute/unmute from panel header
11. **Empty state** — "All caught up!" with illustration

#### Recurring Tasks (10 features)

1. **Run history** — last 5 runs with ticket link + timestamp
2. **Run Now** — manual trigger outside schedule
3. **Preview ticket** — modal with all fields, ID-to-name resolution, due date calc
4. **Search & filter** — text search + Active/Paused/Inactive tabs
5. **Configurable due date** — "Due in X days" on task form
6. **Auto-tag** — tags field, inherited by generated tickets
7. **Pause/resume** — with optional auto-resume date, orange badge
8. **Failure notification** — all admins notified on generation failure
9. **Duplicate task** — one-click clone with all settings
10. **Next run inline** — human-readable relative time in table

#### Reporting (13 features + 3 extras)

1. **Separate Client Reports nav** — dedicated sidebar link
2. **Sparkline bar indicators** — CSS-only proportion bars on Summary tab
3. **CSV/Excel export** — Summary, Detailed, Work Log with UTF-8 BOM
4. **Column picker** — toggle columns on Detailed tab, localStorage
5. **Collapsible filter panel** — auto-collapse with removable pills
6. **Weekly tab improvements** — date ranges, stacked bars, expandable
7. **Searchable client dropdown** — type-to-search in Report Builder
8. **Edit published reports** — builder opens pre-filled for editing
9. **Report duplication** — clone with auto-incremented dates
10. **Scheduled auto-generated reports** — weekly/monthly/quarterly via cron
11. **Email delivery** — HTML email with KPI summary + report link
12. **Smart financial columns** — Cost/Profit hidden when all zero
13. **Tag filter** — chip-select UI with filter pills
14. **Filter persistence** — date range saved in localStorage
15. **Date range presets** — Today, Last 7/30 days, This/Last quarter
16. **Print-friendly** — @media print, A4 landscape, Print button

#### Security (bonus)

- **TOTP Two-Factor Authentication** — Google Authenticator, Authy, 1Password
  - QR code setup + manual secret fallback
  - 8 one-time backup codes (SHA-256 hashed)
  - 2-phase login with rate limiting (5 attempts / 5 min)
  - Admin can require 2FA per role (Settings > Security)
  - Forced setup redirect when required but not configured
  - Disable requires current password
  - Remember-me + API tokens bypass 2FA

#### Other improvements

- **Security settings tab** — dedicated tab with 2FA enforcement and impact warnings
- **Settings consequence hints** — info on non-obvious settings across all tabs
- **Full i18n** — all remaining hardcoded UI strings wrapped in `t()` for translation

---

### New files

- `includes/totp.php` — TOTP 2FA implementation (RFC 6238)
- `includes/web-push.php` — VAPID web push
- `includes/notification-functions.php` — in-app notification engine
- `includes/report-functions.php` — report generation logic
- `includes/api/notification-handler.php` — notification API endpoints
- `includes/api/push-handler.php` — push subscription API
- `pages/admin/reports-list.php` — report list admin page
- `sw.js` — service worker for push notifications

### Upgrade instructions

**From v0.3.75:** Upload the contents of this update folder over your existing installation. The database schema updates automatically on first load (new tables for notifications, push subscriptions, recurring task runs, and report templates are created via auto-migration).

**Auto-update:** Go to Settings > System > Auto-Update and click "Download & Install".

**Manual:** Replace all files from this release. Your `config.php`, uploads, and database are preserved.

### Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- OpenSSL extension with EC support (for web push VAPID)
- cURL extension (for web push delivery)
