# Compact FoxDesk Web UI Implementation Plan

> **For agentic workers:** Execute this plan inline in the isolated edition worktrees. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Make every FoxDesk web workspace use compact, neutral controls and information surfaces, starting with the ticket navigation supplied by the user.

**Architecture:** Change shared density, radius and shadow tokens in each edition's `theme.css`; update the existing ticket navigation rules at their source. Use an explicitly scoped application density section for legacy page components and utility classes. Preserve semantic avatars, focus feedback, status colors and overlay elevation.

**Tech Stack:** PHP-rendered FoxDesk, shared CSS, CleanCSS build, Playwright/Chromium, isolated MariaDB/PHP Docker runtime.

**Spec:** User request on 2026-09-30: remove AI slop across the application and make everything compact. Supplied screenshots: `/var/folders/4b/n0hg_kb51k1dp861px2fmpb00000gn/T/codex-clipboard-0dfeebcb-ac3b-4ea7-8002-511920997373.png` and the duplicate ending `7aca937a-899e-4bbe-9537-6688d1562f9a.png`.

## Global Constraints

- Scope: authenticated Cloud and self-hosted web, desktop and 390 px mobile; native iOS and marketing sites are separate products.
- Desktop controls: 32 px regular, 28 px small; touch controls retain at least 40 px at narrow widths.
- Cards: 6 px radius; controls/badges: 4 px; cards and navigation have no decorative shadow.
- Content gaps and padding: predominantly 8–12 px; readable body text and status meaning are preserved.
- Logical CSS properties and both light/dark themes; build generated CSS, never edit it directly.
- Existing product behavior, data queries, permissions, and translations remain intact.
- Share actual before/after screenshots of populated pages before any commit or deployment.

### Task 1: Shared density and ticket navigation

**Files:** Modify `theme.css`, generate `assets/css/theme.min.css` in both edition worktrees.

- [x] Capture baseline tickets and Work at 1440 px and 390 px.
- [x] Change `--fd-radius-card` to `6px`, control/pill/icon radii to `4px`, regular control height to `2rem`, small to `1.75rem`, card padding to `.75rem`, table row height to `2.5rem` and neutralize surface shadows.
- [x] Change ticket tabs from individual filled cards to compact text tabs with a thin active underline. Show counts as inline numerals, not pill badges.
- [x] Reduce registry toolbar padding/gaps and assigned/unassigned filter button density.
- [x] Run CSS build and the existing UI-system contract. Remove the existing derived control radius which already fails the baseline contract.

### Task 2: App-wide legacy surfaces

**Files:** Modify the scoped compact-density section in `theme.css` in both edition worktrees.

- [x] Compact headers, cards, tables, forms, sidebar links and empty states.
- [x] Compact Work metrics/queues, report builder/filter surfaces, client/team/settings navigation, ticket context/reply/detail and modal padding.
- [x] Remove decorative card hover movement, nested icon tiles, pulse effects and decorative background treatments from app surfaces; preserve focus rings and modal/dropdown separation.
- [x] At 390 px use wrapping or local scrolling, keep touch targets usable and inputs readable; never clip the document to hide overflow.

### Task 3: Verify and hand off

**Files:** Add `tests/smoke/compact-ui-qa.js`, screenshots/evidence under `audits/compact-ui-2026-09-30/` at workspace root.

- [x] Run actual local rendered checks of tickets, Work, ticket detail/edit, new ticket, clients, team, reports, settings, profile and billing at desktop/mobile, light/dark, plus an RTL sample.
- [x] Check page overflow, control geometry, visible tab counts, keyboard focus, tab/filter interactions and ticket modal dismissal.
- [x] Run required `test:i18n`, CSS build contract and relevant existing frontend/layout checks for each edition; record any pre-existing baseline failures separately.
- [x] Inspect screenshots and share desktop/mobile changes in chat.
- [x] Obtain an independent code review and repair material findings.
- [x] Leave reviewable changes on the isolated branches and report local verification and deployment state precisely.

## Verification result

- SaaS: 48 populated route/theme/viewport combinations passed.
- Self-hosted: 44 combinations passed.
- Both: numeric ticket counts, 40 px checked touch targets, visible keyboard focus, mobile search, ticket edit/Escape, mobile title geometry and RTL document bounds passed.
- Shared density/UI, app shell, core workflow, list filter, ticket-detail JavaScript, CSS build and required i18n/RTL contracts passed. SaaS app-frontend bridge passed.
- Independent review: ready, no remaining blockers. Repaired public-token leakage, report step contrast, touch targets, and invalid nested self-hosted ticket/tag links.
- Rendered evidence: /Users/mac/Dev/FoxDesk/audits/compact-ui-2026-09-30/{before,after,selfhost}.
- The isolated runtimes contain synthetic data only. Production, native iOS, public sites, and the user's existing dirty work remain unchanged.
