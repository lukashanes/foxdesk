# FoxDesk contributor instructions

Follow `AGENTS.md` for the complete repository rules.

## Chat-first work preview

- Before editing or running mutating commands, send a concise chat preview of
  the intended result, affected product area, and verification plan.
- For UI work, post actual rendered desktop and 390 px mobile screenshots in
  chat before commit or deployment. Use realistic populated data; if a real
  preview is blocked, say so and do not claim visual QA.
- State deployment scope and existing evidence in chat before deployment, then
  report live production verification separately.

## RTL / i18n

- Edit canonical JSON in `locales/catalogs/`, sync draft locales, then build
  generated `includes/lang/` PHP. Use semantic keys and `tn()` for new plural
  copy.
- Read locale choices and validation from `locales/registry.json`; never add a
  hard-coded language list.
- Prefer logical CSS properties; document any intentionally physical
  `left`/`right` rule. `float` does not mirror automatically.
- Mirror directional icons explicitly in RTL, but never mirror content such as
  logos, media, charts, code, URLs, phone numbers, email addresses, or IDs.
- Use `bidi_isolate()` for dynamic values in plain text and `<bdi dir="auto">`
  in HTML.
- Wait for `compositionend` before search, autosave, or command submission.
- Edit `theme.css` and regenerate CSS with `npm run build:css`; never edit the
  minified file directly.
- Run `npm run test:i18n` before committing.
