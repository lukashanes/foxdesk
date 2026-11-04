#!/usr/bin/env node

import fs from "node:fs";
import path from "node:path";

const args = process.argv.slice(2);
const option = (name, fallback = null) => {
  const prefix = `--${name}=`;
  return args.find((arg) => arg.startsWith(prefix))?.slice(prefix.length) ?? fallback;
};

const root = path.resolve(option("repo", path.join(import.meta.dirname, "..")));
const catalogDir = path.resolve(root, option("catalog-dir", "locales/catalogs"));
const registryPath = path.resolve(root, option("registry", "locales/registry.json"));
const registry = JSON.parse(fs.readFileSync(registryPath, "utf8"));
const englishDocument = JSON.parse(fs.readFileSync(path.join(catalogDir, "en.json"), "utf8"));
const { _meta: _englishMeta, ...english } = englishDocument;
const requested = new Set(
  option("locales", "")
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean),
);
const locales = registry.locales
  .map((locale) => locale.tag)
  .filter((tag) => tag !== "en" && (requested.size === 0 || requested.has(tag)));
const cacheRoot = path.resolve(
  process.env.FOXDESK_TRANSLATION_CACHE
    ?? path.join(root, "locales", ".translation-cache", "google"),
);
const dryRun = args.includes("--dry-run");
const provider = process.env.FOXDESK_TRANSLATION_PROVIDER ?? "google";
const lingvaBase = (process.env.FOXDESK_LINGVA_BASE_URL ?? "https://lingva.ml").replace(/\/+$/u, "");
const translatePaApiKey = process.env.FOXDESK_TRANSLATE_PA_API_KEY ?? "";
const previousCatalogPath = process.env.FOXDESK_TRANSLATION_PREVIOUS_CATALOG
  ? path.resolve(process.env.FOXDESK_TRANSLATION_PREVIOUS_CATALOG)
  : null;
const previousEnglishDocument = previousCatalogPath
  ? JSON.parse(fs.readFileSync(previousCatalogPath, "utf8"))
  : null;
const { _meta: _previousEnglishMeta, ...previousEnglish } = previousEnglishDocument ?? {};
const concurrency = Math.max(
  1,
  Number(process.env.FOXDESK_TRANSLATION_CONCURRENCY ?? (
    ["lingva", "translate-pa"].includes(provider) ? 4 : 1
  )),
);
if (provider === "translate-pa" && translatePaApiKey === "") {
  throw new Error("FOXDESK_TRANSLATE_PA_API_KEY is required for the translate-pa provider.");
}

const googleCodes = {
  "pt-BR": "pt",
  "pt-PT": "pt-PT",
  "zh-Hans": "zh-CN",
  "zh-Hant": "zh-TW",
};
const lingvaCodes = {
  he: "iw",
  "pt-BR": "pt",
  "pt-PT": "pt",
  "zh-Hans": "zh",
  "zh-Hant": "zh_HANT",
};

const protectedPattern = new RegExp(
  [
    String.raw`https?:\/\/[^\s<>"')]+`,
    String.raw`[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}`,
    String.raw`<\/?[^>]+>`,
    String.raw`\{\{[^{}]+\}\}`,
    String.raw`\{[^{}\n]+\}`,
    String.raw`\/`,
    String.raw`%(?:\d+\$)?(?:@|lld|ld|arg|[sdif])`,
    "`[^`\\n]+`",
    String.raw`\b(?:FoxDesk|AGPL-3\.0|API|PWA|PHP|MySQL|MariaDB|SMTP|IMAP|SSL|TLS|CSV|PDF|JSON|HTML|URL|ID|AI|OAuth|WebAuthn|TOTP|Cloudflare|Stripe|Weblate|TestFlight|App Store|GitHub|GitLab|Bitbucket|Google Docs|Google Sheets|Google Slides|YouTube|Figma|Claude|ChatGPT|Codex|Cursor)\b`,
  ].join("|"),
  "giu",
);

function walkStrings(value, callback, trail = []) {
  if (typeof value === "string") return callback(value, trail);
  if (Array.isArray(value)) return value.map((item, index) => walkStrings(item, callback, [...trail, index]));
  if (!value || typeof value !== "object") return value;
  return Object.fromEntries(
    Object.entries(value).map(([key, item]) => [
      key,
      walkStrings(item, callback, [...trail, key]),
    ]),
  );
}

function collectPairs(source, target, output = new Map()) {
  for (const [key, sourceValue] of Object.entries(source)) {
    const targetValue = target?.[key];
    if (typeof sourceValue === "string") {
      if (
        typeof targetValue === "string"
        && targetValue.trim() !== ""
        && targetValue !== sourceValue
      ) {
        output.set(sourceValue, targetValue);
      }
      continue;
    }
    if (sourceValue && typeof sourceValue === "object") {
      collectPairs(sourceValue, targetValue, output);
    }
  }
  return output;
}

function placeholders(value) {
  return [
    ...String(value).matchAll(/\{\{[^{}]+\}\}|\{[^{}\n]+\}|%(?:\d+\$)?(?:@|lld|ld|arg|[sdif])/g),
  ].map((match) => match[0]).sort();
}

function protect(value) {
  const tokens = [];
  const text = value.replace(protectedPattern, (match) => {
    const token = provider === "translate-pa"
      ? `<span class="notranslate">P${tokens.length}</span>`
      : `⟦P${tokens.length}⟧`;
    tokens.push([token, match]);
    return token;
  });
  return { text, tokens };
}

function restore(value, tokens, source) {
  let restored = value;
  for (const [token, original] of tokens) {
    if (!restored.includes(token)) {
      throw new Error(`Translation lost protected token ${token} in: ${source}`);
    }
    restored = restored.replaceAll(token, original);
  }
  restored = restored
    .replace(/[\u200b\u200c\u200d\u2060\ufeff]/gu, "")
    .normalize("NFC")
    .trim();
  if (restored === "") {
    throw new Error(`Translation returned an empty value for: ${source}`);
  }
  if (JSON.stringify(placeholders(restored)) !== JSON.stringify(placeholders(source))) {
    throw new Error(`Placeholder mismatch after translation: ${source}`);
  }
  const leadingWhitespace = source.match(/^\s*/u)?.[0] ?? "";
  const trailingWhitespace = source.match(/\s*$/u)?.[0] ?? "";
  return `${leadingWhitespace}${restored}${trailingWhitespace}`;
}

function needsTranslation(value) {
  if (/^(?:e\.g\.|i\.e\.)\s+(?:Codex|Claude|ChatGPT|Cursor)$/iu.test(value.trim())) {
    return false;
  }
  const stripped = value
    .replace(protectedPattern, "")
    .replace(/[\d\s.,:;!?()[\]{}'"“”‘’+\-–—/\\|@#$%^&*=<>…]+/gu, "");
  return /\p{L}/u.test(stripped);
}

function chunk(
  values,
  locale,
  maxItems = provider === "lingva"
    ? (["ar", "fa", "ur"].includes(locale) ? 12 : 36)
    : provider === "translate-pa"
      ? 64
    : 80,
  maxCharacters = provider === "lingva"
    ? (["ar", "fa", "ur"].includes(locale) ? 800 : 2_400)
    : provider === "translate-pa"
      ? 4_500
    : 12_000,
) {
  const chunks = [];
  let current = [];
  let characters = 0;
  for (const value of values) {
    if (current.length > 0 && (current.length >= maxItems || characters + value.length > maxCharacters)) {
      chunks.push(current);
      current = [];
      characters = 0;
    }
    current.push(value);
    characters += value.length;
  }
  if (current.length > 0) chunks.push(current);
  return chunks;
}

function parseBatch(value, count) {
  const marker = /⟦FD(\d{4})⟧\s*/gu;
  const matches = [...value.matchAll(marker)];
  if (matches.length !== count) {
    throw new Error(`Expected ${count} translation markers, received ${matches.length}.`);
  }
  const output = Array(count);
  for (let index = 0; index < matches.length; index += 1) {
    const itemIndex = Number(matches[index][1]);
    const start = matches[index].index + matches[index][0].length;
    const end = matches[index + 1]?.index ?? value.length;
    output[itemIndex] = value.slice(start, end).trim();
  }
  if (output.some((item) => typeof item !== "string")) {
    throw new Error("Translation markers were duplicated or reordered unexpectedly.");
  }
  return output;
}

async function requestTranslation(values, locale, attempt = 0) {
  const prepared = values.map(protect);
  const query = provider === "translate-pa"
    ? null
    : values.length === 1
      ? prepared[0].text
      : prepared
        .map(({ text }, index) => `⟦FD${String(index).padStart(4, "0")}⟧ ${text}`)
        .join("\n");
  const body = new URLSearchParams({
    client: "gtx",
    sl: "en",
    tl: googleCodes[locale] ?? locale,
    dt: "t",
    q: query,
  });
  try {
    const response = provider === "translate-pa"
      ? await fetch("https://translate-pa.googleapis.com/v1/translateHtml", {
        method: "POST",
        headers: {
          "content-type": "application/json+protobuf",
          "x-goog-api-key": translatePaApiKey,
        },
        body: JSON.stringify([
          [prepared.map(({ text }) => text), "en", googleCodes[locale] ?? locale],
          "wt_lib",
        ]),
        signal: AbortSignal.timeout(45_000),
      })
      : provider === "lingva"
        ? await fetch(
        `${lingvaBase}/api/v1/en/${lingvaCodes[locale] ?? locale}/${encodeURIComponent(query)}`,
        {
          headers: { "user-agent": "FoxDesk localization build/1.0" },
          signal: AbortSignal.timeout(45_000),
        },
      )
      : await fetch("https://translate.googleapis.com/translate_a/single", {
        method: "POST",
        headers: {
          "content-type": "application/x-www-form-urlencoded;charset=UTF-8",
          "user-agent": "FoxDesk localization build/1.0",
        },
        body,
        signal: AbortSignal.timeout(30_000),
      });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    const translated = provider === "translate-pa"
      ? payload?.[0] ?? []
      : provider === "lingva"
      ? payload?.translation ?? ""
      : payload?.[0]?.map((segment) => segment?.[0] ?? "").join("") ?? "";
    const parsed = provider === "translate-pa"
      ? translated
      : values.length === 1
        ? [translated.trim()]
        : parseBatch(translated, values.length);
    if (parsed.length !== values.length) {
      throw new Error(`Expected ${values.length} translations, received ${parsed.length}.`);
    }
    return parsed.map((item, index) => (
      restore(item, prepared[index].tokens, values[index])
    ));
  } catch (error) {
    if (values.length === 1 && /:\s*-\s*/u.test(values[0])) {
      const separator = values[0].indexOf(":");
      const prefix = values[0].slice(0, separator);
      const technicalList = values[0].slice(separator + 1);
      const [translatedPrefix] = await requestTranslation([prefix], locale, 0);
      return [`${translatedPrefix}:${technicalList}`];
    }
    if (attempt >= 3 && values.length > 1) {
      const middle = Math.ceil(values.length / 2);
      const left = await requestTranslation(values.slice(0, middle), locale, 0);
      const right = await requestTranslation(values.slice(middle), locale, 0);
      return [...left, ...right];
    }
    if (attempt >= 5) throw error;
    await new Promise((resolve) => setTimeout(resolve, 750 * (2 ** attempt)));
    return requestTranslation(values, locale, attempt + 1);
  }
}

function readCache(locale) {
  const cachePath = path.join(cacheRoot, `${locale}.json`);
  if (!fs.existsSync(cachePath)) return new Map();
  return new Map(Object.entries(JSON.parse(fs.readFileSync(cachePath, "utf8"))));
}

function writeCache(locale, cache) {
  if (dryRun) return;
  fs.mkdirSync(cacheRoot, { recursive: true });
  const sorted = Object.fromEntries([...cache.entries()].sort(([left], [right]) => left.localeCompare(right)));
  fs.writeFileSync(path.join(cacheRoot, `${locale}.json`), `${JSON.stringify(sorted, null, 2)}\n`);
}

function detectIndent(file) {
  const match = fs.readFileSync(file, "utf8").match(/\n( +)"/);
  return match?.[1]?.length ?? 2;
}

for (const locale of locales) {
  const catalogPath = path.join(catalogDir, `${locale}.json`);
  const targetDocument = JSON.parse(fs.readFileSync(catalogPath, "utf8"));
  const { _meta: targetMeta, ...target } = targetDocument;
  const cache = previousCatalogPath
    ? collectPairs(previousEnglish, target, readCache(locale))
    : collectPairs(english, target, readCache(locale));
  const sources = [];
  walkStrings(english, (value) => {
    if (!cache.has(value) && needsTranslation(value)) sources.push(value);
    if (!cache.has(value) && !needsTranslation(value)) cache.set(value, value);
    return value;
  });
  const uniqueSources = [...new Set(sources)];
  const batches = chunk(uniqueSources, locale);
  process.stdout.write(
    `[${locale}] ${cache.size} reused, ${uniqueSources.length} sources in ${batches.length} batches.\n`,
  );
  for (let index = 0; index < batches.length; index += concurrency) {
    const group = batches.slice(index, index + concurrency);
    const translatedGroups = await Promise.all(
      group.map((sourceBatch) => requestTranslation(sourceBatch, locale)),
    );
    group.forEach((sourceBatch, groupIndex) => {
      sourceBatch.forEach((source, itemIndex) => (
        cache.set(source, translatedGroups[groupIndex][itemIndex])
      ));
    });
    writeCache(locale, cache);
    process.stdout.write(
      `[${locale}] batch ${Math.min(index + concurrency, batches.length)}/${batches.length}\r`,
    );
  }
  process.stdout.write("\n");
  const translatedBody = walkStrings(english, (value) => cache.get(value) ?? value);
  const translatedCatalog = targetMeta
    ? { _meta: targetMeta, ...translatedBody }
    : translatedBody;
  if (!dryRun) {
    const indent = detectIndent(catalogPath);
    fs.writeFileSync(catalogPath, `${JSON.stringify(translatedCatalog, null, indent)}\n`);
  }
  const remaining = [];
  walkStrings(translatedBody, (value, trail) => {
    const source = trail.reduce((item, key) => item[key], english);
    if (source === value && needsTranslation(source)) remaining.push(trail.join("."));
    return value;
  });
  process.stdout.write(`[${locale}] complete; ${remaining.length} translatable strings still equal English.\n`);
}
