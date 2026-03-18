const http = require('http');
const net = require('net');

const server = http.createServer((req, res) => {
  const options = {
    hostname: 'localhost',
    port: 8082,
    path: req.url,
    method: req.method,
    headers: req.headers
  };
  const proxy = http.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode, proxyRes.headers);
    proxyRes.pipe(res, { end: true });
  });
  proxy.on('error', () => { res.writeHead(502); res.end('Bad Gateway'); });
  req.pipe(proxy, { end: true });
});

server.listen(8083, () => console.log('Proxy on 8083 -> 8082'));
