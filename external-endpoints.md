# External API Endpoints WhatsApp dan Telegram

Dokumen ini menjelaskan cara memakai endpoint eksternal DRNet Gateway untuk WhatsApp dan Telegram. Bahasa dibuat praktis agar integrator pemula bisa mulai dari halaman dashboard, menyalin URL, lalu mengirim request pertama.

Endpoint ini berbeda dari endpoint dashboard `/api/*`. Endpoint eksternal memakai URL rahasia berbentuk `/ext/{secret}/...` dan ditujukan untuk sistem lain seperti billing, CRM, bot internal, worker otomatis, atau aplikasi backend milik integrator.

## 1. Konsep Dasar

- **External API harus aktif** di paket tenant. Capability yang dibutuhkan adalah `ext_api_enabled`.
- **Secret URL adalah credential.** Siapa pun yang memiliki URL `/ext/{secret}/...` bisa mencoba request ke endpoint tersebut. Simpan di environment backend, bukan di JavaScript frontend publik.
- **Channel terpisah.** WhatsApp memakai `/wa`, Telegram memakai `/tg`.
- **Auth tambahan opsional.** Secret URL selalu wajib, tetapi Anda bisa menambah Basic Auth, Custom Header, atau JWT dari panel `Authentication`.
- **Default auth hanya melindungi POST.** Jika endpoint GET/SSE juga harus dilindungi, aktifkan `Protect all methods`.

## 2. Cara Mengaktifkan Dari Dashboard

Langkah yang sama berlaku untuk:

- `https://gateway.drnet.biz.id/wa/endpoints`
- `https://gateway.drnet.biz.id/tg/endpoints`

Urutan yang disarankan:

1. Buka submenu **Endpoints** pada channel yang ingin dipakai.
2. Pada panel **Endpoint Status**, klik **Enable / Refresh**.
3. Salin URL dari panel **API URLs**.
4. Jika butuh proteksi tambahan, pilih mode auth di panel **Authentication** lalu klik **Save Auth**.
5. Coba contoh paling sederhana di panel **Examples**.
6. Jika ingin chatbot hanya aktif untuk kontak tertentu, gunakan panel **Chatbot Wiring**.

Endpoint URL berasal dari response `/api/subscription/ext`. Field yang dipakai UI:

| Field response | Dipakai untuk |
|---|---|
| `wa` / `wa_send` | WhatsApp POST Send |
| `wa_get` | WhatsApp GET Send |
| `wa_status` | WhatsApp status device |
| `wa_contacts` | WhatsApp Contacts |
| `wa_receive` | WhatsApp Receive SSE |
| `tg` / `tg_send` | Telegram POST Send |
| `tg_get` | Telegram GET Send |
| `tg_contacts` | Telegram Contacts |
| `tg_receive` | Telegram Receive SSE |
| `ai` | AI external endpoint |

## 3. Authentication

Semua request eksternal selalu memakai secret di path:

```text
https://<host>/ext/<secret>/wa
https://<host>/ext/<secret>/tg
```

Auth tambahan tersedia per channel:

| Mode | Cara pakai | Catatan |
|---|---|---|
| None | Tidak perlu header tambahan | Tetap pakai secret URL. Jangan bagikan URL ke publik. |
| Basic | `Authorization: Basic <base64(user:pass)>` | Cocok untuk integrasi sederhana server-to-server. |
| Header | Header custom, misalnya `X-API-Key: ...` | Bisa juga dikirim sebagai `Authorization: Bearer ...` jika opsi bearer aktif. |
| JWT | `Authorization: Bearer <token>` | Gunakan static token atau HS256 secret sesuai konfigurasi. |

Contoh Basic Auth:

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic <base64-user-pass>" \
  --data '{"action":"send","to":"628123456789","text":"Halo"}'
```

Contoh Custom Header:

```bash
curl -X POST "https://<host>/ext/<secret>/tg" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <token-integrator>" \
  --data '{"action":"send","chat_id":"123456789","text":"Halo"}'
```

## 4. Endpoint WhatsApp

URL yang muncul di panel WhatsApp Endpoints:

| Label UI | Method | URL | Fungsi |
|---|---|---|---|
| POST Send | `POST` | `/ext/{secret}/wa` | Kirim pesan, kelola kontak, request phone, commerce/order, dan Baileys action. |
| GET Send | `GET` | `/ext/{secret}/wa/send?to=&msg=` | Quick send text untuk sistem lama yang hanya bisa GET. |
| Status | `GET` | `/ext/{secret}/wa/status` | Cek device aktif, status link, dan kesiapan session. |
| Contacts | `GET` | `/ext/{secret}/wa/contacts?q=&page=&limit=` | Ambil kontak WhatsApp untuk autocomplete/sinkronisasi. |
| Receive SSE | `GET SSE` | `/ext/{secret}/wa/receive?device_id=&since=&limit=` | Stream pesan masuk via Server-Sent Events. |

### 4.1 Kirim Text WhatsApp

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "send",
    "to": "628123456789",
    "text": "Halo dari DRNet Gateway"
  }'
```

Nomor tujuan gunakan format internasional tanpa tanda plus. Contoh benar: `628123456789`.

### 4.2 Kirim Attachment WhatsApp Dari URL

Endpoint eksternal menerima JSON, bukan multipart upload. Jika file berasal dari sistem Anda, upload dulu ke storage yang bisa diakses gateway, lalu kirim URL publiknya.

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "send",
    "to": "628123456789",
    "attachment": {
      "type": "image",
      "url": "https://example.com/promo.jpg",
      "caption": "Promo hari ini"
    }
  }'
```

Type umum: `image`, `video`, `audio`, `document`, `sticker`.

### 4.3 Kirim Interactive WhatsApp

Template cocok untuk tombol URL, call, quick reply, dan copy code.

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "send",
    "to": "628123456789",
    "interactive": {
      "type": "template",
      "text": "Pilih aksi yang tersedia.",
      "footer": "DRNet Gateway",
      "buttons": [
        { "type": "quick", "text": "Tanya admin", "id": "ask_admin" },
        { "type": "url", "text": "Buka website", "url": "https://example.com" },
        { "type": "copy", "text": "Copy kode", "copyCode": "PROMO2026" }
      ]
    }
  }'
```

Catatan:

- Text yang wajib terlihat sebaiknya ada di `interactive.text`.
- Media header memakai `interactive.headerMedia.url` dan harus bisa diakses gateway.
- Kombinasi button tertentu bisa dibatasi oleh WhatsApp client atau Baileys.

### 4.4 Request Phone Number

Request phone meminta lawan bicara membagikan nomor. Nomor hanya bisa diproses jika WhatsApp/Baileys memberikan metadata yang valid.

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "send",
    "to": "628123456789",
    "requestPhoneNumber": true
  }'
```

### 4.5 Order atau Commerce Message

Order message memakai payload commerce/order WhatsApp. `View details` di WhatsApp client bergantung pada token/order/catalog yang valid.

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "send",
    "to": "628123456789",
    "order": {
      "orderId": "INV-2026-0001",
      "status": "ACCEPTED",
      "surface": "CATALOG",
      "message": "Pesanan berhasil diproses",
      "totalAmount1000": "150000000",
      "totalCurrencyCode": "IDR"
    }
  }'
```

### 4.6 Kelola Kontak WhatsApp

Tambah atau update kontak:

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "upsert",
    "contacts": [
      { "phone": "628123456789", "name": "Customer A", "labels": ["VIP"] }
    ]
  }'
```

Hapus kontak:

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "delete",
    "contacts": [
      { "phone": "628123456789" }
    ]
  }'
```

Ambil kontak:

```bash
curl "https://<host>/ext/<secret>/wa/contacts?q=customer&page=1&limit=50"
```

### 4.7 Quick Send GET WhatsApp

Gunakan GET hanya untuk text sederhana.

```bash
curl "https://<host>/ext/<secret>/wa/send?to=628123456789&msg=Halo%20dari%20integrasi"
```

### 4.8 Status Device WhatsApp

```bash
curl "https://<host>/ext/<secret>/wa/status"
```

Respons ringkas:

```json
{
  "ok": true,
  "status": "LINKED",
  "device_id": "wa-device-id",
  "session_ready": true
}
```

### 4.9 Receive SSE WhatsApp

SSE cocok untuk worker kecil yang ingin menerima pesan baru tanpa polling terus-menerus.

```bash
curl -N "https://<host>/ext/<secret>/wa/receive?device_id=<device_id>&since=0&limit=50"
```

Event `message` berisi data seperti:

```json
{
  "channel": "wa",
  "device_id": "wa-device-id",
  "id": "message-id",
  "jid": "628123456789@s.whatsapp.net",
  "body": "Halo",
  "status": "INBOUND",
  "created_at": 1782450000000,
  "direction": "inbound",
  "media": {}
}
```

Simpan cursor `created_at` atau `Last-Event-ID`, lalu kirim sebagai `since` saat reconnect.

### 4.10 Baileys Action

Gunakan hanya untuk operasi non-send seperti group metadata, presence, privacy, newsletter, dan action lain yang tersedia di runtime.

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "baileys",
    "baileys_action": "presence_update",
    "payload": { "type": "available" }
  }'
```

Untuk action kompleks, kirim `payload.args` sesuai urutan argumen method Baileys.

## 5. Endpoint Telegram

URL yang muncul di panel Telegram Endpoints:

| Label UI | Method | URL | Fungsi |
|---|---|---|---|
| POST Send | `POST` | `/ext/{secret}/tg` | Kirim text Telegram dan kelola kontak melalui action payload. |
| GET Send | `GET` | `/ext/{secret}/tg/send?chat_id=&msg=` | Quick send text untuk sistem lama yang hanya bisa GET. |
| Contacts | `GET` | `/ext/{secret}/tg/contacts?q=&page=&limit=` | Ambil kontak Telegram untuk autocomplete/sinkronisasi. |
| Receive SSE | `GET SSE` | `/ext/{secret}/tg/receive?bot_id=&chat_id=&since=&limit=` | Stream pesan Telegram masuk, termasuk event callback yang sudah tercatat gateway. |

Penting untuk Telegram external v1:

- `POST /ext/{secret}/tg` saat ini mendukung text send dan contacts mutation.
- Media upload, media URL, reply-to, dan inline keyboard tersedia di dashboard/internal authenticated API, tetapi belum menjadi contract endpoint eksternal secret ini.
- Jika integrasi eksternal Anda membutuhkan media atau inline keyboard Telegram melalui secret URL, backend external TG perlu diperluas terlebih dahulu.

### 5.1 Kirim Text Telegram

```bash
curl -X POST "https://<host>/ext/<secret>/tg" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "send",
    "chat_id": "123456789",
    "text": "Halo dari DRNet Gateway"
  }'
```

`chat_id` adalah ID private chat, group, atau channel yang sudah dikenal bot. Bot harus pernah menerima chat dari user tersebut atau sudah berada di group/channel tujuan.

### 5.2 Kelola Kontak Telegram

Tambah atau update kontak:

```bash
curl -X POST "https://<host>/ext/<secret>/tg" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "upsert",
    "contacts": [
      { "chat_id": "123456789", "name": "Support Group", "username": "support_group" }
    ]
  }'
```

Hapus kontak:

```bash
curl -X POST "https://<host>/ext/<secret>/tg" \
  -H "Content-Type: application/json" \
  --data '{
    "action": "delete",
    "contacts": [
      { "chat_id": "123456789" }
    ]
  }'
```

Ambil kontak:

```bash
curl "https://<host>/ext/<secret>/tg/contacts?q=support&page=1&limit=50"
```

### 5.3 Quick Send GET Telegram

Gunakan GET hanya untuk text sederhana.

```bash
curl "https://<host>/ext/<secret>/tg/send?chat_id=123456789&msg=Halo%20dari%20integrasi"
```

### 5.4 Receive SSE Telegram

```bash
curl -N "https://<host>/ext/<secret>/tg/receive?bot_id=<bot_id>&since=0&limit=50"
```

Filter chat tertentu:

```bash
curl -N "https://<host>/ext/<secret>/tg/receive?bot_id=<bot_id>&chat_id=123456789&since=0"
```

Event `message` berisi data seperti:

```json
{
  "channel": "tg",
  "bot_id": "bot-id",
  "chat_id": "123456789",
  "id": "message-id",
  "body": "Halo",
  "status": "INBOUND",
  "created_at": 1782450000000,
  "direction": "inbound",
  "media": {}
}
```

Jika gateway menerima callback query dari Telegram, event dapat memiliki `media.type = "callback_query"`. `callback_data` Telegram dibatasi maksimal 64 byte.

## 6. Response dan Error Umum

Respons sukses send:

```json
{
  "ok": true,
  "success": true,
  "id": "uuid",
  "message": "Pesan terkirim",
  "to": "target",
  "msg": "Halo",
  "attempts": 1
}
```

Respons queued WhatsApp:

```json
{
  "ok": true,
  "queued": true,
  "id": "uuid",
  "to": "628123456789@s.whatsapp.net",
  "msg": "Halo",
  "attempts": 0,
  "next_retry_at": 1782450000000
}
```

Error yang sering muncul:

| HTTP | code | Arti | Cara cek |
|---|---|---|---|
| 400 | `INVALID_INPUT` | Field wajib kosong atau format salah | Cek `to`/`chat_id`, `text`, atau action. |
| 401 | `AUTH_REQUIRED` / `AUTH_INVALID` | Header auth kurang atau salah | Cocokkan panel Authentication. |
| 402 | `PREMIUM_REQUIRED` | Plan belum punya External API | Cek Subscription/Plan. |
| 404 | `NOT_FOUND` | Secret tidak valid | Enable/Refresh endpoint atau rotate secret. |
| 404 | `NO_DEVICE` / `NO_BOT` | Tidak ada device/bot aktif | Cek halaman Devices/Bots. |
| 409 | `DEVICE_NOT_LINKED` | Device WA belum linked | Scan/relink WhatsApp. |
| 422 | `NUMBER_NOT_REGISTERED` | Nomor bukan WhatsApp aktif | Verifikasi nomor tujuan. |
| 429 | `RATE_LIMIT` | Terlalu banyak request | Tambahkan retry/backoff. |
| 500 | `SEND_FAILED` / `DB_ERROR` | Gangguan internal atau provider | Cek Log dan retry dengan idempotency. |

## 7. Retry, Idempotency, dan Keamanan

- Untuk request bisnis yang bisa diulang, kirim `Idempotency-Key` header agar retry tidak membuat pesan ganda.
- Untuk WhatsApp yang boleh asynchronous, pakai `queue: true` atau `auto_retry: true`.
- Jangan hardcode secret di repository.
- Jangan panggil endpoint secret dari browser publik.
- Rotate secret jika URL pernah bocor.
- Jika memakai GET/SSE pada sistem produksi, aktifkan `Protect all methods`.

Contoh idempotency:

```bash
curl -X POST "https://<host>/ext/<secret>/wa" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: invoice-2026-0001" \
  --data '{"action":"send","to":"628123456789","text":"Invoice INV-2026-0001"}'
```

## 8. Troubleshooting Cepat

- **401/403:** secret benar tetapi auth salah. Cek Basic/Header/JWT dan opsi `Protect all methods`.
- **GET berhasil tanpa auth padahal POST memakai auth:** aktifkan `Protect all methods`.
- **WA media gagal:** URL media harus publik dan MIME/type sesuai.
- **WA device tidak siap:** cek `/wa/devices`, relink jika perlu, atau pakai `auto_retry`.
- **Telegram chat_id gagal:** pastikan bot sudah pernah berinteraksi dengan user atau sudah berada di group/channel.
- **SSE tidak ada event:** mulai dengan `since=0` untuk test, lalu simpan cursor terakhir untuk produksi.
- **Event dobel saat reconnect:** gunakan `Last-Event-ID` atau `since` dari event terakhir yang sudah diproses.
- **Callback Telegram tidak muncul:** pastikan callback query memang dikirim oleh bot/gateway dan `callback_data` tidak melewati 64 byte.
