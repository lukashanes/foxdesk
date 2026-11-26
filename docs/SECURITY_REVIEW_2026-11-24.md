# Bezpečnostní změny FoxDesk Self-hosted – PR #26

Stav k 26. 11. 2026: **BLOKOVÁNO pro úplné bezpečnostní zadání**.
Dostupné lokální kontroly prošly, ale pravidla příjmu podání a retence
nejsou ve všech cestách implementována a chybí nativní integrační ověření.
Samostatně zůstává rozhodnutí o odvolávání API tokenů při změně hesla.
Tento dokument nahrazuje dřívější výsledky; nepřebírá staré úspěchy jako
ověření současného pracovního stromu.

## Báze a konflikty

- Práce proběhla ve větvi `codex/bezpecnost-2026-11`, bez commitů,
  změn indexu, pushů, merge a nasazení.
- Místní `origin/main` (`8d1bd4b`) je předkem `HEAD` (`cc9296a`).
  `git ls-files -u` je prázdné; žádné konflikty nebylo nutné řešit.
  Aktuální vzdálený stav nebyl načítán.
- `package.json` zachovává závislosti z main; PR přidává pouze dva skripty
  bezpečnostních regresí. Jediný npm projekt je v kořeni repozitáře.
- Lockfile byl přegenerován pomocí `npm install --package-lock-only
  --ignore-scripts --include=optional`; výsledný obsah se od main neliší.
  Obsahuje i volitelný macOS `fsevents`. Instalace s cílovými platformami
  Linux x64 a arm64 prošly na Windows; skutečný linuxový běh není doložen.
- Rekurzivní `grep -RInE '^<<<<<<<|^=======$|^>>>>>>>'` mimo `.git`
  a `node_modules` nenašel konfliktní značky (exit 1 znamená žádnou shodu).

## Dodatečné opravy v pracovním stromu

1. `assets/js/autosave.js` při načtení uklízí i staré koncepty jiných
   formulářů ve stejné session, včetně workflow a osobních vyhledávání.
   Vadná/budoucí data odmítá; koncept expiruje také přesně po 24 hodinách.
   Zachovává platné koncepty, krátké undo a marker upozornění časovače.
   Dva nové regresní scénáře před opravou selhaly, po opravě prošly.
   Úklid probíhá při načtení autosave a obnova kontroluje stáří; nejde
   o důkaz aktivního mazání ze všech dlouhodobě otevřených/suspendovaných tabů.
2. `theme.css` používá u vyhledávání tiketů logické paddingy.
   Rozměry v LTR zůstávají stejné, RTL získává správnou stranu odsazení.
   `assets/css/theme.min.css` vzniklo standardním buildem.
3. Regresní kontrakty odpovídají současnému main: hlavní navigace Work,
   současné tokeny sidebaru a aktivních záložek a vykreslené odkazy Nastavení.
   Test navigace nyní vykonává skutečné renderovací funkce s offline náhradami.
   Inventář zahrnuje Microsoft OAuth a existující moduly; limitovaný soubor
   musí existovat, takže chybějící modul už nemůže projít jako nula řádků.
   Limity 700/900 řádků zůstávají zachovány.
4. Testy zákazu externích CDN a runtime migrací používají přenosné globy
   se stejnou sadou přípon místo volitelného `GLOB_BRACE`.
   Žádný test nebyl odstraněn, označen skipem ani oslaben zvýšením limitu/retry.

Dřívější opravy remember-me před migrací a vyčištění stat cache v testu
příloh už byly v HEAD; jejich regrese nyní znovu prošly.

## Aktuální kontroly

Npm závislosti, PHP runtime, logy a dočasné výstupy jsou ve sdílené cache,
nikoli v repozitáři. Použit Node 20.20.2 a PHP 8.2.35 ve WebAssembly.
PHP runtime běžel bez `intl`: pokus s tímto rozšířením selhal uvnitř
WASM loaderu (`TypeError: resolved is not a function`). Testy lokalizace
prošly se skutečnou aplikační implementací pro prostředí bez intl.
Nativní PHP ani nativní cestu s intl tyto výsledky nenahrazují.
Testy používají syntetická data, náhrady transportu/databáze nebo SQLite
v paměti. Žádná aplikační databáze, skutečná pošta ani server nebyly použity.

| Příkaz / rozsah | Výsledek |
| --- | --- |
| `npm ci` s prefixem závislostí v cache | Prošlo, instalační skripty povolené. |
| `npm ci --include=optional --os=linux --cpu=x64` / `--cpu=arm64` v cache | Oba příkazy prošly na Windows; kontrola cílové platformy v npm, nikoli běh Linuxu. |
| `npm audit --json` | 0 nálezů, včetně dev závislostí. |
| `npm audit --omit=dev --json` | 0 nálezů. |
| `npm run test:docs` | Prošlo. |
| `npm run test:i18n` | Prošlo, včetně katalogů, IME, RTL a CSS. |
| `npm run test:shared-behavior` | Prošlo. |
| `npm run test:security-review` | Prošlo, včetně r2, pěti variant přihlášení a 12 scénářů autosave. |
| `npm run test:db-clock` | Kontrakty a helpery prošly; MariaDB integrace se sama odmítne bez opt-in konfigurace. Není započítána jako úspěšná integrace. |
| `npm run test:core-parity` | Prošlo. |
| `npm run build:css` / `npm run test:css-build` | Oba příkazy prošly. |
| `npm run lint:php` | Prošlo všech 405 PHP souborů. |
| `node --check` nad vlastními JS/MJS soubory | Prošlo všech 76 souborů. |
| Samostatné dostupné `tests/*-test.php` a `tests/*.test.js` | 145 souborů prošlo, 0 selhání; 7 dalších vyžaduje nedostupné služby, viz níže. |
| `npm run test:email-policy`, `test:agent-api-control`, `test:ui-system`, `test:release-manifest`, `test:report-modules`, `test:settings-modules`, `test:product-copy` | Všech sedm příkazů prošlo bez skutečného doručování. |
| `git diff --check` | Prošlo. |

Workflow `tests.yml` má šest testovacích příkazů uvedených výše; všechny
skončily nulou s popsaným omezením MariaDB části. Projekt nemá samostatný
TypeScript/typecheck skript. Původní čtyři selhávající kontrakty a dva
kontrakty s GLOB_BRACE nyní procházejí.

### Co nelze v tomto prostředí ověřit

- `tests/api-token-rate-limit-integration-test.php`,
  `tests/db-clock-integration-test.php` a
  `tests/schema-migration-integration-test.php` vyžadují izolovanou MariaDB
  a explicitní konfiguraci. Taková databáze nebyla poskytnuta ani vytvořena.
- `tests/api-idempotency-transaction.test.js`,
  `tests/ticket-workflow-integration.test.js` a
  `tests/ticket-workflow-token.test.js` potřebují lokální Docker/HTTP fixture;
  `tests/work-period-persistence.test.js` běžící lokální HTTP aplikaci.
  Úvodní pokusy skončily chybou Dockeru nebo ECONNREFUSED.
- `npm run e2e` používá Docker Compose, MariaDB a lokální aplikaci.
  Není doložena funkčnost přihlášení, uploadů a migrace v reálném HTTP provozu.
- Docker daemon není dostupný (chybí pipe `docker_engine`); WSL vrací
  `Wsl/EnumerateDistros/Service/E_ACCESSDENIED`. Nativní PHP v PATH chybí.
- Workflow `docker-publish.yml` nelze sestavit bez Dockeru a základních image;
  release tag ani oprávnění k publikaci nebyly zadány. Přihlášení do registru
  a publikace by navíc překročily výslovný zákaz nasazování.
- Skutečný desktopový ani 390px mobilní náhled nebyl ověřen, protože není
  dostupná nainstalovaná aplikace/databáze. Build a kontrakty nejsou vizuální QA.

## Neuzavřené bezpečnostní body

Tyto body nejsou splněné pouhým úspěchem regresních testů:

1. **Příjem a e-maily.** `includes/public-mail-rate-limit.php` omezuje
   obnovu hesla; nepokrývá celý IMAP/Microsoft import ani všechny notifikace.
   Chybí doložení úplných denních rozpočtů týmu i návštěvníka, trvalého
   uchování při potlačeném doručování a jediného denního upozornění.
   Importní cesty stále přijímají přílohy také při povolených neznámých
   odesílatelích. Samotný From/allowlist není důkaz ověřeného podání.
   Bez návrhu důvěryhodného ověření a integračních testů není bezpečné tyto
   cesty označit za vyřešené ani přidávat neověřenou novou frontu.
2. **Veřejné vstupy.** Omezený čtenář testuje těla bez Content-Length
   i čtení limitu + jednoho bajtu. Guard 64 KiB je u veřejných větví
   index.php; chybí end-to-end důkaz limitů/rate limitů všech samostatných
   veřejných PHP vstupů a multipart/chunked těl na serveru/proxy.
3. **Soukromé obrázky.** `includes/api/upload-handler.php` ukládá editorové
   obrázky před vznikem tiketu do `public/`. Soukromé vlastnictví těchto
   rozpracovaných uploadů a zpětné doplnění starých metadat nejsou vyřešené.
   Starý obrázek bez vazby na tiket bezpečně zůstává odmítnutý. Odkaz v
   uživatelském textu nesmí sám udělovat přístup.
4. **Retence.** Tři roky pro podání a 30–90 dní pro technické záznamy jsou
   dané požadavky, nikoli otázka k novému schválení. Cron uklízí jen vybraná
   data (např. čtené notifikace a page_views po 90 dnech). Úplná retence
   podání, příloh, importních kopií, logů, e-mailů a záloh chybí.
   Mazání navázaných výkazů a sdílených příloh vyžaduje datový návrh,
   bezpečný výběr a integrační ověření, které zde nejsou k dispozici.

## Rozhodnutí a pořadí před vydáním

- Lukáš musí rozhodnout, zda změna/reset hesla zruší také všechny API tokeny
  a ostatní přihlášení účtu. Současná implementace to dělá; může přerušit
  existující integrace. Tuto politiku jsem neměnil.
- Určit konkrétní denní rozpočty týmu/návštěvníka a vlastníka provedení retence
  včetně poštovních schránek a záloh. Délku retence znovu neschvalovat.
- Nejdříve dokončit výše uvedené technické mezery a izolované nativní testy,
  včetně PHP s intl, skutečného Linux npm ci a desktopového/mobilního QA.
- Při autorizovaném vydání: záloha → nové soubory včetně migrace
  `2026112401_auth_version.php` → `php upgrade.php` → ověření sloupců
  `users.auth_version` a `api_tokens.auth_version`, tabulky `rate_limits`
  a unikátního indexu `(rate_key, ip_address)` → ověření přihlášení/API/2FA
  a příloh → obnovení provozu.
- Tato dodatečná oprava nepřidává migraci ani povinné secrets.
  Stávající volitelný `FOXDESK_PUBLIC_MAIL_DAILY_LIMIT` má výchozí hodnotu
  1000 a rozsah 1–100000. Ověřit proxy konstanty `TRUST_PROXY`,
  `FOXDESK_TRUSTED_PROXIES` a `FOXDESK_TRUST_CF_CONNECTING_IP` podle skutečné
  sítě; žádná produkční konfigurace nebyla kontrolována ani změněna.
- Před sloučením správce ověří aktuální vzdálenou bázi a souběžné PR.
  Pořadí vůči vzdáleným změnám autentizace/uploadů nebylo bez sítě potvrzeno.
