# FoxDesk UI System Contract

FoxDesk workspace UI uses one shared component contract for SaaS and self-hosted helpdesk screens. New app UI should use these primitives instead of page-local radius, padding, or control sizing.

## Tokens

- Cards and panels: `--fd-radius-card`, `--fd-card-padding`
- Controls: `--fd-radius-control`, `--fd-control-height`, `--fd-control-height-sm`
- Pills and badges: `--fd-radius-pill`
- Icons and avatar containers: `--fd-radius-icon`
- Tables: `--fd-table-row-height`

## Primitives

- `.fd-card`
- `.fd-page-section`
- `.fd-button`, `.fd-button--primary`, `.fd-button--secondary`, `.fd-button--sm`
- `.fd-input`, `.fd-select`
- `.fd-segmented`, `.fd-segmented__item`
- `.fd-badge`
- `.fd-table`

Legacy classes such as `.btn`, `.btn-sm`, `.form-input`, `.form-select`, `.workspace-queue-link`, `.work-period-link`, and `.settings-section-card` must map back to these tokens.

## Rules

- Do not add new hardcoded app UI radius values in pages.
- Use `9999px` only for badges, pills, and circular avatars.
- Period switches use `.fd-segmented`, not separate pill buttons.
- Shared helpdesk screens must keep the same component classes in SaaS and self-hosted.
- Page-specific CSS is allowed only for layout, not for redefining the visual language.

## Verification

Run:

```bash
npm run test:ui-system
```

The contract checks tokens, primitives, mapped legacy classes, Work screen structure, and the queue surface.
