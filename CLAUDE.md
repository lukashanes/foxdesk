# FoxDesk — Claude Code Rules

## Git Commits
- NEVER add `Co-Authored-By:` lines (Claude, Anthropic, or any AI attribution) — user is sole author
- Commit messages: short subject line, optional body with feature summary

## Project Structure
- PHP 8.1+ helpdesk app, no framework, no Composer
- Entry point: `index.php` → routes to `pages/` via `?page=` param
- Admin pages: `pages/admin/` — Settings, Reports, Users, Recurring Tasks, etc.
- Shared includes: `includes/` (functions, header, footer, components, API handlers)
- Translations: `includes/lang/{cs,de,en,es,it}.php` — all UI strings use `t()` wrapper
- CSS: `theme.css` (custom) + `tailwind.min.css` (utility classes)
- JS: `assets/js/` — no build step, vanilla JS
- DB schema auto-migrates via `ensure_*` functions on first load

## Release / Update Workflow
- `version.json` — source of truth for version, date, changelog, delete_files
- `update/` folder — clean release files only (no .git, no Docker, no screenshots, no build/, no deploy/, no .png, no dev tooling)
- `bin/build-update.php` is dev-only, never included in update/
- `build/` dir holds zip artifacts + RELEASE-NOTES.md for tagged releases

## Code Patterns
- Database: `db_query()`, `db_fetch_one()`, `db_fetch_all()` — PDO wrappers in `includes/database.php`
- Auth: `is_admin()`, `is_agent()`, `is_logged_in()` from `includes/auth.php`
- Output escaping: `e()` for HTML, `json_encode()` for JS context
- Flash messages: `flash('message', 'success|error')` + redirect pattern
- Settings: `save_setting(key, value)` / `get_setting(key)` stored in DB
