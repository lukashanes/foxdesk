# Bezpečnostní změny FoxDesk Self-hosted

Změny v této větvi zpřísňují kontrolu přístupu k přílohám a konverzacím,
práci s přihlašovacími údaji a zpracování nedůvěryhodného vstupu.

## Kompatibilita při aktualizaci

- Spusťte databázový upgrade podle instalační dokumentace. Běžné přihlášení
  pracuje také před přidáním sloupce `users.auth_version`; bezpečné odvolání
  přihlašovacích údajů při změně či resetu hesla vyžaduje migraci.
- Změna i reset hesla v současné implementaci ruší ostatní session,
  remember-me a API tokeny daného účtu. Před vydáním je třeba schválit tuto
  politiku s ohledem na existující integrace.
- Remember-me zůstává dostupné pro účty bez zapnutého nebo povinného 2FA.
  Nastavení povinného 2FA probíhá formuláři na profilu.
- Soukromé inline obrázky vyžadují vazbu na tiket v metadatech příloh.
  Starší obrázky bez této vazby vyžadují inventarizaci a schválené doplnění
  metadat před vydáním. Samotný odkaz v textu tiketu přístup neuděluje.
- Koncepty a rozpracovaný časovač jsou vázané na session a tab prohlížeče.
  Rozšíření jejich životnosti je samostatné produktové rozhodnutí.

## Ověření

- `npm run build:css` a `npm run test:css-build`
- `npm run test:security-review` včetně `test:security-review-r2`
- `npm run test:i18n`, `npm run test:email-policy`

Regresní testy používají izolované náhrady databáze a transportu. Nenahrazují
integrační test migrace v MariaDB ani ověření skutečného doručování pošty.
Podrobný interní posudek a lokální provozní poznámky nejsou součástí tohoto dokumentu.
