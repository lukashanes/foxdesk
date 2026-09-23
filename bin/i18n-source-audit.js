#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const allowlistPath = path.join(root, 'config', 'i18n-physical-direction-allowlist.json');
const allowlist = JSON.parse(fs.readFileSync(allowlistPath, 'utf8'));
const failures = [];

function fail(message) {
  failures.push(message);
}

function walk(directory, files = []) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (['.git', 'node_modules', 'build', 'vendor', 'locales'].includes(entry.name)) continue;
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      walk(absolute, files);
    } else {
      files.push(absolute);
    }
  }
  return files;
}

const sourceFiles = walk(root).filter((file) => /\.(php|sql|js)$/.test(file));
for (const absolute of sourceFiles) {
  const relative = path.relative(root, absolute);
  if (relative.startsWith('tests' + path.sep) || relative.startsWith('includes' + path.sep + 'lang' + path.sep)) {
    continue;
  }
  const source = fs.readFileSync(absolute, 'utf8');
  if (/(language|report_language)[\s\S]{0,80}VARCHAR\s*\(\s*5\s*\)|VARCHAR\s*\(\s*5\s*\)[\s\S]{0,80}(language|report_language)/i.test(source)) {
    fail(`${relative}: locale database columns must allow full BCP-47 tags.`);
  }
  if (/\[\s*['"]en['"]\s*,\s*['"]cs['"]\s*,\s*['"]de['"]/i.test(source)) {
    fail(`${relative}: hard-coded legacy language list must use the locale registry.`);
  }
  if (/style\s*=\s*["'][^"']*(?:\bleft\b|\bright\b|margin-left|margin-right|padding-left|padding-right|border-left|border-right)\s*:/i.test(source)) {
    fail(`${relative}: inline styles must use logical CSS properties.`);
  }
}

const cssPropertyPattern = /(?:^|;)\s*(left|right|margin-left|margin-right|padding-left|padding-right|border-left|border-right|border-top-left-radius|border-top-right-radius|border-bottom-left-radius|border-bottom-right-radius)\s*:|(?:^|;)\s*text-align\s*:\s*(left|right)\b/g;
for (const rule of allowlist.css || []) {
  const cssPath = path.join(root, rule.file);
  const css = fs.readFileSync(cssPath, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  const blockPattern = /([^{}]+)\{([^{}]*)\}/g;
  let block;
  let allowedCount = 0;
  while ((block = blockPattern.exec(css))) {
    cssPropertyPattern.lastIndex = 0;
    let declaration;
    while ((declaration = cssPropertyPattern.exec(block[2]))) {
      if (!block[1].includes(rule.selectorIncludes)) {
        fail(`${rule.file}: physical declaration outside documented selector allowlist: ${block[1].trim()}`);
      } else {
        allowedCount++;
      }
    }
  }
  if (allowedCount > rule.maxDeclarations) {
    fail(`${rule.file}: physical declaration allowlist grew from ${rule.maxDeclarations} to ${allowedCount}.`);
  }
}

const scriptRules = new Map((allowlist.scripts || []).map((rule) => [rule.file, rule]));
for (const absolute of sourceFiles) {
  const relative = path.relative(root, absolute).split(path.sep).join('/');
  const source = fs.readFileSync(absolute, 'utf8');
  const matches = source.match(/\.style\.(left|right|marginLeft|marginRight|paddingLeft|paddingRight)\b/g) || [];
  if (matches.length === 0) continue;
  const rule = scriptRules.get(relative);
  if (!rule) {
    fail(`${relative}: physical JavaScript positioning is not documented in the allowlist.`);
    continue;
  }
  const allowedMatches = (rule.patterns || [rule.pattern]).reduce((count, pattern) => count + source.split(pattern).length - 1, 0);
  if (allowedMatches !== matches.length || matches.length > rule.maxMatches) {
    fail(`${relative}: physical JavaScript allowlist no longer matches source (${matches.length} occurrences).`);
  }
}
for (const rule of scriptRules.values()) {
  const source = fs.readFileSync(path.join(root, rule.file), 'utf8');
  const count = (rule.patterns || [rule.pattern]).reduce((count, pattern) => count + source.split(pattern).length - 1, 0);
  if (count === 0) {
    fail(`${rule.file}: remove stale physical-direction allowlist entry.`);
  }
}

const generatedCss = fs.readFileSync(path.join(root, 'assets', 'css', 'theme.min.css'), 'utf8');
if (generatedCss.trim() === '') {
  fail('assets/css/theme.min.css: generated CSS is empty.');
}

if (failures.length > 0) {
  for (const failure of failures) console.error(`[i18n source audit] ${failure}`);
  process.exit(1);
}

console.log('i18n source and physical-direction audit passed.');
