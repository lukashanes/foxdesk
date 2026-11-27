# HTTP API FoxDesku self-hosted

Přehled odpovídá self-hosted vydání 0.3.153. Centrální vstup je
`/index.php?page=api&action=<akce>`. V tabulkách je uvedena hodnota `action`.
Pokud je FoxDesk instalován v podadresáři, cesta začíná tímto podadresářem.
Registr akcí je v [routeru](../includes/api/router.php), rozsahy tokenů v [autentizaci](../includes/auth.php).

## Přístup, odpovědi a limity

- API vyžaduje přihlášenou webovou relaci nebo platný `Authorization: Bearer <token>`.
  Tokeny jsou povolené u akcí s uvedeným rozsahem a u `agent-docs`. Handler dále
  kontroluje roli vlastníka tokenu a oprávnění k záznamu. U `agent-*`, `app-*`
  a `upload` má platný token přednost před současnou relací.
- `CSRF` v tabulkách znamená `X-CSRF-Token` nebo formulářové pole
  `csrf_token` s hodnotou z `csrf-token` pro stejnou relaci. U akcí s tímto
  požadavkem a podporou tokenu nahrazuje CSRF autentizace Bearer.
- Vstup `JSON` znamená UTF-8 a tělo `application/json`. Vstup `form`
  znamená hodnoty čtené z `$_POST`. Parametry u GET jsou v query stringu.
  Hvězdička u metody označuje obvyklou metodu volání bez výslovné kontroly
  metody v handleru.
- Standardní úspěch je `{"success":true,...}`. Akce App vracejí
  `data`, `meta` (verze schématu, čas a typ zdroje) a `errors:[]`.
  Některé poskytují původní pole i v kořeni odpovědi. Výjimky z tohoto formátu
  jsou uvedené v tabulkách.
- Standardní chyba obsahuje `success:false` a `error`. Typicky jde o
  `400` pro neplatný požadavek, `401` pro chybějící přihlášení, `403`
  pro oprávnění nebo CSRF, `404` pro neznámou akci nebo nedostupný záznam,
  `405` pro nesprávnou metodu, `409` pro konflikt, `422` pro neplatná
  data včetně UTF-8 a JSON a `500` pro interní chybu. CSRF odpovědi mohou mít
  vlastní formát. Překročení PHP `post_max_size` může vrátit `413`. Funkce
  vyžadující novější databázové schéma vrací `503` s chybou
  `database_upgrade_required`.
- Token má výchozí limit **120 požadavků za 60 sekund**. Hodnota
  `FOXDESK_API_TOKEN_RATE_LIMIT_PER_MINUTE` limit nastaví, hodnota
  nejvýše nula ho vypne. Odpovědi zahrnují `X-RateLimit-Limit` a
  `X-RateLimit-Remaining`, při `429` také `Retry-After`.
- Pro zápisy s tokenem podporuje router `Idempotency-Key` nebo
  `X-Idempotency-Key` (nejvýš 128 znaků, písmena ASCII, číslice, `_`, `.`,
  `:`, `-`). Klíč se váže k tokenu, akci a otisku požadavku. Uložené
  úspěšné odpovědi se uchovávají 30 dní a opakování vrací
  `X-Idempotent-Replay: true`. Jiné tělo se stejným klíčem nebo probíhající
  požadavek může vrátit `409`, nedostupná rezervace `503`. Po chybě
  může klient opakovat původní požadavek pod stejným klíčem podle typu chyby.

## API pro agenty

`S` označuje role agent nebo správce. Sloupec **Scope** platí pro Bearer.
Konkrétní výběr práce, strukturované datum a pravidla schvalování popisují
[Agent API Quickstart](AGENT_API_QUICKSTART.md) a [Agent Ticket Workflow](AGENT_TICKET_WORKFLOW.md).

| Akce | Metoda | Role a scope | Vstup | Výstup, chyby a limity |
| --- | --- | --- | --- | --- |
| `agent-docs` | GET* | Relace nebo platný token | `instruction_language` | Kontrakt API, identita a dostupné nástroje a pokyny podle účtu. |
| `agent-me` | GET* | `work:read` | — | `user` s rolí, jazykem a příznakem AI. |
| `agent-list-statuses` | GET* | `tickets:read` | — | `statuses` včetně ID, názvu, barvy a výchozího stavu. |
| `agent-list-priorities` | GET* | `tickets:read` | — | `priorities` včetně ID, názvu a barvy. |
| `agent-list-users` | GET* | S, `users:read` | `role`, `exclude_ai` | `users` v rozsahu viditelnosti účtu. |
| `agent-list-tickets` | GET* | S, `tickets:read` | `status`, `priority` (ID/název), `search`, `user_id`, `assignee_id`, `sort`, `limit`, `offset` | `tickets`, `total`, `limit`, `offset`. Limit 1–200, výchozí 50. |
| `agent-get-ticket` | GET* | S, `tickets:read` | `hash` nebo `id` | Detail tiketu, komentáře, přílohy, aktivita a workflow. Chybějící identifikátor `422`, nenalezený tiket `404`. |
| `agent-create-ticket` | POST | S, `tickets:write` | JSON `title`, volitelně `description`, `type`, `user_id`, `status_id`, `priority_id`, `organization_id`, `assignee_id`, `due_date`, `tags`, `skip_notification` | Identifikátory a URL tiketu. Nepřípustný vlastník, klient či řešitel `403`, chybějící stav/priorita `404`, časová pole nebo neplatný termín `422`. |
| `agent-add-comment`, `agent-add-update`, `agent-add-work-entry` | POST | S, `tickets:read` + `comments:write`, při zápisu času i `time:write` | JSON `ticket_id` nebo `ticket_hash`, `content`, `is_internal`, `skip_notification`. Pracovní záznam přijímá `duration_minutes`, `worked_on`, `time_precision`, dvojici `started_at`/`ended_at` nebo `manual_date`, `manual_start_time`, `manual_end_time`. Volitelný `status_id` vyžaduje `tickets:write` a `expected_revision`. | `comment_id`, případně `time_entry_id`, workflow a výsledek notifikací. `agent-add-update` je čistý komentář, `agent-add-work-entry` vyžaduje čas. Neplatná kombinace `422`, konflikt revize `409`. |
| `agent-delete-comment` | POST | S, `tickets:read` + `comments:write` a oprávnění ke komentáři | JSON `comment_id` nebo `id` | `ticket_id`, `comment_id`, `deleted`, `undo_token`, `undo_seconds`. Okno pro obnovu je 10 sekund. Neplatné ID `422`, omezení tiketu/komentáře `403`. |
| `agent-log-time` | POST | S, `time:write` | JSON `ticket_id` nebo `ticket_hash`, kladné `duration_minutes`, datum/přesnost nebo časy jako u pracovního záznamu, volitelně `summary`, `source`, `is_billable`, pro správce `billable_rate` | ID uloženého záznamu a délka. Chybějící datum nebo neplatné časy `422`. |
| `agent-update-work-entry` | POST | S, `tickets:read` + `time:write` a oprávnění k záznamu. Pro token povinný `Idempotency-Key`. | JSON `time_entry_id` nebo `entry_id`, aktualizované časové údaje, volitelně `expected_duration_minutes`, `summary` nebo `time_summary` | Aktualizovaný záznam a změny v historii. Nenalezený záznam `404`, konflikt délky `409`, běžící časovač nebo neplatná data `422`. |
| `agent-plan-work-log` | POST | S, `tickets:read`, `tickets:write`, `comments:write`, `time:write` | JSON `structure` (`one_ticket`, `multiple_tickets`), `allocation_basis` (`actual`, `approved_total`), `total_minutes`, `tickets[]` včetně `entries[]` | `plan`, `plan_hash`, `preview` platné 30 minut. Délka 1–1 440 minut na položku a 1–525 600 minut celkem. Neplatná alokace či odkaz `422`. |
| `agent-apply-work-log-plan` | POST | S, stejné rozsahy jako pro plán | JSON `plan`, `plan_hash`, `confirm:true` | `applied`, tikety s ID komentářů a časů, `total_minutes`, `verification`. Neplatný nebo prošlý podpis plánu `409`, nesouhlasná identita `403`, chybějící potvrzení `422`. |
| `agent-update-status` | POST | S, `tickets:read` + `tickets:write`, CSRF | JSON `ticket_id` nebo `ticket_hash`, `status_id` nebo `status`, volitelně `expected_revision`, `skip_notification` | Nový stav a workflow. Neznámý stav `422`, zastaralá revize `409`. |
| `agent-ticket-workflow` | POST | S, `tickets:read` + `tickets:write`, podmíněně `time:write` a `comments:write`, CSRF | JSON nebo form, stejná data jako u `ticket-workflow` | Workflow, stav časovače, notifikace. Při konfliktu `409` může odpověď obsahovat aktuální `workflow`. |
| `agent-delete-ticket-preflight` | GET* | Oprávnění trvale smazat tiket, `tickets:read` | `hash` nebo `ticket_id` | `preflight` s rozsahem a potvrzením. Nedostupný tiket `404`. |
| `agent-delete-ticket-permanently` | POST | Oprávnění trvale smazat tiket, `tickets:read` + `delete:write`, povinný `Idempotency-Key` | JSON `ticket_id`, `confirmation`. Volitelná pole `delete_comments`, `delete_time_entries`, `delete_attachments` akceptují pouze `true` nebo `1`. | Výsledek trvalého smazání. Částečné mazání či neplatné potvrzení `422`. |

## App API

Sloupec **Scope a přístup** uvádí rozsah Bearer a případné další podmínky
pro oba typy přihlášení. Běžný identifikátor tiketu je `hash` nebo `id`,
případně `ticket_hash` nebo `ticket_id`. Neznámý tiket vrací `404`,
přístup mimo oprávnění `403`.

| Akce | Metoda | Scope a přístup | Vstup | Hlavní pole `data`, chyby a limity |
| --- | --- | --- | --- | --- |
| `app-shell` | GET | `work:read` | — | `app_shell` s identitou, navigací a schopnostmi. |
| `app-home` | GET | `work:read` | `limit` | `app_shell`, `home` s viditelnými frontami. Limit 1–20, výchozí 5. |
| `app-ticket-list` | GET | `tickets:read` | `view`/`work_view`, `search`, `source`, `due_date_filter`, `tag`/`tags`, `view_mode`, `organization_id`, `priority_id`, `type_id`, `status_id`, `user_id`, `assigned_to` (ID nebo `me`), `sort`, `limit`, `offset` | `tickets`, pohledy, počty a filtry. Limit 1–100, výchozí 25. |
| `app-ticket-detail` | GET | `tickets:read` | Identifikátor tiketu | Tiket, viditelné komentáře, přílohy, časové údaje, akce a workflow. |
| `app-ticket-actions` | GET | `tickets:read` | Identifikátor tiketu | `ticket`, `actions`. |
| `app-create-ticket` | POST | `tickets:write`, CSRF | JSON `title`, volitelně `description`, `type`, `user_id`, `status_id`, `priority_id`, `organization_id`, `assignee_id`, `due_date`, `tags` | Identifikátory a URL tiketu. Chybějící stav/priorita `404`, neplatný název či termín `422`. |
| `app-add-comment` | POST | `comments:write`, CSRF | JSON s identifikátorem tiketu, `content`, volitelně `is_internal` (pro S) | `comment_id`. Prázdný obsah `422`. |
| `app-attachment-metadata` | GET | `attachments:read` a viditelnost přílohy | `attachment_id` nebo `id` | `attachment` s metadaty a odkazem; obsah souboru má vlastní cestu. |
| `app-ticket-timer` | GET | `time:read` a oprávnění k času | Identifikátor tiketu | `ticket`, `timer`. |
| `app-ticket-timer-action` | POST | S, `time:write`, CSRF | JSON s identifikátorem tiketu a `action` (`start`, `pause`, `resume`, `stop`, `discard`) | `ticket`, `timer`, `action`, `result`. Start na hotovém nebo archivovaném tiketu `409`, neznámá operace `422`. |
| `app-log-time` | POST | S, `time:write`, CSRF | JSON s identifikátorem tiketu, `duration_minutes` 1–1440, volitelně `worked_on`, `started_at`, `ended_at`, `time_precision`, `summary`, `is_billable` | Identifikátor a časové údaje. Neplatná délka, datum nebo nesoulad údajů `422`. |
| `app-update-time-entry` | POST | S, `tickets:read` + `time:write`, oprávnění upravit záznam, CSRF | JSON `time_entry_id`, `entry_id` nebo `id`, pole času k úpravě, volitelně `summary`, `is_billable`, `comment_id` | Aktualizovaný čas. Běžící záznam `409`, komentář jiného tiketu nebo vadný čas `422`. |
| `app-client-overview` | GET | S a povolený klient, `clients:read` | `organization_id` nebo `client_id`, `view` | `client`, `view`, `counts`, `tickets`, `contacts`, `time`, `links`. Nejvýš osm tiketů, klient nenalezen `404`. |
| `app-reporting-review` | GET | `reports:read` a oprávnění k času nebo správce | `time_range`, `from_date`, `to_date`, `organizations`, `agents`, `tags`, `limit`, `offset` | `entries`, `totals` za vrácenou stránku a `pagination`. Mimo roli správce jen vlastní záznamy. Limit 1–250, výchozí 100. |
| `app-notifications` | GET | `notifications:read` | `limit`, `offset`, `include_resolved=1` | Notifikace, součty a stránkování účtu. Limit 1–100, výchozí 25. |
| `app-notifications-summary` | GET | `notifications:read` | `limit` | Počet nepřečtených a aktuální položky. Limit 1–25, výchozí 10. |
| `app-notification-read-state` | POST | `notifications:write`, CSRF | JSON `scope` (`notification`, `ticket`, `all`), `notification_id`/`id` nebo `ticket_id`, `is_read` (výchozí true) | Nový stav a počet nepřečtených. Skupiny lze jen označit za přečtené, jinak `422`. |
| `app-notification-review` | POST | Relace, CSRF | JSON `notification_id` a boolean `is_resolved` | `notification_id`, `is_resolved`. Chybějící záznam `404`, nedostupná podpora `409`. |

## Webové akce

Tyto akce používají relaci. `upload` podporuje i Bearer s rozsahem
`attachments:write`. **S** znamená agent nebo správce. **A** znamená správce.
Zápisy tiketu dále vyžadují příslušná oprávnění k prohlížení či úpravě.

| Akce | Metoda | Přístup | Vstup | Výstup, chyby a limity |
| --- | --- | --- | --- | --- |
| `csrf-token` | GET* | Relace | — | `token` z relace. |
| `search_users` | GET* | S | `q` | Ploché pole s ID, jménem a e-mailem. Nejméně 2 bajty dotazu, nejvýš 10 výsledků. |
| `search-tickets` | GET* | Relace | `q` | `tickets` s kódem, názvem, stavem a URL. Nejméně 2 bajty dotazu, nejvýš 8 výsledků. |
| `global-search` | GET* | Relace | `q`, `limit` | `query`, `sections`, `total`. Dotaz od 2 znaků, limit 1–20 na sekci, výchozí 6. |
| `get_user_tickets` | GET* | Relace | `limit` | `tickets` viditelné účtu. Výchozí 20, horní hranice 50. |
| `get_active_timer`, `get_active_timers` | GET* | Relace | — | `active` a detail jednoho časovače, nebo pole `timers` uživatele. |
| `get-tags` | GET* | Relace | — | `tags` z viditelných tiketů pro našeptávač. |
| `get-timeline` | GET* | Oprávnění k tiketu a historii | `ticket_id` | `events`. Bez viditelnosti nebo oprávnění `403`. |
| `ticket-workflow` | POST | S, CSRF | JSON nebo form `ticket_id` nebo `ticket_hash`, `operation` (`status`, `complete`, `reopen`, `claim`, `assign`), `expected_revision`, podle operace `status_id`, `assignee_id`, `handoff_note`, volitelně `skip_notification` | `workflow`, `timer_stopped`, `previous_status_id`, `handoff_comment_id`, `notifications`. Konflikt revize `409`. |
| `change-status` | POST | CSRF, oprávnění měnit stav | form `ticket_id`, `status_id` | Nový stav a případné zastavení časovače. Neplatný stav nebo tiket `404`. |
| `quick-start` | POST | S, CSRF | — | Vytvoří nový tiket a spustí časovač. `ticket_id`, `entry_id`, `started_at`, `url`, `message`. Nedostupné měření času `400`. |
| `start-timer`, `pause-timer`, `resume-timer`, `stop-timer`, `discard-timer` | POST | S, CSRF | form `ticket_id` | Příznak úspěchu, podle operace ID, stav či délka časovače. Neplatný stav časovače `400`. |
| `cancel-ticket` | POST | S, CSRF a viditelnost tiketu | form `ticket_id` | `archived:true`, `message`. Existující komentáře nebo dokončené časy `400`, nedostupné archivování `409`. |
| `quick-log-time` | POST | S, CSRF | form `ticket_id`, `duration_minutes` 1–1440, volitelně `note` | `entry_id`, délka a zpráva. Konec je aktuální čas. Neplatná délka `400`. |
| `update-time-inline` | POST | A, CSRF | JSON `entry_id`, `entry_date`, `start_time`, `end_time` | Upravený čas a délka. Neplatné nebo neúplné časy `400`. |
| `delete-time-entry`, `restore-time-entry` | POST | S, CSRF, oprávnění k záznamu | form `entry_id` při smazání, `undo_token` při obnově | Výsledek a při smazání token pro obnovu, pokud je dostupný. |
| `edit-comment`, `delete-comment`, `restore-comment` | POST | CSRF, oprávnění ke komentáři | form `comment_id`, při editaci `content`, při obnově `undo_token` | Výsledek editace/smazání/obnovy, při smazání případný token pro obnovu. |
| `delete-attachment`, `restore-attachment` | POST | CSRF, oprávnění k příloze | form `attachment_id` nebo při obnově `undo_token` | Výsledek a při smazání token pro obnovu. |
| `permanent-delete-ticket-preflight`, `permanent-delete-ticket` | POST | CSRF, oprávnění trvale smazat tiket | form `ticket_id`, u smazání `confirmation` | Přehled rozsahu nebo výsledek trvalého smazání. |
| `quick-assign`, `quick-behalf`, `quick-due-date`, `quick-priority`, `quick-type`, `quick-company`, `quick-subject` | POST | S, CSRF | form `ticket_id` a po řadě `assignee_id`, `created_for_user_id`, `due_date`, `priority_id`, `type`, `organization_id`, `title` | Uložené pole a data pro překreslení prvku. Vadná hodnota `400` nebo `422`, nedostupný tiket `404`. |
| `quick-create-ticket` | POST | S, CSRF | form `title`, volitelně `type`, `assignee_id`, `organization_id`, `priority_id`, `status_id` | Identifikátor a URL nového tiketu. |
| `update-tags` | POST | S, CSRF | form `ticket_id`, `tags` | Uložené `tags`. |
| `upload` | POST | Relace s CSRF nebo `attachments:write` | multipart `file` a `ticket_id`. Pro obrázek editoru před založením tiketu `purpose=editor-image`. | `file` včetně URL a u tiketu `attachment_id`. Limit `max_upload_size` 1–100 MiB, výchozí 10 MiB, případně nižší limit PHP. Pro editor JPEG, PNG, GIF, WebP. Chyba souboru `400`, chybějící tiket `404`, neoprávněný tiket `403`. |
| `save-dashboard-layout` | POST* | Relace | JSON `layout` jako pole nebo objekt s `order`, `hidden`, `sizes` | `saved`; neplatný formát `400`. Velikosti `half` a `full`. |
| `work-time-dashboard` | GET | S, týmový souhrn pro A | `period` (výchozí `this_month`), `time_scope`, časové filtry, `days` | `period`, `scope`, finanční součty, lidé, klienti, dny, upozornění, aktivita a tikety. `days` 1–62, výchozí 14. |
| `get-notifications`, `get-notification-count` | GET* | Relace | Výpis má `limit`, `offset`, `include_resolved=1` | `notifications` a počty, nebo `unread_count`. Limit 1–100, výchozí 50. |
| `mark-notification-read`, `mark-ticket-notifications-read`, `mark-all-notifications-read` | POST | Relace, CSRF | form `notification_id`, `ticket_id` nebo bez vlastního pole pro všechny | Aktualizované počty. Chybějící ID `422`. |
| `push-subscribe`, `push-unsubscribe` | POST | Relace | JSON `endpoint`, při registraci také `p256dh` a `auth` | JSON `success`; chybějící endpoint `400`. |
| `push-vapid-key`, `push-notifications` | GET* | Relace | — | `publicKey` nebo nejvýš tři nepřečtené Web Push položky v `notifications`. |
| `org-add-user`, `org-remove-user` | POST* | A, CSRF | JSON `org_id`, `user_id` | Při přidání `user` včetně `organization_ids`, při odebrání příznak úspěchu. Neplatné ID nebo záznam `400`. |
| `reorder-statuses`, `reorder-priorities`, `reorder-ticket-types` | POST | A, CSRF | JSON neprázdné pole `order` s ID | Příznak úspěchu. Chybný vstup `400`. |
| `move-status-up`, `move-status-down`, `move-priority-up`, `move-priority-down` | POST | A, CSRF | Query `id` | Výsledek posunutí, mez pořadí může vrátit `success:false` se zprávou. Nenalezený stav `404`. |
| `allowed-senders-list` | GET* | A | — | `senders`. |
| `allowed-senders-add`, `allowed-senders-delete`, `allowed-senders-toggle` | POST | A, CSRF | JSON `type` (`email` nebo `domain`), `value`, volitelné `user_id` při přidání, jinak `id` | `message` a příznak úspěchu. Vadný vstup `400`, nenalezený přepínaný záznam `404`. |
| `test-smtp` | POST | A, CSRF | JSON `host`, `port`, `user`, `pass`, `encryption` (výchozí port 587, TLS) | `success`, `message` s výsledkem pokusu o SMTP spojení. |
| `check-for-updates` | POST | A, CSRF | — | `update_available` a údaje o verzi, případně chyba kontroly `500`. |
| `download-remote-update` | POST | A, CSRF | — | `downloaded`, `version`, `changelog`, `message`. Pracuje s uloženou informací o vydání a připraví ZIP v relaci. Nevhodný balík `400`, selhání stahování `500`. |
| `dismiss-update-notice` | POST | A, CSRF | JSON nebo form `version` | `dismissed`, `version`; bez verze `400`. |

## Provozní a souborové cesty

| Cesta | Metoda a přístup | Vstup | Výstup a limity |
| --- | --- | --- | --- |
| `/index.php?page=health` | GET*, veřejná | — | JSON `status`, `version`, `db`, `php`, `timestamp`. Databázová chyba se pozná podle `status:error` a `db:false`. |
| `/index.php?page=cron` | GET*, tajná hodnota instalace | Query `token` | Textový výsledek běhu. Neplatný token `403`, již běžící zámek vrací `Locked` po dobu nejvýše 300 sekund od zápisu. |
| `/attachment.php` | GET*, relace s přístupem nebo platný odkaz ke sdílenému tiketu | `f`, volitelně `share_token` | Stažení souboru. Vadný identifikátor `400`, chybějící oprávnění `403`, nenalezený soubor `404`. |
| `/image.php` | GET*, podle viditelnosti souboru | `f`, u sdílení `share_token` nebo `token` | Obrazová odpověď. Vadný identifikátor `400`, chybějící oprávnění `403`, nenalezený soubor `404`. |
| `/manifest.php`, `/pwa-icon.php` | GET*, veřejné po konfiguraci instalace | Pro ikonu `s` | PWA manifest a ikona. Rozměr ikony 48–1024 px, výchozí 512 px. Bez konfigurace `404`. |

Webové stránky s HTML a klasickými formuláři mají vlastní navigaci.
Přenos do samostatné SaaS edice je popsán v [návodu k migraci](../SELF_HOSTED_TO_SAAS_MIGRATION.md).
