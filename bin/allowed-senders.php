<?php
/**
 * CLI helper: manage allowed inbound senders.
 *
 * Commands:
 *   php bin/allowed-senders.php list
 *   php bin/allowed-senders.php add --type=email --value=user@example.com [--user-id=1] [--inactive]
 *   php bin/allowed-senders.php add --type=domain --value=example.com
 *   php bin/allowed-senders.php import --file=senders.csv
 *   php bin/allowed-senders.php disable --id=10
 *   php bin/allowed-senders.php disable --type=email --value=user@example.com
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run in CLI mode.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

if (!file_exists(BASE_PATH . '/config.php')) {
    fwrite(STDERR, "Missing config.php. Install/configure the app first.\n");
    exit(1);
}

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/email-ingest-functions.php';

email_ingest_ensure_schema();

$command = $argv[1] ?? 'help';
$opts = cli_parse_long_options($argv, 2);

switch ($command) {
    case 'list':
        $where = !empty($opts['active-only']) ? 'WHERE active = 1' : '';
        $rows = db_fetch_all("SELECT id, type, value, user_id, active, created_at FROM allowed_senders {$where} ORDER BY active DESC, type, value");
        if (empty($rows)) {
            echo "No allowed senders found.\n";
            exit(0);
        }
        foreach ($rows as $row) {
            echo implode("\t", [
                $row['id'],
                $row['type'],
                $row['value'],
                'user_id=' . ($row['user_id'] ?? 'null'),
                'active=' . ((int) $row['active']),
                $row['created_at'],
            ]) . PHP_EOL;
        }
        exit(0);

    case 'add':
        $type = strtolower(trim((string) ($opts['type'] ?? '')));
        $value = strtolower(trim((string) ($opts['value'] ?? '')));
        $user_id = isset($opts['user-id']) && (int) $opts['user-id'] > 0 ? (int) $opts['user-id'] : null;
        $active = empty($opts['inactive']) ? 1 : 0;

        if (!in_array($type, ['email', 'domain'], true)) {
            fwrite(STDERR, "--type must be 'email' or 'domain'.\n");
            exit(2);
        }

        if ($type === 'email') {
            $value = email_ingest_normalize_email($value);
            if ($value === '') {
                fwrite(STDERR, "Invalid email address.\n");
                exit(2);
            }
        } else {
            $value = ltrim($value, '@');
            if ($value === '' || strpos($value, '.') === false) {
                fwrite(STDERR, "Invalid domain value.\n");
                exit(2);
            }
        }

        db_query(
            "INSERT INTO allowed_senders (type, value, user_id, active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                active = VALUES(active),
                updated_at = NOW()",
            [$type, $value, $user_id, $active]
        );

        echo "Saved allowed sender: {$type} {$value}\n";
        exit(0);

    case 'disable':
        if (!empty($opts['id'])) {
            $id = (int) $opts['id'];
            db_query("UPDATE allowed_senders SET active = 0, updated_at = NOW() WHERE id = ?", [$id]);
            echo "Disabled allowed sender id={$id}\n";
            exit(0);
        }

        $type = strtolower(trim((string) ($opts['type'] ?? '')));
        $value = strtolower(trim((string) ($opts['value'] ?? '')));
        if (!in_array($type, ['email', 'domain'], true) || $value === '') {
            fwrite(STDERR, "Use disable with --id OR with --type and --value.\n");
            exit(2);
        }

        db_query("UPDATE allowed_senders SET active = 0, updated_at = NOW() WHERE type = ? AND value = ?", [$type, $value]);
        echo "Disabled allowed sender: {$type} {$value}\n";
        exit(0);

    case 'import':
        $file = (string) ($opts['file'] ?? '');
        if ($file === '' || !file_exists($file)) {
            fwrite(STDERR, "CSV file not found. Use --file=/path/to/senders.csv\n");
            exit(2);
        }

        $h = fopen($file, 'r');
        if (!$h) {
            fwrite(STDERR, "Unable to open file: {$file}\n");
            exit(2);
        }

        $header = fgetcsv($h);
        if (!$header) {
            fclose($h);
            fwrite(STDERR, "Empty CSV file.\n");
            exit(2);
        }

        $header_map = [];
        foreach ($header as $i => $col) {
            $normalized = strtolower(trim((string) $col));
            if ($i === 0) {
                $normalized = ltrim($normalized, "\xEF\xBB\xBF");
            }
            $header_map[$normalized] = $i;
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($h)) !== false) {
            $raw_email = '';
            $raw_domain = '';
            $raw_type = '';
            $raw_active = '';
            $raw_user_id = '';
            $raw_value = '';

            if (isset($header_map['email'])) {
                $raw_email = trim((string) ($row[$header_map['email']] ?? ''));
            }
            if (isset($header_map['domain'])) {
                $raw_domain = trim((string) ($row[$header_map['domain']] ?? ''));
            }
            if (isset($header_map['type'])) {
                $raw_type = strtolower(trim((string) ($row[$header_map['type']] ?? '')));
            }
            if (isset($header_map['active'])) {
                $raw_active = strtolower(trim((string) ($row[$header_map['active']] ?? '')));
            }
            if (isset($header_map['user_id'])) {
                $raw_user_id = trim((string) ($row[$header_map['user_id']] ?? ''));
            }
            if (isset($header_map['value'])) {
                $raw_value = trim((string) ($row[$header_map['value']] ?? ''));
            }

            $type = $raw_type;
            $value = '';
            if ($type === 'email' || ($type === '' && $raw_email !== '')) {
                $type = 'email';
                $value = email_ingest_normalize_email($raw_email);
            } elseif ($type === 'domain' || ($type === '' && $raw_domain !== '')) {
                $type = 'domain';
                $value = strtolower(ltrim($raw_domain, '@'));
            } elseif ($type === '' && $raw_value !== '') {
                if (strpos($raw_value, '@') !== false) {
                    $type = 'email';
                    $value = email_ingest_normalize_email($raw_value);
                } else {
                    $type = 'domain';
                    $value = strtolower(ltrim($raw_value, '@'));
                }
            }

            if (!in_array($type, ['email', 'domain'], true) || $value === '') {
                $skipped++;
                continue;
            }

            $active = 1;
            if ($raw_active !== '') {
                if (in_array($raw_active, ['0', 'false', 'no', 'off'], true)) {
                    $active = 0;
                }
            }

            $user_id = null;
            if ($raw_user_id !== '' && ctype_digit($raw_user_id)) {
                $user_id = (int) $raw_user_id;
            }

            db_query(
                "INSERT INTO allowed_senders (type, value, user_id, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    active = VALUES(active),
                    updated_at = NOW()",
                [$type, $value, $user_id, $active]
            );
            $imported++;
        }

        fclose($h);
        echo "Import done. imported={$imported} skipped={$skipped}\n";
        exit(0);

    default:
        echo "Usage:\n";
        echo "  php bin/allowed-senders.php list [--active-only]\n";
        echo "  php bin/allowed-senders.php add --type=email --value=user@example.com [--user-id=1] [--inactive]\n";
        echo "  php bin/allowed-senders.php add --type=domain --value=example.com\n";
        echo "  php bin/allowed-senders.php import --file=senders.csv\n";
        echo "  php bin/allowed-senders.php disable --id=10\n";
        echo "  php bin/allowed-senders.php disable --type=email --value=user@example.com\n";
        exit(1);
}

/**
 * Parse simple long options from argv.
 * Supports:
 *   --key=value
 *   --key value
 *   --flag
 */
function cli_parse_long_options($argv, $start_index = 1)
{
    $out = [];
    $count = count($argv);
    for ($i = $start_index; $i < $count; $i++) {
        $arg = (string) $argv[$i];
        if (substr($arg, 0, 2) !== '--') {
            continue;
        }
        $arg = substr($arg, 2);
        if ($arg === '') {
            continue;
        }

        if (strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
            continue;
        }

        $next = $argv[$i + 1] ?? null;
        if ($next !== null && substr((string) $next, 0, 2) !== '--') {
            $out[$arg] = (string) $next;
            $i++;
        } else {
            $out[$arg] = true;
        }
    }
    return $out;
}

