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
  Před migrací zůstává dostupné přihlášení heslem, ale remember-me se nevydává
  ani neobnovuje. Opakované kontroly schématu nesmějí tuto podmínku obejít.
  Nastavení povinného 2FA probíhá formuláři na profilu.
- Soukromé inline obrázky vyžadují vazbu na tiket v metadatech příloh.
  Starší obrázky bez této vazby vyžadují inventarizaci a schválené doplnění
  metadat před vydáním. Samotný odkaz v textu tiketu přístup neuděluje.
- Koncepty a rozpracovaný časovač jsou vázané na session a tab prohlížeče.
  Maximální stáří konceptu je 24 hodin; staré koncepty z localStorage se mažou.

## Ověření

- `npm run build:css` a `npm run test:css-build`
- `npm run test:security-review` včetně `test:security-review-r2`
- `npm run test:i18n`, `npm run test:email-policy`

Regresní testy používají izolované náhrady databáze a transportu. Nenahrazují
integrační test migrace v MariaDB ani ověření skutečného doručování pošty.
Podrobný interní posudek a lokální provozní poznámky nejsou součástí tohoto dokumentu.

## Příprava PR #26 ke sloučení (26. 11. 2026)

**ROZHODNOUT.** Opravy regresí jsou připravené v pracovním stromu. Nasazení
vyžaduje dořešení níže uvedených dopadů a provozní ověření. Kontrola používá
místní `main` (`8d1bd4b`), který je předkem větve; vzdálený stav nebyl načítán.
Nebyly provedeny commity, odeslání zpráv, změny aplikační databáze ani nasazení.

Dodatečné opravy:

- `includes/auth.php` ukládá skutečný výsledek kontroly schématu remember-me.
  Obnova staré cookie následovaná přihlášením heslem v témže požadavku tak
  nezkouší používat dosud chybějící `auth_version`. Regresní scénář je součástí
  `tests/auth-upgrade-access-test.php`; před opravou selhal, po opravě prošel.
- `tests/api-upload-idempotency-test.php` po zvětšení syntetického souboru
  čistí stat cache. Jinak `filesize()` vrací předchozí velikost a test chybně
  hlásí přijetí nadlimitního souboru. Produkční limit se tímto nemění.

### Výsledky dostupných kontrol

Závislosti a pomocné nástroje jsou mimo pracovní strom. JavaScript byl ověřen
na Node 20.20.2; PHP kontroly používají PHP 8.2.35 ve WebAssembly, nikoli
nativní PHP z CI. Síť byla použita pouze pro registr npm. SQL a poštovní testy
používají náhrady nebo syntetickou SQLite v paměti, bez aplikační databáze.

| Příkaz / kontrola | Výsledek |
| --- | --- |
| `npm ci --ignore-scripts --no-audit --no-fund` s instalací do cache | Prošlo; instalační skripty vypnuté, volitelný macOS `fsevents` se na Windows neinstaluje. |
| `npm run test:docs` | Prošlo. |
| `npm run test:i18n` | Prošlo, včetně validace katalogů, IME, RTL a kontroly CSS. |
| `npm run test:shared-behavior` | Prošlo. |
| `npm run test:security-review` | Prošlo, včetně `test:security-review-r2` a pěti variant přihlášení. |
| `npm run test:db-clock` | Kontrakty a pomocné funkce prošly; MariaDB integrace je přeskočená. |
| `npm run test:core-parity` | Prošlo. |
| `npm run lint:php` | Prošlo všech 405 PHP souborů; změněné PHP soubory znovu zkontrolovány po opravách. |
| `npm run build:css` a `npm run test:css-build` | Prošlo; výsledné CSS je obsahově beze změny. |
| `npm run test:email-policy` | Prošlo bez doručování pošty. |
| `npm run test:agent-api-control` | Prošlo, včetně offline MCP smoke testu. |
| `npm run test:ui-system` a `npm run test:release-manifest` | Prošlo. |
| `npm run test:product-copy` | Prošlo. |
| `npm run test:settings-modules` a `npm run test:report-modules` | Prošlo. |
| `node --check` nad vlastními verzovanými `.js`/`.mjs` soubory | Prošlo všech 68 souborů. |
| `npm audit --package-lock-only --registry=https://registry.npmjs.org` | 0 zranitelností. |
| Každý samostatný offline `tests/*-test.php` a `tests/*.test.js` | 139 prošlo; 4 zděděná selhání; 2 nedostupné kvůli runtime; 7 integračních souborů přeskočeno. |
| `git diff --check` a kontrola celého diffu proti `main` | Bez konfliktních značek, nalezených tajných údajů, lokálních cest a dočasných souborů. |

Čtyři zděděné chyby nebyly opravovány v bezpečnostním PR. Porovnání s `main`
potvrdilo stejné testy i chybějící podmínky už v bázi:

- `tests/admin-ui-contract-test.php`: očekává navigační název Dashboard.
- `tests/compact-ui-contract.test.js`: očekává `font-weight: 700` aktivní záložky.
- `tests/module-extraction-contract-test.php`: očekává šířku sidebaru 280 px.
- `tests/monolith-exit-inventory-contract-test.php`: inventář neuvádí
  `pages/microsoft-oauth.php`.

`tests/no-external-cdn-contract-test.php` a
`tests/runtime-schema-mutation-contract-test.php` nelze v použitém PHP runtime
dokončit, protože nepodporuje `GLOB_BRACE`. Testy nebyly oslabeny ani upraveny.
MariaDB migrace, rate-limit integrace, HTTP workflow, Playwright E2E a Docker
image build nebyly spuštěny: Docker daemon není dostupný a nebyla použita
žádná existující databáze nebo služba. Release balíček nebyl vytvářen.
Samostatný TypeScript/typecheck skript projekt nemá. Skutečný desktopový ani
390px mobilní náhled aplikace bez instalace/databáze nebyl ověřen; tento krok
nemění rozvržení ani vzhled aplikace.

### Co dokončit před nasazením

1. V izolovaném prostředí dokončit nativní CI, integrační test migrace v MariaDB
   a ověření přihlášení, 2FA, příloh a konceptů na desktopu i mobilu. Test
   doručení pošty vyžaduje zvláštní oprávnění a testovací transport.
2. Schválit dopad změny/resetu hesla na existující API integrace: implementace
   odvolá všechny jejich tokeny a ostatní session daného uživatele.
3. Připravit inventuru a bezpečné doplnění vlastnictví starších inline obrázků.
   Obrázek bez prokazatelné vazby na tiket zůstává odmítnutý. Obrázky nahrané
   editorem ještě před vznikem tiketu nadále používají `public/`
   (`includes/api/upload-handler.php`); toto zděděné chování neplní požadavek
   na soukromé úložiště podání a vyžaduje samostatné řešení vlastnictví.
4. Dokončit implementaci a ověření již schválené retence: podání/přílohy
   **3 roky**, zálohy 30 dní, diagnostika/odložené záznamy 90 dní a čítače
   30 dní, bez mazání indexu nedoručených. Současný cron uklízí jen vybraná
   data (např. notifikace a page_views); zálohy omezuje počet, nikoli stáří.
   Automatické mazání tiketů, příloh a navázaných výkazů nebylo do tohoto PR
   přidáno bez návrhu bezpečného výběru a integračních testů. Délka 3 roky
   se znovu neschvaluje; je třeba určit navazující implementační PR.
5. Pro e-mailový import samostatně doložit pravidla pro neověřená podání:
   denní rozpočty, odložení bez e-mailu, upozornění jednou denně a nepřeposílání
   příloh. Nový `public_auth_mail_reserve()` chrání obnovu hesla, nikoli všechny
   importní a notifikační cesty. Tento PR není důkazem splnění všech pravidel
   formulářů. Ověřit též serverový limit multipart/chunked těl a WAF rate limit.
6. Pro vlastní vydání připravit zálohu a migrační okno: nové soubory včetně
   `2026112401_auth_version.php`, poté `php upgrade.php`, kontrola sloupců
   `users.auth_version` i `api_tokens.auth_version` a dostupnosti tabulky
   `rate_limits` s unikátním indexem `(rate_key, ip_address)` před obnovením
   provozu. Tato příprava žádný z těchto kroků na serveru neprovedla.

Nové povinné secrets nejsou potřeba. Volitelná proměnná
`FOXDESK_PUBLIC_MAIL_DAILY_LIMIT` má výchozí hodnotu 1000 (rozsah 1–100000).
`FOXDESK_TRUST_CF_CONNECTING_IP` je volitelná konstanta v konfiguraci; zapínat
ji pouze s proxy, která přepisuje hlavičku ověřenou hodnotou. Před vydáním
ověřit dosavadní `TRUST_PROXY` a `FOXDESK_TRUSTED_PROXIES` podle skutečné sítě.

Z místních větví nelze určit pořadí vůči dalším neotevřeným PR. Správce musí
před sloučením ověřit aktuální bázi a souběžné změny autentizace, uploadů,
migrací a ukládání konceptů; vzdálená PR nebyla kvůli zákazu API kontrolována.
