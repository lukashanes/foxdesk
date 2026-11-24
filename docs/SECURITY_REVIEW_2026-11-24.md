# FoxDesk self-hosted: bezpečnostní přezkum a lokální opravy

Datum: 24. 11. 2026. Připraveno pro Lukáše Hanese.

Změny jsou pouze v pracovním stromu. Bez commitů, větví, pushů, nasazení,
reálné databáze, odesílání pošty nebo přístupu k produkci. Jediná použitá
externí síťová služba byla npm registry pro audit závislostí. Dočasné nástroje,
výsledky a npm cache jsou v `%TEMP%`.

Podklady: dodané `foxdesk-saas-bezpecnost.md`, `oprava-foxdesk-saas.md`,
`foxdesk-saas-52.md` a pravidla bezpečnosti formulářů z 18. 11. 2026.
Implementace byla porovnána i se současným lokálním kódem opravené SaaS edice.
Přezkum provedl jeden auditor; samostatný další auditor ani spravovaný sken
nebyli použiti. Není to potvrzení úplné bezpečnosti celého produktu.

## Přenos nálezů ze SaaS

| Nález | Self-hosted a výsledek |
| --- | --- |
| 1. Vnořené HTML obaly obcházejí sanitizaci | Potvrzeno a opraveno. Potomci se sanitizují před odstraněním obalu. Bez DOM se vrací escapovaný text. |
| 2. Tokeny a formuláře v request logu | Potvrzeno a opraveno. Pouze normalizovaná HTTP metoda, bez GET/POST dat a dynamické zprávy; anonymní requesty tento diagnostický záznam nevytvářejí. Auth události zůstávají v místním bezpečnostním logu. Tenantová část se nepřenáší. |
| 3. Odpověď e-mailem připojí cizího uživatele k tiketu | Potvrzeno a opraveno společnou kontrolou oprávnění před vložením komentáře pro IMAP i Microsoft 365 a při výběru příjemců odpovědi. Historický komentář ani CC nezakládají přístup. CC nabídka ukazuje pouze uživatele s oprávněním. |
| 4. Veřejnost obrázku se odvozuje z jeho URL v obsahu | Potvrzeno a opraveno. Rozhodují explicitní metadata; chráněná příloha má přednost před veřejným prefixem. Odstraněno také zaměňování cest přes `basename()`. Mezi organizacemi se zde data nesměšují, protože jde o self-hosted edici. |
| 5. API obchází povinné dokončení 2FA | Potvrzeno a opraveno společnou kontrolou pro HTML, API a přihlášený přístup k přílohám. Výjimka pro servisního AI agenta platí pouze při skutečné autentizaci API tokenem. Veřejný platný share token zůstává samostatným oprávněním. |
| 6. Identita autora z neověřeného From | Obdobné riziko zůstává u IMAP i Microsoft 365. Povolená adresa ani přenos od poskytovatele neprokazují autora. Vyžaduje rozhodnutí o důvěryhodné poštovní bráně nebo tokenizovaných odpovědích; nevymýšlím ověření z libovolných hlaviček. |
| 7. Koncepty mezi účty | Potvrzeno a opraveno. `sessionStorage`, účet a nonce přihlášení, expirace 24 h, odstranění starých klíčů při migraci a přechodu na login. Zahrnut samostatný draft časovače a osobní vyhledávání na stránce Práce. |
| 8. Interní cíle SMTP testu | SaaS omezení se nepřenáší. Endpoint je omezen na admina, POST a CSRF; správce vlastní self-hosted instalace smí používat interní SMTP. |
| 9. Registrace obchází kvóty resetu | Registrace zde není. Samostatně byla doplněna ochrana existující obnovy hesla podle IP, příjemce, cooldownu a globálního denního stropu. |
| 10. Veřejný Cloudflare ingest načte neomezené tělo | Tento endpoint zde není. Doplněno omezené čtení existujících veřejných stránek a JSON/API cest, které tělo skutečně načítají. |
| 11. Reset neruší staré přístupy | Potvrzeno a opraveno pomocí `auth_version`. Atomický reset i změna hesla ruší předchozí relace, remember-me a API tokeny; pending 2FA a impersonace kontrolují verzi. Mobilní a párovací tabulky se nepřenášejí. |

Druhý názor byl zohledněn: AI agenti nejsou plošně zablokováni; neplatná
relace se opravdu odhlásí; neúspěšná rotace remember-me nevytvoří přihlášení;
revokace se týká i `update_password()`; migrace kontroluje existenci tabulek
a je zapsaná v paritním manifestu; nabídka CC odpovídá serverové autorizaci.

## Další potvrzené chyby z nezávislého přezkumu

- **Vysoká: push SSRF.** Běžný přihlášený účet mohl uložit libovolný endpoint
  a server na něj následně posílal push požadavky. Nyní se povoluje pouze
  HTTPS na portu 443, veřejné DNS cíle, bez přihlašovacích údajů, fragmentů,
  interních, rezervovaných a přechodových adres. Každé odeslání znovu ověří
  DNS a připne IP pomocí `CURLOPT_RESOLVE`; bez redirectů a proxy z prostředí.
  Nepotřebné tělo odpovědi se neukládá do paměti. Resolver v testu je náhrada,
  žádný skutečný push ani DNS dotaz se v testu neposílal.
- **Vysoká: aktivní SVG příloha na originu aplikace.** Ingest mohl přijmout
  SVG a `attachment.php` používal inline zobrazení pro každý `image/*`.
  Inline jsou nyní pouze JPEG, PNG, GIF a WebP. SVG a ostatní aktivní formáty
  se stahují. Současně se MIME a velikost e-mailové přílohy odvozují ze
  skutečných bajtů; PDF a rastrové přípony mají kontrolu obsahu. Vadná příloha
  se odmítne, text zprávy pokračuje ve stávajícím workflow.
- **Střední: CSRF u změn push odběru.** Oba zapisující push endpointy nyní
  vyžadují CSRF. Stávající klient již token posílal.
- **Střední: podvržení IP pro limity.** Veřejný rozsah `172.*` byl celý
  považován za proxy a hlavička mohla obsahovat libovolný řetězec. Používá se
  společná politika důvěryhodných proxy, přesný privátní rozsah a validace IP.
- **Střední: záměna API identity.** Neplatný explicitní Bearer token na
  tokenových endpointech již nespadne zpět na současnou browserovou relaci;
  skončí odpovědí 401 před vedlejšími účinky.

Prozkoumané hranice zahrnovaly router a oprávnění API, přihlášení a obnovu
hesla, impersonaci, uploady a downloady, veřejné share stránky, poštovní
ingest a příjemce, push, OAuth callback a síťovou funkci Microsoft 365,
instalátor/obnovu/upgrade, cron, lokální úložiště a pravidla přístupu k souborům.
V prozkoumaných SQL cestách nebyla doložena SQL injection. Lokální hledání
formátů soukromých klíčů a známých provider tokenů v 530 sledovaných souborech
nevrátilo kandidáta; nejde o důkaz absence každého typu tajného údaje.

## Kontrolní seznam formulářů

| Bod pravidel | Závěr a hranice ověření |
| --- | --- |
| 1. Podání a doručení | Tikety/komentáře mají existující ukládání a transakce. Reálné doručení nebylo testováno; trvalý retry outbox pro selhané SMTP notifikace zde není prokázán. Architektura fronty zůstává k rozhodnutí. |
| 2. Povolená pole a skutečný typ | Prozkoumané webové handlery čtou konkrétní pole souborů a používají finfo. E-mailový ingest nyní měří bajty a MIME, kontroluje PDF a rastry. Test odmítá HTML vydávané za PDF. |
| 3. Neověřená příloha v e-mailu | Prozkoumané notifikace přidávají odkazy, neposílají přijaté soubory jako MIME přílohy. Ověření skutečného autora příchozí pošty je otevřené. |
| 4. Denní kvóty pošty | Obnova hesla má 5 požadavků / 15 min podle IP a příjemce, 10 / 24 h na příjemce, cooldown 60 s a výchozí společný strop 1000 / 24 h (`FOXDESK_PUBLIC_MAIL_DAILY_LIMIT`). Kvóta se rezervuje před změnou tokenu, po nalezení aktivního účtu; neexistující adresy nespotřebovávají globální mailovou kvótu. Plná politika neověřeného ingestu / týmových mailů zůstává otevřená. |
| 5. Náhradní cesty | Registrace, veřejný Cloudflare ingest ani záložní formulářové endpointy zde nejsou. IMAP/Microsoft 365 mají zpracování po dávkách; to nenahrazuje ověření autora ani globální denní kvótu. |
| 6. Tělo požadavku | Veřejné stránky přes index mají limit 64 KiB před schedulerem a handlery. JSON čtení a běžná idempotence mají 1 MiB; upload idempotence respektuje limit příloh s 64 KiB rezervou. Test streamu bez Content-Length skončí po limitu + 1 bajtu. Nativní PHP parsuje POST před aplikačním kódem; proxy a PHP limity je nutné ověřit samostatně, stejně jako pomocné instalační endpointy. |
| 7. Veřejné zápisy | Odstraněn anonymní obecný request debug zápis. Reset má transakční kvóty. Není zavedena úplná denní kvóta všech auth logů, session souborů a počítadel veřejných share přístupů; je potřebná provozní politika. |
| 8. Fronty a kurzory | IMAP má UID stav a chybovou složku, Microsoft označuje zpracované i chybující zprávy. Neověřeno na službách. SMTP retry fronta a úplné stránkování příloh Microsoft 365 nad 100 položek vyžadují samostatný návrh. |
| 9. Koncepty | Koncepty, draft časovače a osobní hledání jsou v sessionStorage podle účtu/přihlášení s 24h platností. Staré místní klíče se mažou. Testováno ve VM s událostmi včetně IME a submitu, bez tvrzení o ověření v reálném browseru. |
| 10. Soukromí a retence | `.htaccess` blokuje uploads/storage/backups a proxy souborů autorizují přístup. Nastavení skutečného webserveru nebylo ověřeno. Nebylo přidáno automatické mazání tiketů, příloh ani záloh po třech letech; retenci musí schválit vlastník self-hosted dat. |
| 11. WAF | Neověřeno a neměněno podle zákazu zásahů do infrastruktury. |
| 12. Závislosti | npm audit lockfilu: 0 nálezů. Netýká se nebalíčkovaných vendored JS/PHP komponent; jejich aktuální CVE nebyla ověřována mimo npm registry. |
| 13. Testovací data | Pouze lokální syntetické hodnoty a náhrady služeb/databáze. Žádné skutečné odeslání formuláře, pošty ani push zprávy. |

## Navržené commity

Sdílené soubory obsahují několik samostatných celků; správce musí dělit jejich
bloky změn. Není nutné přenášet nové SaaS tabulky ani tenantové filtry.

1. **Opravit sanitizaci vnořeného HTML.**
   `includes/functions.php`, `tests/safe-html-nested-wrapper-test.php`.
   Potomci nepovolených obalů se kontrolují před rozbalením; bezpečný fallback.
2. **Odstranit citlivé údaje a anonymní debug request zápisy.**
   `index.php`, `includes/security-helpers.php`,
   `tests/request-log-privacy-test.php`, `tests/microsoft-mail-contract-test.php`.
   Request log obsahuje jen povolená metadata; bezpečnostní auth logy zůstávají.
3. **Opravit autorizaci obrázků, cesty a typy příloh.**
   `image.php`, `attachment.php`, `includes/upload-functions.php`,
   `includes/email-ingest-functions.php`, `tests/image-public-metadata-test.php`,
   `tests/attachment-path-resolution-test.php`, `tests/email-attachment-content-test.php`,
   `tests/security-debt-contract-test.php`.
   URL v obsahu neuděluje přístup, cesty se nezaměňují přes basename a aktivní
   formáty nejsou inline; MIME a velikost pošty se odvozují z obsahu.
4. **Autorizovat e-mailové konverzace a CC.**
   `includes/ticket-conversation-access.php`, `includes/email-ingest-functions.php`,
   `includes/mailer.php`, `includes/components/ticket-detail-composer.php`,
   `tests/ticket-conversation-authorization-test.php`.
   Sdílená autorizace před komentářem a notifikací; nabídka CC odpovídá oprávnění.
5. **Vynutit 2FA a revokovat staré přístupy při změně hesla.**
   `includes/auth.php`, `includes/api/router.php`, `includes/upload-functions.php`,
   `includes/security-helpers.php`, `includes/schema.sql`, `index.php`,
   `pages/login.php`, `pages/reset-password.php`,
   `migrations/2026112401_auth_version.php`, `config/migration-parity.json`,
   `tests/auth-session-boundary-test.php`, `tests/required-2fa-boundary-test.php`,
   `tests/password-reset-revocation-test.php`, `tests/auth-credential-epoch-test.php`,
   `tests/auth-version-migration-test.php`.
   Zahrnuje atomickou revokaci, souběhy tokenů, impersonaci, konzistentní odhlášení
   a zachování servisních AI tokenů při povinném 2FA. Neplatný Bearer nesmí použít
   browserovou identitu. **Vyžaduje migraci před použitím nového kódu.**
6. **Omezit veřejné požadavky a obnovu hesla.**
   `includes/public-mail-rate-limit.php`, `pages/forgot-password.php`,
   `includes/security-helpers.php`, `includes/admin-crud-helper.php`,
   `includes/auth.php`, `index.php`, `tests/public-mail-rate-limit-test.php`,
   `tests/request-body-limit-test.php`, `tests/client-ip-trust-test.php`.
   Transakční kvóty pošty, omezené čtení těla a oprava důvěry v předávanou IP.
7. **Izolovat koncepty, draft časovače a osobní hledání.**
   `assets/js/autosave.js`, `assets/js/work-dashboard.js`, `includes/header.php`,
   `includes/components/new-ticket-assets.php`, `pages/login.php`,
   `includes/auth.php`, `tests/autosave-isolation.test.js`.
   SessionStorage, účet, přihlášení, 24 h a migrace starých klíčů; zachována IME ochrana.
8. **Zabránit push SSRF a doplnit CSRF.**
   `includes/web-push.php`, `includes/api/push-handler.php`,
   `tests/web-push-target-test.php`.
   Veřejné HTTPS cíle, DNS pinning, omezení transportu a CSRF obou změn odběru.
9. **Zapojit bezpečnostní regresní sadu a zdokumentovat předání.**
   `package.json`, `docs/SECURITY_REVIEW_2026-11-24.md`.
   `npm run test:security-review` a tento záznam pokrytí, omezení a rozhodnutí.

## Ověření

- PHP 8.4.27 ve WASM runtime z existujících npm nástrojů v `%TEMP%`.
  Databázové a DNS testy používají náhrady, migrace používá PDO náhradu bez spojení.
- `npm run build:css` a `npm run test:css-build`: prošly; minifikované CSS nemá
  obsahovou změnu a jeho původní formát konců řádků byl zachován.
- `npm run test:security-review`: 15 PHP testů a 5 JS scénářů prošlo.
- `npm run test:i18n`, `npm run test:email-policy`,
  `npm run test:security-boundary`, `npm run test:core-parity`: prošly.
- Širší běh 127 samostatných PHP testů: 122 prošlo, 3 dříve existující rozpory
  a 2 překážky prostředí níže. Syntaxe všech 39 změněných/nových PHP souborů
  a obou změněných externích JS souborů byla ověřena.
- Původní XSS, zpřístupnění obrázku přes komentář, nepovolená e-mailová odpověď,
  sdílení konceptů, neomezené čtení, nebezpečný push cíl, chybná IP důvěra a
  kolize basename byly doloženy neúspěšným regresním případem před opravou.
- Windows npm wrapper potřeboval pro složené skripty Git `sh` jako
  `npm_config_script_shell`. Po nastavení pouze v procesu celé příkazy prošly;
  projektová konfigurace se kvůli tomu neměnila.

Záměrně nesouvisející selhání nebyla opravována:

- `admin-ui-contract-test.php`: očekává Dashboard místo existujícího Work.
- `module-extraction-contract-test.php`: očekává starší šířku sidebaru 280 px.
- `monolith-exit-inventory-contract-test.php`: v inventáři chybí Microsoft OAuth stránka.
- JS `compact-ui-contract.test.js`: očekává jinou tloušťku aktivní navigace.

Všechny čtyři rozpory byly ověřeny i proti nezměněnému obsahu HEAD. Neurčuji,
zda má správce změnit současný produkt, nebo starší testy.

`no-external-cdn-contract-test.php` a `runtime-schema-mutation-contract-test.php`
nelze dokončit, protože tento WASM runtime nemá `GLOB_BRACE`. Nebyly upraveny
testy tak, aby se tato překážka skryla. Dokumentační, release-manifest,
workflow-queue a UI-system JS kontrakty prošly.

Pět PHP testů vyžadujících databázi (včetně dvou SQLite scénářů) bylo vynecháno:
`api-token-rate-limit-integration-test.php`, `db-clock-integration-test.php`,
`schema-migration-integration-test.php`, `client-overview-runtime-test.php`,
`migration-line-ending-checksum-test.php`. Síťové/DB integrační JS a E2E nebyly
prováděny. Pokus o `work-period-persistence.test.js` skončil už při prvním GET
na localhost `ECONNREFUSED`; k odeslání formuláře nedošlo.

Reálná aplikace nemá lokální konfiguraci a povolený přístup k databázi. Proto
neproběhla skutečná vizuální kontrola desktopu ani 390px mobilu, SMTP/IMAP,
Microsoft 365, push doručení, migrace na MariaDB ani produkční ověření.

## Co zůstává k rozhodnutí a provoznímu ověření

1. Důvěryhodné ověření autora pošty a politika neověřeného ingestu, denních
   kvót pro tým i návštěvníka a trvalé retry fronty. Podvržení From zůstává rizikem
   i po opravě přístupu ke konkrétním konverzacím.
2. Potvrdit, že změna/reset hesla ruší také dlouhodobé API tokeny, a nastavit
   vhodný globální mailový strop (výchozí 1000 / 24 h). Tyto opravy jsou připravené.
3. Správce musí ověřit migraci na izolované MariaDB před vydáním. Migrace
   nebyla provedena proti žádné databázi. Stávající relace i tokeny začínají na
   verzi 0; první změna hesla je zneplatní.
4. Dřívější logy a případné uniklé platné tokeny nebyly měněny. Je nutné
   rozhodnout o jejich revokaci/retenci mimo tuto pracovní kopii.
5. Nový tiket stále před založením ukládá editorové obrázky do explicitně
   veřejného `uploads/public/`. Pro důvěrné pre-uploady je potřeba návrh navázání
   na tiket a oprávnění. Staré obrázky bez veřejných metadat nebo přílohy se po
   odstranění obsahového bypassu přestanou anonymně zobrazovat; není bezpečné
   automaticky je označit za veřejné podle výskytu v komentáři.
6. CSP `unsafe-inline` vyžaduje migraci existujících skriptů a skutečné UI QA;
   nebylo plošně odstraněno. Bezpečná sanitizace a SVG download nečekají na tuto práci.
7. Retence tiketů/příloh, záloh a logů, WAF, proxy/PHP limity, egress a skutečné
   zákazy přímého přístupu k souborům jsou provozní práce mimo zadané oprávnění.
   Nebyla zavedena automatická destruktivní retence uživatelských dat.
8. Před vydáním ověřit reálné přihlášení, 2FA, změnu hesla, přílohy a CC na
   desktopu a 390px mobilu; potom SMTP/IMAP/Microsoft 365 a push v testovacím
   prostředí s explicitně povoleným doručováním. Žádné nasazení nebylo provedeno.
