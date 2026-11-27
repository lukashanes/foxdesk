# Contributing to FoxDesk

Thank you for improving FoxDesk. This repository contains the self-hosted,
AGPL-3.0 edition.

## Start in the right place

- Use [Discussions](https://github.com/lukashanes/foxdesk/discussions) for setup help, ideas, and questions.
- Use [Issues](https://github.com/lukashanes/foxdesk/issues) for reproducible bugs and agreed feature work.
- Search existing issues and discussions before opening a new one.
- Report vulnerabilities through [private vulnerability reporting](https://github.com/lukashanes/foxdesk/security/advisories/new), never in a public issue.

## Repository scope

Contributions should improve the self-hosted product: tickets, organizations,
email integration, time tracking, client reports, API access, localization,
installation, updates, and recovery.

FoxDesk Cloud billing, subscriptions, tenant administration, usage metering, and
platform operations belong to the private SaaS codebase and are not accepted here.

## Local setup

Follow [INSTALL.md](INSTALL.md) for the application setup. Install frontend test
dependencies with:

```bash
npm ci
```

Use a local database with synthetic data. Never copy production credentials,
tokens, email archives, or customer records into a branch, issue, test, or
screenshot.

## Make a focused change

1. Create a short branch from the current `main`.
2. Keep the patch focused on one user-visible problem.
3. Add or update a test that proves the behavior.
4. Update documentation and screenshots when the user experience changes.
5. Open a pull request with the reason for the change and the checks you ran.

For CSS changes, edit `assets/css/theme.css` and run `npm run build:css`. Do not
edit `assets/css/theme.min.css` manually.

For application text, edit the source catalogs and follow the localization tools
documented in the repository. Do not hand-edit generated locale outputs.

## Checks

Run the checks that cover the changed surface. The usual baseline is:

```bash
npm run test:css-build
npm run test:core-parity
npm run test:shared-behavior
npm run test:security-boundary
npm run test:critical-paths
```

The critical-path suite uses PHP CLI with PDO SQLite and temporary in-memory
fixtures. For `npm run test:schema-migration`, set `FOXDESK_SCHEMA_INTEGRATION=1`
and point the `FOXDESK_SCHEMA_DB_HOST`, `FOXDESK_SCHEMA_DB_PORT`,
`FOXDESK_SCHEMA_DB_USER` and `FOXDESK_SCHEMA_DB_PASS` environment variables at
a disposable MariaDB instance. The test creates and drops its own database.

Run `npm run test:i18n` for localization work. Run the relevant module contract
tests for PHP behavior. A UI pull request should include light, dark, desktop,
and mobile verification where applicable.

## Pull request checklist

- The change belongs in the self-hosted edition.
- The user-facing result is explained before implementation detail.
- Tests cover the new behavior or regression.
- UI changes include current screenshots.
- New text is ready for localization.
- No secrets, customer data, private SaaS code, or generated dependencies are included.

By contributing, you agree that your contribution is licensed under the
[GNU Affero General Public License v3.0](LICENSE.md).
