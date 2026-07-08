/**
 * Receipt Printing Interceptor - Node.js service that runs 24/7
 * Intercepts POST requests with receipt data and forwards to receipt.php for printing.
 * Use this URL in your app instead of receipt.php so the interceptor is always available.
 */

const express = require('express');
const bodyParser = require('body-parser');
const http = require('http');
const https = require('https');

const app = express();
const PORT = process.env.PORT || 3847;
const RECEIPT_PHP_URL = process.env.RECEIPT_PHP_URL || 'http://127.0.0.1/receipt.php';

app.use(bodyParser.json({ limit: '2mb' }));
app.use(bodyParser.raw({ type: 'application/json', limit: '2mb' }));

function getReceiptUrl() {
  try {
    const u = new URL(RECEIPT_PHP_URL);
    return u;
  } catch (e) {
    return new URL('http://127.0.0.1/receipt.php');
  }
}

function forwardToReceiptPhp(body) {
  return new Promise((resolve, reject) => {
    const url = getReceiptUrl();
    const isHttps = url.protocol === 'https:';
    const lib = isHttps ? https : http;
    const data = typeof body === 'string' ? body : JSON.stringify(body || {});

    const options = {
      hostname: url.hostname,
      port: url.port || (isHttps ? 443 : 80),
      path: url.pathname + url.search,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(data, 'utf8'),
      },
    };

    const req = lib.request(options, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let parsed;
        try {
          parsed = JSON.parse(raw);
        } catch (_) {
          parsed = { success: false, message: 'Invalid JSON from receipt.php', raw: raw.slice(0, 200) };
        }
        resolve({ statusCode: res.statusCode, body: parsed, raw });
      });
    });

    req.on('error', (err) => reject(err));
    req.setTimeout(60000, () => {
      req.destroy();
      reject(new Error('Request to receipt.php timed out'));
    });
    req.write(data);
    req.end();
  });
}

// Health check for monitoring / process managers
app.get('/health', (req, res) => {
  res.setHeader('Content-Type', 'application/json');
  res.status(200).json({
    ok: true,
    service: 'receipt-interceptor',
    uptime: process.uptime(),
    receipt_php_url: RECEIPT_PHP_URL,
  });
});

// Intercept receipt print: same API as receipt.php (POST JSON body)
app.post('/', (req, res) => handlePrint(req, res));
app.post('/print', (req, res) => handlePrint(req, res));
app.post('/receipt.php', (req, res) => handlePrint(req, res));

function handlePrint(req, res) {
  const body = req.body;
  if (!body || (typeof body === 'object' && Object.keys(body).length === 0)) {
    res.status(400).json({ success: false, message: 'No order data received' });
    return;
  }

  const payload = typeof body === 'string' ? (() => { try { return JSON.parse(body); } catch (_) { return null; } })() : body;
  if (!payload) {
    res.status(400).json({ success: false, message: 'Invalid JSON body' });
    return;
  }

  forwardToReceiptPhp(payload)
    .then(({ statusCode, body: phpBody }) => {
      res.status(statusCode >= 200 && statusCode < 300 ? 200 : statusCode).json(phpBody);
    })
    .catch((err) => {
      console.error('[receipt-interceptor] Forward error:', err.message);
      res.status(503).json({
        success: false,
        message: 'Receipt service unavailable: ' + err.message,
        receipt_php_url: RECEIPT_PHP_URL,
      });
    });
}

const server = app.listen(PORT, '0.0.0.0', () => {
  console.log('[receipt-interceptor] Listening on port %s (RECEIPT_PHP_URL=%s)', PORT, RECEIPT_PHP_URL);
});

server.on('error', (err) => {
  console.error('[receipt-interceptor] Server error:', err);
  process.exitCode = 1;
});

// Keep process alive and handle shutdown
process.on('SIGINT', () => {
  server.close(() => {
    console.log('[receipt-interceptor] Shutdown');
    process.exit(0);
  });
});
process.on('SIGTERM', () => {
  server.close(() => {
    console.log('[receipt-interceptor] Shutdown');
    process.exit(0);
  });
});
