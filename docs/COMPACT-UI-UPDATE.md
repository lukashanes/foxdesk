# Compact light workspace update

Status: **draft migration, not a stable release**. The last published version
remains 0.3.148. This branch does not change the updater version or publish a ZIP.

## Included in this public branch

- Shared compact light theme: soft surfaces, consistent card/control radii,
  pastel chart tokens, responsive layout rules and narrow-card ticket rows.
- Soft searchable selects with keyboard navigation, recent client/assignee
  choices, native form submission and per-user session storage.
- Ticket status is shown explicitly in the dashboard table.
- Core properties in the new-ticket form are visible without opening an
  advanced section.
- Completing/reopening a ticket reloads the saved state even when the current
  URL contains a fragment. Keyboard shortcut help no longer adds a text row.
- Responsive-layout and workflow-fragment regression contracts.

The shared visual component updates do not replace self-hosted authentication,
installation, database schema, API permissions or update/backup implementation.
No SaaS-only configuration, tenant data, secrets or customer screenshots belong
in this public change.

## Still needed before full redesign parity

This is deliberately not advertised as the complete compact SaaS application.
The public templates and backend differ, so copying that application wholesale
would bypass self-hosted compatibility checks.

1. Port the dashboard saved-time/client projection, live timer synchronization,
   latest-updates queue and notification/review panel as one verified flow.
2. Port the unified ticket conversation/work-log editor, audience preview and
   image/document quick previews without introducing tenant-only dependencies.
3. Integrate client detail/directory, standalone time, report history, profiles
   and settings grouping while retaining the self-hosted updater controls.
4. Verify persistence, CSRF, all user roles, RTL and large collections in a
   populated self-hosted installation. Validate compact and wide viewports.
5. Capture current public-edition screenshots, verify printed PDF output,
   then bump the public version and build/verify the update package.

Some newly shared theme selectors intentionally target forthcoming components;
their presence is not proof that those components are already implemented here.

## Verification boundary

Source/model contracts and generated-asset checks are recorded on the pull
request. They do not substitute for browser acceptance of this edition. Keep
the pull request in draft until the relevant self-hosted browser flows pass.

The extended local audit also exposed a pre-existing admin navigation contract
gap: `tests/admin-ui-contract-test.php` expects settings-management markers that
the current self-hosted templates do not provide. The source files for those
settings are unchanged in this branch; this failure remains a migration gate,
not a reason to weaken or remove the test. Two earlier inconsistencies found
by that test (the Dashboard label and logical search-input padding) are repaired.

On 2026-09-17 the extended local runner passed 25 of 26 checks, with only the
above admin-navigation contract failing. All individual `test:i18n` commands,
CSS generation parity, shared behavior, edition/security boundaries, UI-system,
responsive and workflow-fragment contracts passed. The added public-layout
compatibility contract passed separately. All five changed PHP templates passed
syntax checks, as did both changed JavaScript modules. No browser acceptance of
this public branch or self-hosted update-package installation has been claimed.
