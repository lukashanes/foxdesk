<?php

/** Resolve the workspace locale without assuming the settings table is loaded. */
function foxdesk_workspace_language(): string
{
    $language = null;
    if (function_exists('get_setting')) {
        try {
            $language = normalize_locale_tag(get_setting('app_language', 'en'));
        } catch (Throwable $e) {
            $language = null;
        }
    }

    return $language ?? 'en';
}

/** Return a user's explicit locale, or null when it inherits the workspace. */
function foxdesk_user_language_override(?array $user): ?string
{
    if (!$user || !array_key_exists('language', $user)) {
        return null;
    }

    $value = trim((string) ($user['language'] ?? ''));
    return $value === '' ? null : normalize_locale_tag($value);
}

function foxdesk_effective_user_language(?array $user, ?string $workspace_language = null): string
{
    return foxdesk_user_language_override($user)
        ?? normalize_locale_tag($workspace_language)
        ?? foxdesk_workspace_language();
}

/**
 * Load the canonical FoxDesk locale registry, keyed by canonical BCP-47 tag.
 */
function foxdesk_locale_registry(): array
{
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }

    $path = BASE_PATH . '/locales/registry.json';
    $decoded = is_file($path)
        ? json_decode((string) file_get_contents($path), true)
        : null;
    $items = is_array($decoded['locales'] ?? null) ? $decoded['locales'] : [];

    $registry = [];
    foreach ($items as $item) {
        $tag = trim((string) ($item['tag'] ?? ''));
        if ($tag === '') {
            continue;
        }
        $item['tag'] = $tag;
        $item['direction'] = ($item['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
        $item['fallback'] = $item['fallback'] ?? ($tag === 'en' ? null : 'en');
        $item['channels'] = is_array($item['channels'] ?? null) ? $item['channels'] : [];
        $registry[$tag] = $item;
    }

    if (!isset($registry['en'])) {
        $registry['en'] = [
            'tag' => 'en',
            'slug' => 'en',
            'englishName' => 'English',
            'nativeName' => 'English',
            'direction' => 'ltr',
            'script' => 'Latn',
            'fallback' => null,
            'wave' => 0,
            'channels' => ['self_hosted' => 'stable'],
        ];
    }

    return $registry;
}

function foxdesk_locale_channel(): string
{
    $channel = strtolower(trim((string) getenv('FOXDESK_LOCALE_CHANNEL')));
    return in_array($channel, ['self_hosted', 'saas', 'ios', 'website'], true)
        ? $channel
        : 'self_hosted';
}

function foxdesk_draft_locales_enabled(): bool
{
    return in_array(
        strtolower(trim((string) getenv('FOXDESK_ENABLE_DRAFT_LOCALES'))),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function foxdesk_pseudo_locale_registry(): array
{
    return [
        'en-XA' => [
            'tag' => 'en-XA',
            'slug' => 'en-xa',
            'englishName' => 'English (expanded pseudo)',
            'nativeName' => 'English (expanded pseudo)',
            'direction' => 'ltr',
            'script' => 'Latn',
            'fallback' => 'en',
            'wave' => -1,
            'internal' => true,
            'channels' => [],
        ],
        'ar-XB' => [
            'tag' => 'ar-XB',
            'slug' => 'ar-xb',
            'englishName' => 'Arabic (mirrored pseudo)',
            'nativeName' => 'العربية (اختبار معكوس)',
            'direction' => 'rtl',
            'script' => 'Arab',
            'fallback' => 'en',
            'wave' => -1,
            'internal' => true,
            'channels' => [],
        ],
    ];
}

function foxdesk_pseudo_locales_enabled(): bool
{
    return in_array(
        strtolower(trim((string) getenv('FOXDESK_ENABLE_PSEUDO_LOCALES'))),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

/**
 * Canonicalize a locale without applying product release-state filtering.
 */
function foxdesk_canonical_locale($value): ?string
{
    $value = trim(str_replace('_', '-', (string) $value));
    if ($value === '') {
        return null;
    }

    $lookup = strtolower($value);
    $aliases = [
        'zh-cn' => 'zh-Hans',
        'zh-sg' => 'zh-Hans',
        'zh-tw' => 'zh-Hant',
        'zh-hk' => 'zh-Hant',
        'zh-mo' => 'zh-Hant',
    ];
    if (isset($aliases[$lookup])) {
        return $aliases[$lookup];
    }

    foreach (foxdesk_locale_registry() as $tag => $metadata) {
        if (strtolower($tag) === $lookup || strtolower((string) ($metadata['slug'] ?? '')) === $lookup) {
            return $tag;
        }
    }
    foreach (foxdesk_pseudo_locale_registry() as $tag => $metadata) {
        if (strtolower($tag) === $lookup || strtolower((string) ($metadata['slug'] ?? '')) === $lookup) {
            return $tag;
        }
    }

    return null;
}

function foxdesk_locale_status(string $locale, ?string $channel = null): string
{
    $canonical = foxdesk_canonical_locale($locale);
    if ($canonical === null) {
        return 'unavailable';
    }
    if (isset(foxdesk_pseudo_locale_registry()[$canonical])) {
        return foxdesk_pseudo_locales_enabled() ? 'draft' : 'unavailable';
    }
    $channel = $channel ?? foxdesk_locale_channel();
    $status = strtolower((string) (foxdesk_locale_registry()[$canonical]['channels'][$channel] ?? 'draft'));
    return in_array($status, ['stable', 'beta', 'draft'], true) ? $status : 'draft';
}

function foxdesk_locale_is_available(
    string $locale,
    ?string $channel = null,
    ?bool $includeDraft = null
): bool {
    $canonical = foxdesk_canonical_locale($locale);
    if ($canonical !== null && isset(foxdesk_pseudo_locale_registry()[$canonical])) {
        return foxdesk_pseudo_locales_enabled();
    }
    $status = foxdesk_locale_status($locale, $channel);
    $includeDraft = $includeDraft ?? foxdesk_draft_locales_enabled();
    return in_array($status, ['stable', 'beta'], true) || ($includeDraft && $status === 'draft');
}

function foxdesk_available_locales(?string $channel = null, ?bool $includeDraft = null): array
{
    $available = [];
    foreach (foxdesk_locale_registry() as $tag => $metadata) {
        if (!foxdesk_locale_is_available($tag, $channel, $includeDraft)) {
            continue;
        }
        $metadata['state'] = foxdesk_locale_status($tag, $channel);
        $available[$tag] = $metadata;
    }
    return $available;
}

/**
 * Backwards-compatible language metadata used by existing selectors.
 */
function get_supported_languages()
{
    $supported = [];
    $available = foxdesk_available_locales();
    if (foxdesk_pseudo_locales_enabled()) {
        foreach (foxdesk_pseudo_locale_registry() as $tag => $metadata) {
            $metadata['state'] = 'draft';
            $available[$tag] = $metadata;
        }
    }
    foreach ($available as $tag => $metadata) {
        $supported[$tag] = [
            'name' => (string) ($metadata['englishName'] ?? $tag),
            'native' => (string) ($metadata['nativeName'] ?? $tag),
            'rtl' => ($metadata['direction'] ?? 'ltr') === 'rtl',
            'direction' => (string) ($metadata['direction'] ?? 'ltr'),
            'script' => (string) ($metadata['script'] ?? 'Latn'),
            'fallback' => $metadata['fallback'] ?? 'en',
            'state' => (string) ($metadata['state'] ?? 'draft'),
            'wave' => (int) ($metadata['wave'] ?? 0),
        ];
    }
    return $supported;
}

function normalize_locale_tag(
    $value,
    ?string $channel = null,
    ?bool $includeDraft = null
): ?string {
    $canonical = foxdesk_canonical_locale($value);
    if ($canonical === null || !foxdesk_locale_is_available($canonical, $channel, $includeDraft)) {
        return null;
    }
    return $canonical;
}

function foxdesk_locale_metadata($locale): ?array
{
    $canonical = foxdesk_canonical_locale($locale);
    if ($canonical === null) {
        return null;
    }
    return foxdesk_locale_registry()[$canonical]
        ?? foxdesk_pseudo_locale_registry()[$canonical]
        ?? null;
}

function foxdesk_locale_slug($locale): string
{
    $metadata = foxdesk_locale_metadata($locale);
    return (string) ($metadata['slug'] ?? 'en');
}

function foxdesk_locale_option_label(string $locale): string
{
    $metadata = foxdesk_locale_metadata($locale);
    if ($metadata === null) {
        return $locale;
    }
    return (string) ($metadata['nativeName'] ?? $metadata['englishName'] ?? $locale);
}

function foxdesk_negotiate_locale(
    string $acceptLanguage,
    ?string $channel = null,
    ?bool $includeDraft = null
): ?string {
    $candidates = [];
    foreach (explode(',', $acceptLanguage) as $position => $part) {
        $segments = array_map('trim', explode(';', $part));
        $requested = $segments[0] ?? '';
        $quality = 1.0;
        foreach (array_slice($segments, 1) as $parameter) {
            if (preg_match('/^q=([0-9.]+)$/i', $parameter, $matches)) {
                $quality = max(0.0, min(1.0, (float) $matches[1]));
            }
        }
        $candidates[] = ['locale' => $requested, 'quality' => $quality, 'position' => $position];
    }

    usort($candidates, static function (array $a, array $b): int {
        return $b['quality'] <=> $a['quality'] ?: $a['position'] <=> $b['position'];
    });

    foreach ($candidates as $candidate) {
        $normalized = normalize_locale_tag($candidate['locale'], $channel, $includeDraft);
        if ($normalized !== null) {
            return $normalized;
        }
        $base = explode('-', (string) $candidate['locale'])[0] ?? '';
        $normalized = normalize_locale_tag($base, $channel, $includeDraft);
        if ($normalized !== null) {
            return $normalized;
        }
    }

    return null;
}

function is_rtl(?string $lang = null): bool
{
    $lang = $lang ?? (function_exists('get_app_language') ? get_app_language() : 'en');
    $metadata = foxdesk_locale_metadata($lang);
    return ($metadata['direction'] ?? 'ltr') === 'rtl';
}

function get_app_direction(?string $lang = null): string
{
    return is_rtl($lang) ? 'rtl' : 'ltr';
}

function foxdesk_translation_catalog(string $locale): array
{
    static $catalogs = [];
    $locale = foxdesk_canonical_locale($locale) ?? 'en';
    if (!isset($catalogs[$locale])) {
        if (isset(foxdesk_pseudo_locale_registry()[$locale])) {
            $english = foxdesk_translation_catalog('en');
            $catalogs[$locale] = array_map(
                static fn(string $message): string => foxdesk_pseudolocalize($message, $locale),
                $english
            );
        } else {
            $path = BASE_PATH . '/includes/lang/' . $locale . '.php';
            $catalogs[$locale] = is_file($path) ? (require $path) : [];
        }
    }
    return is_array($catalogs[$locale]) ? $catalogs[$locale] : [];
}

function foxdesk_pseudolocalize(string $message, string $locale): string
{
    $locale = foxdesk_canonical_locale($locale) ?? 'en-XA';
    $parts = preg_split(
        '/(<[^>]+>|\{[a-zA-Z0-9_]+\}|&[a-zA-Z0-9#]+;)/u',
        $message,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if (!is_array($parts)) {
        $parts = [$message];
    }

    $accentMap = [
        'a' => 'á', 'b' => 'ƀ', 'c' => 'ç', 'd' => 'ď', 'e' => 'ë', 'f' => 'ƒ',
        'g' => 'ğ', 'h' => 'ħ', 'i' => 'ï', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ľ',
        'm' => 'ṁ', 'n' => 'ñ', 'o' => 'ö', 'p' => 'þ', 'q' => 'ǫ', 'r' => 'ř',
        's' => 'š', 't' => 'ŧ', 'u' => 'ü', 'v' => 'ṽ', 'w' => 'ŵ', 'x' => 'ẋ',
        'y' => 'ÿ', 'z' => 'ž',
        'A' => 'Á', 'B' => 'Ƀ', 'C' => 'Ç', 'D' => 'Ď', 'E' => 'Ë', 'F' => 'Ƒ',
        'G' => 'Ğ', 'H' => 'Ħ', 'I' => 'Ï', 'J' => 'Ĵ', 'K' => 'Ķ', 'L' => 'Ľ',
        'M' => 'Ṁ', 'N' => 'Ñ', 'O' => 'Ö', 'P' => 'Þ', 'Q' => 'Ǫ', 'R' => 'Ř',
        'S' => 'Š', 'T' => 'Ŧ', 'U' => 'Ü', 'V' => 'Ṽ', 'W' => 'Ŵ', 'X' => 'Ẋ',
        'Y' => 'Ÿ', 'Z' => 'Ž',
    ];

    foreach ($parts as $index => $part) {
        if ($part === '' || $part[0] === '<' || $part[0] === '{' || $part[0] === '&') {
            continue;
        }
        $parts[$index] = $locale === 'en-XA' ? strtr($part, $accentMap) : $part;
    }

    $transformed = implode('', $parts);
    if ($locale === 'ar-XB') {
        return "\u{200F}⟦" . $transformed . "⟧\u{200F}";
    }
    $padding = str_repeat('~', max(2, (int) ceil(mb_strlen($message, 'UTF-8') * 0.3)));
    return '⟦' . $transformed . ' ' . $padding . '⟧';
}

function foxdesk_plural_category(string $locale, $number): string
{
    $locale = foxdesk_canonical_locale($locale) ?? 'en';
    if ($locale === 'en-XA') {
        $locale = 'en';
    } elseif ($locale === 'ar-XB') {
        $locale = 'ar';
    }
    $n = abs((float) $number);

    if (class_exists('MessageFormatter')) {
        try {
            $formatter = new MessageFormatter(
                $locale,
                '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}'
            );
            $category = $formatter->format(['n' => $n]);
            if (in_array($category, ['zero', 'one', 'two', 'few', 'many', 'other'], true)) {
                return $category;
            }
        } catch (Throwable $e) {
            // Continue with the bundled rules for self-hosted installs without
            // complete ICU locale data.
        }
    }

    $integer = (int) floor($n);
    $isInteger = abs($n - $integer) < 0.0000001;
    $mod10 = $integer % 10;
    $mod100 = $integer % 100;

    if ($locale === 'ar') {
        if ($n === 0.0) return 'zero';
        if ($n === 1.0) return 'one';
        if ($n === 2.0) return 'two';
        if ($isInteger && $mod100 >= 3 && $mod100 <= 10) return 'few';
        if ($isInteger && $mod100 >= 11 && $mod100 <= 99) return 'many';
        return 'other';
    }
    if (in_array($locale, ['ru', 'uk'], true) && $isInteger) {
        if ($mod10 === 1 && $mod100 !== 11) return 'one';
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) return 'few';
        if ($mod10 === 0 || $mod10 >= 5 || ($mod100 >= 11 && $mod100 <= 14)) return 'many';
        return 'other';
    }
    if ($locale === 'pl' && $isInteger) {
        if ($integer === 1) return 'one';
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) return 'few';
        return 'many';
    }
    if ($locale === 'cs' && $isInteger) {
        if ($integer === 1) return 'one';
        if ($integer >= 2 && $integer <= 4) return 'few';
        return 'other';
    }
    if ($locale === 'cs') {
        return 'many';
    }
    if ($locale === 'he' && $isInteger) {
        if ($integer === 1) return 'one';
        if ($integer === 2) return 'two';
        return 'other';
    }
    if (in_array($locale, ['ja', 'zh-Hans', 'zh-Hant', 'ko', 'tr', 'id', 'vi'], true)) {
        return 'other';
    }
    if (in_array($locale, ['fr', 'pt-BR'], true)) {
        return ($integer === 0 || $integer === 1) ? 'one' : 'other';
    }
    if (in_array($locale, ['fa', 'hi'], true)) {
        return ($integer === 0 || abs($n - 1.0) < 0.0000001) ? 'one' : 'other';
    }
    return $isInteger && $integer === 1 ? 'one' : 'other';
}

function foxdesk_normalize_unicode(string $value): string
{
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (is_string($normalized)) {
            return $normalized;
        }
    }
    return $value;
}

function foxdesk_strip_bidi_controls(string $value): string
{
    return (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value);
}

/**
 * Detect scripts that require the non-tokenizing ticket search path.
 *
 * The decision is based on the query itself, not on the user's interface
 * language, so an English-speaking agent can still search a Japanese ticket.
 */
function foxdesk_contains_cjk(string $value): bool
{
    return preg_match(
        '/[\x{3040}-\x{30ff}\x{31f0}-\x{31ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}\x{1100}-\x{11ff}\x{3130}-\x{318f}\x{ac00}-\x{d7af}\x{20000}-\x{2fa1f}]/u',
        $value
    ) === 1;
}

/**
 * Build a literal contains-pattern for SQL LIKE using a portable escape byte.
 */
function foxdesk_like_contains_pattern(string $value, string $escape = '='): string
{
    $value = str_replace(
        [$escape, '%', '_'],
        [$escape . $escape, $escape . '%', $escape . '_'],
        foxdesk_normalize_unicode($value)
    );
    return '%' . $value . '%';
}

function foxdesk_number_profile(string $locale): array
{
    $locale = foxdesk_canonical_locale($locale) ?? 'en';
    if ($locale === 'en-XA') {
        $locale = 'en';
    } elseif ($locale === 'ar-XB') {
        $locale = 'ar';
    }
    $commaDecimal = [
        'cs', 'de', 'es', 'it', 'fr', 'pt-BR', 'pt-PT', 'pl', 'nl',
        'tr', 'ru', 'uk', 'id', 'vi',
    ];
    return [
        'decimal' => in_array($locale, $commaDecimal, true) ? ',' : '.',
        'group' => in_array($locale, $commaDecimal, true) ? "\u{00A0}" : ',',
    ];
}

function foxdesk_format_number($number, int $decimals = 0, ?string $locale = null): string
{
    $locale = foxdesk_canonical_locale($locale ?? (function_exists('get_app_language') ? get_app_language() : 'en')) ?? 'en';
    $formatLocale = $locale === 'en-XA' ? 'en' : ($locale === 'ar-XB' ? 'ar' : $locale);
    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter($formatLocale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, max(0, $decimals));
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, max(0, $decimals));
        $formatted = $formatter->format((float) $number);
        if (is_string($formatted)) {
            return $formatted;
        }
    }

    $profile = foxdesk_number_profile($locale);
    return number_format((float) $number, max(0, $decimals), $profile['decimal'], $profile['group']);
}

function foxdesk_format_currency($amount, string $currency, ?string $locale = null): string
{
    $locale = foxdesk_canonical_locale($locale ?? (function_exists('get_app_language') ? get_app_language() : 'en')) ?? 'en';
    $formatLocale = $locale === 'en-XA' ? 'en' : ($locale === 'ar-XB' ? 'ar' : $locale);
    $currency = strtoupper(trim($currency)) ?: 'CZK';
    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter($formatLocale, NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency((float) $amount, $currency);
        if (is_string($formatted)) {
            return $formatted;
        }
    }
    return foxdesk_format_number($amount, 2, $locale) . "\u{00A0}" . $currency;
}

function foxdesk_fallback_date_pattern(string $locale, bool $withTime): string
{
    $locale = foxdesk_canonical_locale($locale) ?? 'en';
    if ($locale === 'en-XA') {
        $locale = 'en';
    } elseif ($locale === 'ar-XB') {
        $locale = 'ar';
    }
    if ($locale === 'en') {
        $date = 'm/d/Y';
    } elseif (in_array($locale, ['ja', 'zh-Hans', 'zh-Hant'], true)) {
        $date = 'Y/m/d';
    } elseif ($locale === 'ko') {
        $date = 'Y. m. d.';
    } elseif (in_array($locale, ['cs', 'de', 'pl', 'ru', 'uk'], true)) {
        $date = 'd.m.Y';
    } else {
        $date = 'd/m/Y';
    }
    return $withTime ? $date . ' H:i' : $date;
}

function foxdesk_format_datetime($date, bool $withTime = true, ?string $locale = null): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $timestamp = is_numeric($date) ? (int) $date : strtotime((string) $date);
    if ($timestamp === false) {
        return '';
    }

    $locale = foxdesk_canonical_locale($locale ?? (function_exists('get_app_language') ? get_app_language() : 'en')) ?? 'en';
    $formatLocale = $locale === 'en-XA' ? 'en' : ($locale === 'ar-XB' ? 'ar' : $locale);
    $timeFormat = '24';
    if (function_exists('get_setting')) {
        try {
            $timeFormat = get_setting('time_format', '24') === '12' ? '12' : '24';
        } catch (Throwable $e) {
            $timeFormat = '24';
        }
    }
    if (class_exists('IntlDateFormatter')) {
        // Reuse ICU formatters within this request; the key includes mutable runtime settings.
        static $formatters = [];
        $timezone = date_default_timezone_get();
        $key = $formatLocale . '|' . $timezone . '|' . $timeFormat;
        if (!isset($formatters[$key])) {
            if (count($formatters) >= 64) {
                $formatters = [];
            }
            $formatters[$key] = [new IntlDateFormatter(
                $formatLocale,
                IntlDateFormatter::SHORT,
                IntlDateFormatter::NONE,
                $timezone
            ), null];
        }
        $dateFormatter = $formatters[$key][0];
        $formattedDate = $dateFormatter->format($timestamp);
        if (is_string($formattedDate)) {
            if (!$withTime) {
                return $formattedDate;
            }
            if ($formatters[$key][1] === null) {
                $timeFormatter = new IntlDateFormatter(
                    $formatLocale,
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::SHORT,
                    $timezone
                );
                $timeFormatter->setPattern($timeFormat === '12' ? 'h:mm a' : 'HH:mm');
                $formatters[$key][1] = $timeFormatter;
            }
            $timeFormatter = $formatters[$key][1];
            $formattedTime = $timeFormatter->format($timestamp);
            if (is_string($formattedTime)) {
                return $formattedDate . ' ' . $formattedTime;
            }
        }
    }

    if (!$withTime) {
        return date(foxdesk_fallback_date_pattern($locale, false), $timestamp);
    }
    $timePattern = $timeFormat === '12' ? 'g:i A' : 'H:i';
    return date(foxdesk_fallback_date_pattern($locale, false) . ' ' . $timePattern, $timestamp);
}
