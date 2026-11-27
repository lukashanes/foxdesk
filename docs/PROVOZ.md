# Provoz FoxDesku self-hosted

## Instalace

### Docker Compose

Potřebujete Docker Engine s pluginem Compose. V kořeni klonu zkopírujte `.env.example` na `.env` a nastavte `DB_PASSWORD` a `DB_ROOT_PASSWORD`. Pro hotový image nastavte `FOXDESK_IMAGE` na konkrétní verzi z [vydání FoxDesku](https://github.com/lukashanes/foxdesk/releases).

```bash
docker compose pull app cron
docker compose up -d --no-build
docker compose ps
```

Pro sestavení z tohoto klonu ponechte `FOXDESK_IMAGE` nenastavenou a použijte `docker compose up -d --build`. Dockerfile kopíruje hotové CSS z repozitáře. Po změnách `theme.css` napřed spusťte jeho build.

Otevřete `http://localhost:8080/install.php`, případně použijte port nastavený v `FOXDESK_HTTP_PORT`. Jako databázový server zadejte `db` na portu `3306`. Název databáze, uživatele a heslo převezměte z `DB_NAME`, `DB_USER` a `DB_PASSWORD`. Dokončete krok s účtem správce. Konfigurace je uložená ve svazku `config` a dostupná jako `/var/www/html/config.php`. Po instalaci se běžný požadavek na `install.php` přesměruje do aplikace.

Služby `app` a `db` mají v `docker compose ps` hlásit `healthy`. Služba `cron` sdílí aplikační data a čeká na dokončení instalace. Pro přístup zvenčí použijte HTTPS proxy a v `APP_URL` ověřte veřejnou adresu. Příklady proxy jsou v [Docker návodu](DOCKER.md#https-and-reverse-proxies).

### PHP hosting nebo VPS

1. Rozbalte instalační vydání z [GitHub Releases](https://github.com/lukashanes/foxdesk/releases) do webového kořene. Požadavky projektu uvádějí PHP 8.1+, MySQL 5.7+ nebo MariaDB 10.2+ a rozšíření `pdo_mysql`, `mbstring`, `json`, `openssl` a `zip`. Pro provoz odpovídající Docker image a kontrolám použijte PHP 8.2.
2. Připravte prázdnou databázi v `utf8mb4` a samostatného databázového uživatele s právy pro vytvoření schématu a budoucí migrace této databáze.
3. Zajistěte PHP právo zápisu pro vytvoření `config.php` a do `uploads/`, `storage/` a `backups/`. Otevřete `/install.php` přes výslednou HTTPS adresu. Průvodce vytvoří schéma, provede migrace a zapíše konfiguraci ve druhém kroku po vytvoření správce.
4. Omezte přístup ke `config.php`, odstraňte instalační skript z webového kořene a ověřte blokování přímého přístupu k interním adresářům. Pravidla pro Apache a Nginx jsou v [instalačním návodu](../INSTALL.md#vps--dedicated-server-guide).

## Konfigurace

| Umístění | Účel | Názvy |
| --- | --- | --- |
| `.env` pro Compose | Přístup k databázi | `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` |
| `.env` pro Compose | Image, HTTP a časové pásmo | `FOXDESK_IMAGE`, `FOXDESK_HTTP_BIND`, `FOXDESK_HTTP_PORT`, `TZ` |
| `.env` pro Compose | Limity PHP a provoz | `PHP_UPLOAD_MAX_FILESIZE`, `PHP_POST_MAX_SIZE`, `PHP_MEMORY_LIMIT`, `FOXDESK_CRON_INTERVAL`, `FOXDESK_UPGRADE_BACKUP` |
| `config.php` | Připojení aplikace | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| `config.php` | Identita aplikace a proxy | `APP_NAME`, `APP_URL`, `SECRET_KEY`, `TRUST_PROXY`, `APP_DEBUG` |
| `config.php` | Přílohy | `UPLOAD_DIR`, `MAX_UPLOAD_SIZE` |

Při čisté instalaci nechte `config.php` vytvořit průvodcem. Po obnově zachovejte původní `SECRET_KEY`. Hodnoty databázových proměnných v Compose inicializují nový svazek `db`. Při změně přihlašovacích údajů existující databáze upravte účty v MariaDB i připojení v `config.php`.

Úlohy na pozadí nastavíte v **Settings > System > Background tasks**. V Compose je spouští služba `cron`. Na hostingu bez plánovače lze využít pseudo-cron při návštěvě aplikace. Pro systémový cron jsou v [instalačním návodu](../INSTALL.md#cron-jobs-setup) uvedené CLI příkazy.

## Build a lokální testy

Použijte Node.js 20 a PHP CLI 8.2 jako v CI. Příkazy níže jsou pro POSIX shell. Ve Windows použijte Git Bash, `npm.cmd` a podle potřeby `npm_config_script_shell` s cestou k POSIX shellu. Pro PHP mimo `PATH` nastavte `PHP_BIN` na cestu k binárce. Testy obnovy potřebují `zip`, test migrací s kontrolou konců řádků používá `pdo_sqlite` a databázové integrace používají `pdo_mysql`.

```bash
npm ci
npm run build:css
npm run lint:php
npm run test:docs
npm run test:i18n
npm run test:shared-behavior
npm run test:db-clock
npm run test:core-parity
npm run test:security-boundary
npm run test:operations
```

- `test:css-build` ověří, že minifikované CSS odpovídá `theme.css`. Build zpracovává CSS, aplikace běží přímo v PHP.
- Další sady vypíše `npm run`. Samostatné PHP testy spouštějte po jednom přes `sh bin/run-php.sh tests/<soubor>-test.php`, aby každá sada dostala vlastní proces. Čisté Node testy lze spouštět přes jejich npm skript nebo `node --test tests/<soubor>.test.js`.
- Pro databázový čas a rate limit slouží `tests/db-clock-integration-test.php` a `tests/api-token-rate-limit-integration-test.php`. Nastavte příslušný přepínač `FOXDESK_DB_CLOCK_INTEGRATION` nebo `FOXDESK_RATE_LIMIT_INTEGRATION` na `1` a použijte výhradně testovací databázi nastavenou přes `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. Testy upravují nebo mažou testovací tabulky.
- Pro `tests/schema-migration-integration-test.php` nastavte `FOXDESK_SCHEMA_INTEGRATION` na `1` a připojení přes `FOXDESK_SCHEMA_DB_HOST`, `FOXDESK_SCHEMA_DB_PORT`, `FOXDESK_SCHEMA_DB_USER`, `FOXDESK_SCHEMA_DB_PASS`. Test vytváří a na konci odstraňuje vlastní databázi. Z čistého self-hosted klonu ho spusťte přímo přes `bin/run-php.sh`.
- `npm run e2e:install` instaluje Chromium. `npm run e2e` s funkčním Docker Engine vytvoří dočasné kontejnery a provede instalaci s testovacími daty. Nastavte unikátní `E2E_RUN_ID` a volný `E2E_PORT`. Příkaz `npm run e2e -- --list` pouze vypíše shromážděné scénáře. Samostatné Node integrace s `FOXDESK_TEST_URL` a `FOXDESK_TEST_CONTAINER` potřebují připravenou lokální testovací instanci.

Při sestavování vlastního aktualizačního ZIPu použijte `npm run release:package` v prostředí s nástroji Bash, `rsync` a `zip`. Balíček vznikne pod `build/`. Pro doprovodný SHA‑256 soubor skript používá `shasum` nebo `sha256sum`.

## Aktualizace

1. Zkontrolujte verzi a poznámky k vydání. Pořiďte kompletní zálohu podle postupu níže.
2. V **Settings > System > Updates** zkontrolujte dostupné vydání a potvrďte instalaci. Při ruční aktualizaci nahrajte aktualizační ZIP. Aplikace balíček nejdřív ověří a vyžádá si potvrzení před použitím.
3. Při aktualizaci přes image změňte pin v `FOXDESK_IMAGE` a opakujte `docker compose pull app cron` a `docker compose up -d --no-build`. U vlastního buildu zvolte zdroje požadovaného vydání a použijte `docker compose build --pull` a `docker compose up -d`.
4. Při startu s novějším obrazem entrypoint zálohuje soubory a databázi do `backups/docker-upgrades/`, nahradí soubory vydání a spustí `upgrade.php`. Pokud záloha selže, ponechá starou verzi souborů a zapíše chybu do logu. Při spuštění staršího obrazu zůstane ve svazku `app` nainstalovaná novější verze. Nový obraz aktualizuje i PHP, Apache a systémové balíčky v kontejneru.
5. Ověřte verzi a výsledek kontroly po aktualizaci v **Settings > System**, načtení `index.php?page=health` a dostupnost dříve uložených dat. V Dockeru prověřte i `docker compose ps` a logy aplikace a plánovače.

## Zálohy a obnova

**Settings > System > Backups** nabízí body obnovy aplikace. Zkontrolujte, že kromě `files.zip` vznikl použitelný `database.sql`. Záloha souborů vydání vynechává `config.php`, `uploads/`, `storage/` i starší zálohy. Tlačítko **Restore** vrací soubory vydání. Databázi obnovte zvlášť ze zálohy odpovídající verze.

Pro úplnou zálohu uložte databázový dump, `config.php`, `uploads/`, `storage/` a údaj o přesné verzi vydání. Pokud používáte vlastní cestu pro přílohy, zahrňte i ji. Kopii uložte mimo server a obnovu ověřte na samostatné instanci. Databázi a soubory zálohujte v jednom servisním okně bez zápisů od uživatelů a úloh na pozadí.

Příklad pro výchozí svazky Compose v POSIX shellu. Vytvořené soubory zálohy obsahují citlivá data a patří do privátního úložiště.

```bash
umask 077
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' > foxdesk-db.sql
docker compose exec -T app tar -czf - -C / var/www/html/uploads var/www/html/storage var/lib/foxdesk/config > foxdesk-data.tar.gz
```

Před obnovou připravte nový izolovaný Compose projekt se stejnou verzí FoxDesku, kompatibilní MariaDB a odpovídajícím databázovým připojením. Spusťte služby `db` a `app`, vyčkejte na databázi a nechte plánovač vypnutý. Importujte SQL do prázdné databáze, obnovte archiv a restartujte aplikaci.

```bash
docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < foxdesk-db.sql
docker compose exec -T app tar -xzf - -C / < foxdesk-data.tar.gz
docker compose restart app
```

Zkontrolujte zdraví aplikace, přihlášení, záznamy a otevření příloh. Plánovač zapněte po ověření obnovené instance. Stejný princip platí pro PHP hosting s importem databáze přes nástroj poskytovatele a obnovou souborů včetně jejich oprávnění.

## Časté problémy

| Příznak | Co ověřit |
| --- | --- |
| Compose nic nespustí | Ověřte Docker Engine příkazem `docker version` a konfiguraci příkazem `docker compose config --quiet`. Povinné databázové proměnné musí být nastavené. |
| Instalace hlásí chybu databáze | V Compose použijte hostitele `db` a ověřte `docker compose logs db`. Na hostingu použijte hostitele a port od poskytovatele. Ověřte práva databázového uživatele. |
| Místní HTTP se přesměruje na HTTPS | Použijte `localhost` nebo `127.0.0.1`. U veřejné adresy zkontrolujte HTTPS proxy, důvěru v předávané hlavičky a `APP_URL`. |
| Příloha se nenahraje | Zkontrolujte `MAX_UPLOAD_SIZE`, PHP limity `upload_max_filesize` a `post_max_size`, limit proxy a zápis do cílového adresáře. |
| Docker po aktualizaci hlásí nedokončenou migraci | Prohlédněte `docker compose logs app`, ověřte zálohu, odstraňte příčinu a spusťte `docker compose exec -u www-data app php upgrade.php`. |
| HTTP 500 nebo prázdná stránka | Ověřte PHP verzi, rozšíření, oprávnění a chybu v logu PHP nebo `docker compose logs app`. |
| Úlohy na pozadí neběží | V Compose prohlédněte `docker compose logs cron`. Na hostingu zkontrolujte nastavení pseudo-cronu nebo systémový plánovač. |
| Je třeba zastavit Compose | `docker compose down` zachová svazky. Přepínač `-v` svazky smaže včetně databáze a příloh. |
