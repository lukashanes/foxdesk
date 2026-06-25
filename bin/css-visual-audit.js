#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const baselinePath = path.join(root, 'docs', 'visual-style-baseline.json');
const baseline = JSON.parse(fs.readFileSync(baselinePath, 'utf8'));

function read(file) {
  return fs.readFileSync(path.join(root, file), 'utf8');
}

function uniqueDeclarationValues(css, property) {
  const pattern = new RegExp(`${property}\\s*:\\s*([^;{}]+)`, 'gi');
  const values = new Set();
  let match;
  while ((match = pattern.exec(css)) !== null) {
    const value = String(match[1] || '').trim().replace(/\s+/g, ' ');
    if (value === '' || value.startsWith('var(--')) {
      continue;
    }
    values.add(value);
  }
  return values;
}

const css = baseline.files.map(read).join('\n');
const metrics = {
  uniqueFontSizes: uniqueDeclarationValues(css, 'font-size').size,
  uniqueBorderRadii: uniqueDeclarationValues(css, 'border-radius').size,
  uniqueBoxShadows: uniqueDeclarationValues(css, 'box-shadow').size,
};

const failures = [];
for (const [key, value] of Object.entries(metrics)) {
  const baselineValue = Number(baseline.metrics[key]);
  if (!(value < baselineValue)) {
    failures.push(`${key} must be lower than baseline ${baselineValue}; got ${value}.`);
  }
}

const result = {
  status: failures.length === 0 ? 'passed' : 'failed',
  baseline: baseline.metrics,
  current: metrics,
  files: baseline.files,
  failures,
};

if (process.argv.includes('--json')) {
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
} else {
  console.log(`CSS visual audit: ${result.status}`);
  console.log(`- font sizes: ${metrics.uniqueFontSizes} / baseline ${baseline.metrics.uniqueFontSizes}`);
  console.log(`- radii: ${metrics.uniqueBorderRadii} / baseline ${baseline.metrics.uniqueBorderRadii}`);
  console.log(`- shadows: ${metrics.uniqueBoxShadows} / baseline ${baseline.metrics.uniqueBoxShadows}`);
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
}

if (failures.length > 0) {
  process.exit(1);
}
