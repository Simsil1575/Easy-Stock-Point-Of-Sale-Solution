# Receipt Printing Interceptor

Node.js service that intercepts receipt print requests and forwards them to `receipt.php`. Runs 24/7 in the background.

## Quick start

```bash
cd receipt-interceptor
npm install
npm start
```

By default the server listens on **port 3847**. Send receipt data as POST JSON (same as `receipt.php`):

- `POST http://localhost:3847/`
- `POST http://localhost:3847/print`
- `POST http://localhost:3847/receipt.php`

Health check: `GET http://localhost:3847/health`

## Configuration

| Env var | Default | Description |
|--------|---------|-------------|
| `PORT` | `3847` | Port the interceptor listens on |
| `RECEIPT_PHP_URL` | `http://127.0.0.1/receipt.php` | Full URL to your `receipt.php` (XAMPP: use `http://localhost/receipt.php` if your doc root is htdocs) |

Example:

```bash
set RECEIPT_PHP_URL=http://localhost/receipt.php
set PORT=3847
npm start
```

## Run 24/7 in background (Windows)

### Option 1: PM2 (recommended)

```bash
npm install -g pm2
cd receipt-interceptor
pm2 start server.js --name receipt-interceptor
pm2 save
pm2 startup
```

Then:

- `pm2 status` — see status
- `pm2 logs receipt-interceptor` — view logs
- `pm2 restart receipt-interceptor` — restart

### Option 2: Run in background with `start`

```bash
cd receipt-interceptor
start /B node server.js
```

Or run in a separate terminal and leave it open.

### Option 3: Windows Service (NSSM)

1. Download [NSSM](https://nssm.cc/download).
2. Install the service:

```bash
nssm install ReceiptInterceptor "C:\Program Files\nodejs\node.exe" "C:\xampp\htdocs\receipt-interceptor\server.js"
nssm set ReceiptInterceptor AppDirectory "C:\xampp\htdocs\receipt-interceptor"
nssm start ReceiptInterceptor
```

## Using the interceptor from your app

Point `sendToPrinter` (or any code that currently POSTs to `receipt.php`) to the interceptor instead:

- Before: `fetch('receipt.php', { method: 'POST', body: JSON.stringify(data) })`
- After:  `fetch('http://localhost:3847/receipt.php', { method: 'POST', body: JSON.stringify(data) })`

Or use a configurable base URL so you can switch between direct PHP and the interceptor.

The interceptor forwards the same JSON to `receipt.php` and returns the same JSON response; printing is still done by `receipt.php`.
