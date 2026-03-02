# WPIO Remote Conversion Server

A lightweight Node.js server using [Sharp](https://sharp.pixelplumbing.com/) for high-quality WebP/AVIF conversion.
Deploy this on any VPS, or free-tier platforms like **Railway**, **Render**, or **Fly.io**.

## Why self-host?

- Zero cost (free tier on Railway/Render is enough for personal/small sites)
- Full control over conversion quality and privacy
- Sharp outperforms GD/Imagick especially for AVIF
- Your image data never goes to a third party

## API Contract

### `GET /ping`
Health check. Returns `200 OK` with `{ "ok": true }`.
Plugin uses this to test the connection.

### `POST /convert`
Convert an image.

**Request headers:**
```
Content-Type: application/json
Authorization: Bearer <your_token>
```

**Request body (JSON):**
```json
{
  "file": "<base64-encoded image>",
  "mime": "image/jpeg",
  "format": "webp",
  "quality": 82
}
```

**Response (JSON):**
```json
{
  "image": "<base64-encoded converted image>",
  "format": "webp",
  "original_size": 204800,
  "converted_size": 62000,
  "saved_pct": 70
}
```

## Quick Deploy (Node.js)

```bash
npm install
npm start
```

### `server.js`

```js
const express = require('express');
const sharp   = require('sharp');
const app     = express();

app.use(express.json({ limit: '50mb' }));

const TOKEN = process.env.WPIO_TOKEN || 'change-me';

function auth(req, res, next) {
  const h = req.headers['authorization'] || '';
  if (h !== `Bearer ${TOKEN}`) return res.status(401).json({ error: 'Unauthorized' });
  next();
}

app.get('/ping', auth, (req, res) => res.json({ ok: true }));

app.post('/convert', auth, async (req, res) => {
  try {
    const { file, format, quality } = req.body;
    if (!file || !format) return res.status(400).json({ error: 'Missing file or format' });
    const input  = Buffer.from(file, 'base64');
    const output = await sharp(input)[format]({ quality: quality || 82 }).toBuffer();
    const saved  = Math.round((1 - output.length / input.length) * 100);
    res.json({
      image:          output.toString('base64'),
      format,
      original_size:  input.length,
      converted_size: output.length,
      saved_pct:      saved,
    });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`WPIO Remote Server running on :${PORT}`));
```

### `package.json`

```json
{
  "name": "wpio-remote-server",
  "version": "1.0.0",
  "main": "server.js",
  "scripts": { "start": "node server.js" },
  "dependencies": {
    "express": "^4.18.2",
    "sharp": "^0.33.0"
  }
}
```

## Deploy to Railway (free)

1. Push this folder to a GitHub repo
2. Go to [railway.app](https://railway.app) → New Project → Deploy from GitHub
3. Set env var: `WPIO_TOKEN=your-secret-token`
4. Copy the public URL into the plugin Settings → Remote Server URL
5. Click Test Connection in the plugin

## Security Notes

- Always set a strong `WPIO_TOKEN`
- Images are sent as base64 JSON over HTTPS
- The server does not store any images
- Rate limit with nginx/cloudflare if exposed publicly
