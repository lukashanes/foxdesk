// Reproducibly vendor the exact renderer used by the approved local prototype.
// Usage: node bin/vendor-pdf-preview.js /path/to/node_modules/pdfjs-dist
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const source = path.resolve(process.argv[2] || 'node_modules/pdfjs-dist');
const version = '6.3.289';
if (JSON.parse(fs.readFileSync(path.join(source,'package.json'),'utf8')).version !== version) throw Error('Unexpected PDF.js version');
const destination = path.resolve(__dirname, '../assets/vendor/pdfjs', version);
fs.mkdirSync(destination, {recursive:true});
const files = {'build/pdf.min.mjs':'pdf.min.mjs','build/pdf.worker.min.mjs':'pdf.worker.min.mjs','LICENSE':'LICENSE'};
const manifest = {package:'pdfjs-dist', version, files:{}};
for (const [input,output] of Object.entries(files)) {
    const bytes=fs.readFileSync(path.join(source,input));
    fs.writeFileSync(path.join(destination,output),bytes);
    manifest.files[output]=crypto.createHash('sha256').update(bytes).digest('hex');
}
fs.writeFileSync(path.join(destination,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');
console.log('Vendored PDF preview renderer '+version);
