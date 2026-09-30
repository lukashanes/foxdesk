# Utilitarian FoxDesk UI v2 Implementation Plan

> **For agentic workers:** Execute inline in the existing isolated SaaS and self-hosted worktrees. Use checkbox steps to track this user-requested revision.

**Goal:** Replace the rejected rounded blue-underline design with a visibly different, compact, neutral operational interface.

**Architecture:** Edit the existing scoped Compact operational UI rules in each theme.css, not a second override layer. Ticket navigation uses plain bold active text with no underline, shadow, fill or curved edge. Public pages, data, permissions and native iOS stay untouched.

**Tech Stack:** PHP, CSS, CleanCSS, local Playwright/Chrome and populated Docker fixtures.

**Spec:** User rejection on 2026-09-30 and attached crop /var/folders/4b/n0hg_kb51k1dp861px2fmpb00000gn/T/codex-clipboard-10fdd11b-456b-4004-ab75-275ba35d72e5.png. Do not answer this rejection with merely smaller rounding.

## Constraints

- Flat white/neutral-dark workspace, rectangular sections; app card radius 0, input/button radius 2px, navigation radius 0.
- No active blue tab bar, selected text shadow, count pills, decorative icon tiles or prominent update stripe.
- Active navigation indicated by darker/bolder text; keyboard focus remains clearly visible.
- Primary action stays visually distinct using a neutral high-contrast fill. Status, warning and real chart series colors retain their meanings.
- Desktop regular controls32px/small28px; checked mobile targets at least40x40px.
- Preserve horizontal local scrolling, mobile title readability, original route/query behavior and RTL.
- Share real desktop/390px screenshots before any commit or deployment.

## Task 1: Change the shared visual system

**Files:** theme.css and generated assets/css/theme.min.css in both editions; tests/compact-ui-contract.test.js.

- [x] Update the scoped token test to expect card0px/control2px and assert no active navigation underline; run it and confirm the old design fails.
- [x] Change scoped card/control/pill/icon radii to0/2/0/0; keep root defaults for public pages.
- [x] Remove ticket active bottom border at its source; set navigation radius0 and active text weight700 with neutral color.
- [x] Flatten segmented controls, client/settings/report navigation, sidebar CTA and cards; neutralize the update banner and avatars while retaining identities and semantic colors.
- [x] Build CSS and run shared UI/source contracts in both editions.

Example source rules:

```css
.app-shell-page { --fd-radius-card: 0px; --fd-radius-control: 2px; }
.app-shell-page .ticket-view-tab { border-radius: 0; color: var(--text-secondary); }
.app-shell-page .ticket-view-tab.is-active { background: transparent; color: var(--text-primary); font-weight: 700; }
```

## Task 2: Verify the exact rejected shape and the whole app

**Files:** tests/smoke/compact-ui-qa.js in both editions; evidence at /Users/mac/Dev/FoxDesk/audits/utilitarian-ui-v2-2026-09-30/.

- [x] Extend rendered metrics to assert active tab radius0, shadow none, bottom-border0 and weight700.
- [x] Sync only theme/generated CSS into isolated runtimes and capture the complete 48/44 route/theme/viewport matrix.
- [x] Inspect and share desktop/mobile tickets, Work and settings as actual rendered screenshots; test tab/search/editor/Escape and RTL.
- [x] Run required i18n/RTL, UI-system, app-shell and affected source contracts.
- [x] Obtain independent review; repair real regressions and keep the revision local pending visual acceptance.

## Verification notes

- Source token regression failed before the redesign and now passes in both editions.
- Rendered client-navigation regression failed before its unification and now passes.
- Visual inspection exposed a high-specificity legacy dark link reset overriding primary action text; component links now retain their own foreground colors. Primary action contrast is checked at 4.5:1 or better on every captured screen.
- Removed the hardcoded light comment hover that made dark-mode content unreadable; warning/success actions retain semantic color with legible dark foreground.
- SaaS 48 and self-hosted 44 route/theme/viewport states pass, including desktop 1440px, mobile 390px, exact active-tab shape, touch targets, overflow, populated titles/counts, tab navigation, mobile search, edit modal/Escape, and RTL.
- Both editions pass i18n/RTL, generated-CSS parity, UI system, application-shell, core UX, ticket filter and ticket-detail contracts. SaaS also passes app-frontend contract.
- Actual screenshots were shared in chat before integration. The original dirty checkouts remain unchanged; no deployment, push, merge or v2 commit has been performed.
- Independent review exposed an invisible keyboard focus on the selected List/Board icon. The new regression test failed before the fix; the selected icon now changes from a 1px inset selection outline to a distinct 2px external focus outline. The reviewer independently confirmed both themes and 1440/390px in both editions, with no remaining important or critical findings.
- Report period presets are also neutral text navigation, with 32/40px control heights and an explicit rendered selected-state check.
