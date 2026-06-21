# Absensi Harian TP PKK Balangan

Aplikasi absensi harian berbasis web untuk Super Admin, Operator, Anggota, dan layar Gawai. Seluruh waktu aplikasi menggunakan `Asia/Makassar` (WITA).

## Fitur Utama

- Jadwal check-in/check-out mingguan dan pengecualian tanggal.
- QR Gawai yang berubah otomatis setiap 10 detik.
- Pengelolaan anggota, akun, role, perangkat anggota, dan Gawai.
- Permohonan koreksi, izin, cuti, sakit, dinas, dan persetujuan admin.
- Kehadiran harian, laporan PDF/XLSX, preview laporan, dan Log.
- Session login, credential Gawai, binding perangkat anggota, Redis queue, dan Reverb.

## Kebutuhan

### Development

- Linux Ubuntu 22.04/24.04 atau sistem setara.
- PHP `8.3+` beserta `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `redis`, `tokenizer`, `xml`, dan `zip`.
- Driver PHP `pdo_mysql` untuk MariaDB/MySQL atau `pdo_pgsql` untuk PostgreSQL.
- Composer `2.7+`.
- Node.js `24+` dan pnpm `11+`.
- MariaDB `10.6+` atau PostgreSQL `16+`.
- Redis `7+`.

Periksa mesin saat ini:

```bash
pnpm check:requirements
```

### Production

Selain kebutuhan di atas, siapkan Apache atau Nginx, PHP-FPM, service manager (`systemd`), cron, TLS/reverse proxy, dan ruang backup database. Konfigurasi deployment proyek berada di `deploy/`.

## Environment Terpusat

Backend dan frontend memakai satu file `.env` di root. Jangan membuat `backend/.env` atau `frontend/.env`.

```bash
cp .env.example .env
chmod 600 .env
```

Nilai penting untuk development MariaDB:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:5173
FRONTEND_URL=http://localhost:5173
FRONTEND_URLS=http://localhost:5173,http://127.0.0.1:5173

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tppkk_absensi
DB_USERNAME=tppkk_app
DB_PASSWORD=password-database

DB_CREATE_DATABASE=true
DB_ADMIN_USE_SUDO=true
DB_ADMIN_USERNAME=root

SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=auto
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
TRUSTED_PROXIES=127.0.0.1,::1

VITE_API_URL=
VITE_ALLOWED_HOSTS=absensi.kapul.my.id,absensi.dev.kapul.my.id
```

Untuk PostgreSQL, ubah bagian database:

```env
DB_CONNECTION=pgsql
DB_PORT=5432
DB_ADMIN_USERNAME=postgres
DB_ADMIN_DATABASE=postgres
```

`SESSION_SECURE_COOKIE=auto` memberi flag `Secure` pada HTTPS dan tetap mengizinkan HTTP jaringan lokal. Untuk production HTTPS-only, nilai dapat diubah menjadi `true`.

## Install Pertama Kali

```bash
pnpm setup
```

Perintah tersebut akan:

1. Membuat `.env` dari `.env.example` bila belum tersedia.
2. Memasang dependency Composer dan pnpm dari lockfile.
3. Membuat `APP_KEY` serta credential Reverb.
4. Membuat storage link.

Setelah koneksi database di `.env` benar:

```bash
pnpm setup:database
```

Database dan user dapat dibuat otomatis dari parameter `DB_*`. Pada mesin yang memakai autentikasi sudo:

```bash
SUDO_PASSWORD='password-sudo' pnpm setup:database
```

Password tersebut hanya digunakan oleh proses saat itu dan tidak ditulis ke source.

## Menjalankan Development

Jalankan seluruh service:

```bash
pnpm dev:services
```

Service internal:

- Laravel API: `127.0.0.1:8000`
- Vite: `VITE_DEV_HOST:VITE_DEV_PORT` (default `127.0.0.1:5173`)
- Reverb: `127.0.0.1:8080`
- Queue dan scheduler berjalan pada terminal yang sama.

Vite mem-proxy `/api`, `/sanctum`, `/broadcasting`, `/storage`, dan `/app`, sehingga browser cukup membuka `http://localhost:5173`.

Jika domain development menampilkan `Blocked request`, tambahkan hostname yang benar ke `VITE_ALLOWED_HOSTS`. Jangan memakai wildcard atau `allowedHosts=true`.

## Akun Awal

| Role | ID pengguna | Password awal |
| --- | --- | --- |
| Super Admin | `admin` | `ChangeMe123!` |
| Operator | `operator` | `Operator123!` |
| Anggota demo | `220340096` | `MemberDemo123!` |

Seluruh akun seed ditandai wajib mengganti password. Ganti password sebelum aplikasi dipakai untuk data nyata.

## Alur Uji Cepat

1. Login Super Admin dan periksa jadwal di Pengaturan.
2. Buka menu Gawai, pilih record Gawai, lalu buat kode aktivasi.
3. Buka `/gawai` pada browser layar dan masukkan kode tersebut.
4. Login sebagai anggota, ajukan binding perangkat bila diminta, lalu setujui dari panel admin.
5. Pindai QR ketika rentang check-in/check-out aktif.
6. Periksa Kehadiran, Laporan, dan Log.

Jika sedang di luar jadwal, buat pengecualian sementara untuk tanggal hari ini, lalu hapus setelah pengujian.

## Verifikasi Source

```bash
pnpm check:requirements
pnpm lint
pnpm test
pnpm build
pnpm --dir frontend e2e
```

## Build dan Deployment Aplikasi

Script berikut memasang dependency production, membangun frontend, menjalankan migrasi, mengoptimalkan Laravel, dan menginterupsi scheduler lama:

```bash
scripts/deploy-production.sh
```

Untuk sekaligus restart service:

```bash
RESTART_SERVICES=true scripts/deploy-production.sh
```

Permission runtime:

```bash
sudo chown -R www-data:www-data backend/storage backend/bootstrap/cache
sudo chmod -R ug+rwX backend/storage backend/bootstrap/cache
```

## Deployment Lokal Single-Port

Deployment lokal menggunakan source di `/home/robert/tppkk-absensi`, Apache port `8088`, dan database PostgreSQL yang sudah ada.

```bash
sudo apt install php8.3-fpm
sudo a2enmod rewrite headers proxy proxy_http proxy_fcgi proxy_wstunnel setenvif expires deflate
sudo cp deploy/apache/tppkk-local.conf /etc/apache2/sites-available/tppkk-local.conf
sudo a2ensite tppkk-local.conf
sudo systemctl reload apache2
```

Isi URL lokal di root `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://20.20.20.2:8088
FRONTEND_URL=http://20.20.20.2:8088
FRONTEND_URLS=http://20.20.20.2:8088
SANCTUM_STATEFUL_DOMAINS=20.20.20.2:8088
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=auto
VITE_API_URL=
```

Buka `http://20.20.20.2:8088` dari jaringan ZeroTier.

## Deployment Ubuntu 20.20.20.21

Server target memakai Ubuntu 22.04 dan aplikasi berada di `/var/www/tppkk-absensi`.

### 1. Install requirement

```bash
sudo scripts/bootstrap-ubuntu-server.sh
```

Script memasang Apache, PHP 8.3-FPM, ekstensi PHP, MariaDB, Redis, Composer, Node 24, pnpm, cron, dan phpMyAdmin.

### 2. Konfigurasi production

Contoh nilai root `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://absensi.kapul.my.id
FRONTEND_URL=https://absensi.kapul.my.id
FRONTEND_URLS=https://absensi.kapul.my.id,http://20.20.20.21

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tppkk_absensi
DB_USERNAME=tppkk_app
DB_PASSWORD=password-kuat-database
DB_CREATE_DATABASE=true
DB_ADMIN_USE_SUDO=true
DB_ADMIN_USERNAME=root

SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=auto
SANCTUM_STATEFUL_DOMAINS=absensi.kapul.my.id,20.20.20.21
TRUSTED_PROXIES=127.0.0.1,::1

REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_API_URL=
VITE_REVERB_HOST=
VITE_REVERB_PORT=
VITE_REVERB_SCHEME=
```

### 3. Database dan build

```bash
pnpm setup
SUDO_PASSWORD='password-sudo' pnpm setup:database
CONFIGURE_PHPMYADMIN_BASIC_AUTH=true scripts/deploy-production.sh
```

### 4. Apache dan service

```bash
sudo cp deploy/apache/tppkk-remote.conf /etc/apache2/sites-available/tppkk-remote.conf
sudo a2ensite tppkk-remote.conf
sudo cp deploy/systemd/tppkk-*.service /etc/systemd/system/
sudo cp deploy/cron/tppkk /etc/cron.d/tppkk
sudo systemctl daemon-reload
sudo systemctl enable --now tppkk-queue tppkk-reverb
sudo systemctl reload apache2
```

Apache melayani IP pada port 80 dan origin Cloudflare pada `127.0.0.1:5173`. Reverb hanya tersedia internal pada `127.0.0.1:8080`.

Jika interface ZeroTier memakai MTU `2800` di atas jalur fisik `1500`, pasang unit MTU agar respons cookie/CSRF tidak tertahan oleh fragmentasi:

```bash
sudo cp deploy/systemd/zerotier-mtu@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now zerotier-mtu@NAMA_INTERFACE.service
```

## phpMyAdmin Remote

phpMyAdmin dapat dibuka melalui:

- `https://absensi.kapul.my.id/phpmyadmin`
- `http://20.20.20.21/phpmyadmin`

Sebelum login database, browser akan meminta **Basic Auth** Apache. Username default adalah `pmaadmin`. Password Basic Auth dibuat oleh `scripts/deploy-production.sh` saat `CONFIGURE_PHPMYADMIN_BASIC_AUTH=true` dan disimpan di server pada `/root/tppkk-phpmyadmin-basic-auth-password`. File password ini tidak boleh dicatat di repo atau dibagikan terbuka.

Konfigurasi server:

```bash
sudo install -d -o www-data -g www-data -m 700 /var/lib/php/sessions-phpmyadmin /var/lib/phpmyadmin/tmp
sudo cp deploy/php-fpm/phpmyadmin.conf /etc/php/8.3/fpm/pool.d/phpmyadmin.conf
sudo install -o root -g www-data -m 640 deploy/phpmyadmin/config.footer.inc.php /etc/phpmyadmin/config.footer.inc.php
sudo a2disconf phpmyadmin 2>/dev/null || true
CONFIGURE_PHPMYADMIN_BASIC_AUTH=true scripts/deploy-production.sh
sudo systemctl restart php8.3-fpm
sudo systemctl reload apache2
```

Login phpMyAdmin memakai `DB_USERNAME` dan `DB_PASSWORD` dari `.env` remote. User aplikasi hanya memiliki hak pada database `tppkk_absensi`.

Jika tetap ingin akses tunnel lokal, gunakan:

```bash
ssh -L 8081:127.0.0.1:8081 robert@20.20.20.21
```

Selama SSH aktif, buka `http://localhost:8081` bila site loopback phpMyAdmin juga diaktifkan.

## Backup dan Restore MariaDB

Cron production menjalankan backup setiap hari pukul 02.17 dan menyimpan hasil selama 30 hari di `/var/backups/tppkk-absensi`.

Backup manual:

```bash
sudo scripts/backup-database.sh
```

Restore:

```bash
gunzip -c /var/backups/tppkk-absensi/tppkk_absensi-TIMESTAMP.sql.gz \
  | mariadb -h 127.0.0.1 -u tppkk_app -p tppkk_absensi
```

## Pemeriksaan Runtime

```bash
php backend/artisan about
php backend/artisan migrate:status
php backend/artisan schedule:list
curl http://20.20.20.21/api/v1/health
systemctl status apache2 php8.3-fpm mariadb redis-server tppkk-queue tppkk-reverb
```

Pada domain production, periksa juga cookie, CSRF, CORS, WebSocket `/app`, dan `https://absensi.kapul.my.id/api/v1/health`.

## Catatan Keamanan

- Jangan commit `.env`, password, token Cloudflare, credential database, dependency, build, cache, atau hasil test.
- Credential Gawai dan perangkat anggota hanya disimpan sebagai hash di database.
- phpMyAdmin hanya boleh diakses melalui SSH tunnel.
- Logo aplikasi berada di `frontend/public/tp-pkk-logo.png` dan `backend/public/tp-pkk-logo.png`.

## Lisensi

Kode aplikasi menggunakan [MIT License](LICENSE).
