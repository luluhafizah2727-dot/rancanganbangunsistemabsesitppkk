# Deployment TP PKK Absensi

Dokumen ini adalah runbook deployment. Untuk pengenalan aplikasi dan development lokal, mulai dari [README utama](../README.md).

## Cakupan Deployment Bawaan

Contoh di repository menargetkan:

- Ubuntu 22.04/24.04.
- Apache dan PHP 8.3-FPM.
- MariaDB, Redis, cron, dan systemd.
- Aplikasi di `/var/www/tppkk-absensi`.
- Reverb pada loopback `127.0.0.1:8080`.

Laravel dan script persiapan database juga mendukung PostgreSQL. Namun, bootstrap Ubuntu, backup otomatis, phpMyAdmin, dan sebagian metadata service bawaan mengasumsikan MariaDB. Siapkan PostgreSQL dan mekanisme backupnya secara terpisah jika memilih PostgreSQL untuk production.

## Topologi yang Disarankan

```text
Internet/Client
      │ HTTPS
      v
TLS reverse proxy atau tunnel
      │ HTTP/WS jaringan internal
      v
Apache ──> frontend/dist
   ├─────> Laravel public/index.php
   └─────> Reverb 127.0.0.1:8080
              │
Laravel ──> MariaDB + Redis
```

File `deploy/apache/tppkk-remote.conf` tidak menyediakan sertifikat TLS. Jika `APP_URL` menggunakan HTTPS, TLS harus ditangani oleh reverse proxy, load balancer, tunnel, atau konfigurasi Apache HTTPS terpisah. Pastikan `TRUSTED_PROXIES` hanya berisi proxy yang benar-benar dipercaya.

## Persiapan Server Ubuntu

Jalankan bootstrap dari checkout repository yang sudah tersedia. Script ini mengubah konfigurasi sistem: memasang paket, menambahkan repository NodeSource, PPA PHP bila diperlukan, repository Cloudflare, serta mengaktifkan service Apache, PHP-FPM, MariaDB, Redis, dan cron. Tinjau script sebelum menjalankannya pada server yang sudah berisi layanan lain.

```bash
sudo scripts/bootstrap-ubuntu-server.sh
```

Script tersebut juga memasang phpMyAdmin dan `cloudflared`, tetapi keduanya bukan kebutuhan runtime utama aplikasi.

## Direktori Aplikasi

Contoh systemd dan cron menggunakan `/var/www/tppkk-absensi`:

```bash
sudo install -d -o "$USER" -g www-data -m 2750 /var/www/tppkk-absensi
```

Tempatkan repository di direktori tersebut. Jika memilih lokasi lain, sesuaikan semuanya:

- `APP_ROOT` pada `deploy/apache/tppkk-remote.conf` dan `deploy/apache/tppkk-local.conf`.
- `WorkingDirectory` pada `deploy/systemd/tppkk-*.service`.
- Path aplikasi dan backup pada `deploy/cron/tppkk`.
- Permission runtime dan command operasional pada dokumen ini.

## Environment Production

Buat `.env` di root repository:

```bash
cp .env.example .env
chmod 600 .env
```

Contoh nilai utama:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com
FRONTEND_URL=https://app.example.com
FRONTEND_URLS=https://app.example.com

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tppkk_absensi
DB_USERNAME=tppkk_app
DB_PASSWORD=ganti-dengan-password-kuat
DB_CREATE_DATABASE=true
DB_ADMIN_USE_SUDO=true
DB_ADMIN_USERNAME=root

SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=app.example.com
TRUSTED_PROXIES=127.0.0.1,::1

REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_API_URL=
VITE_REVERB_HOST=
VITE_REVERB_PORT=
VITE_REVERB_SCHEME=
```

Ganti `app.example.com` dan seluruh credential contoh. `SANCTUM_STATEFUL_DOMAINS` berisi hostname tanpa `https://`, sedangkan `FRONTEND_URLS` berisi origin lengkap.

## Instalasi Pertama Tanpa Data Demo

```bash
pnpm setup
sudo -v
pnpm database:prepare
scripts/deploy-production.sh
```

`pnpm database:prepare` hanya membuat database dan user. Migration dijalankan oleh `scripts/deploy-production.sh`. Jangan menjalankan `pnpm setup:database` di production karena command itu juga menjalankan seeder development.

Atur permission runtime:

```bash
sudo chown -R www-data:www-data backend/storage backend/bootstrap/cache
sudo chmod -R ug+rwX backend/storage backend/bootstrap/cache
```

## Apache dan Service

Sebelum menyalin konfigurasi, ubah `APP_ROOT` dan `APP_HOST` di bagian atas `deploy/apache/tppkk-remote.conf`.

```bash
sudo cp deploy/apache/tppkk-remote.conf /etc/apache2/sites-available/tppkk-remote.conf
sudo a2ensite tppkk-remote.conf

sudo cp deploy/systemd/tppkk-*.service /etc/systemd/system/
sudo cp deploy/cron/tppkk /etc/cron.d/tppkk
sudo systemctl daemon-reload
sudo systemctl enable --now tppkk-queue tppkk-reverb
sudo systemctl reload apache2
```

Periksa konfigurasi sebelum reload:

```bash
sudo apache2ctl configtest
sudo systemctl status tppkk-queue tppkk-reverb
```

Untuk deployment berikutnya:

```bash
scripts/deploy-production.sh
```

Untuk sekaligus me-restart queue, Reverb, dan Apache:

```bash
RESTART_SERVICES=true scripts/deploy-production.sh
```

## Deployment HTTP Jaringan Lokal

Konfigurasi `deploy/apache/tppkk-local.conf` menyajikan frontend, API, storage, dan Reverb pada satu port. Nilai `192.0.2.10` di file tersebut adalah alamat dokumentasi dan harus diganti dengan alamat server yang nyata.

Sesuaikan `APP_ROOT`, `APP_HOST`, dan `APP_PORT`, kemudian:

```bash
sudo a2enmod rewrite headers proxy proxy_http proxy_fcgi proxy_wstunnel setenvif expires deflate
sudo cp deploy/apache/tppkk-local.conf /etc/apache2/sites-available/tppkk-local.conf
sudo a2ensite tppkk-local.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Contoh `.env` jika host nyata adalah `192.168.1.10:8088`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.10:8088
FRONTEND_URL=http://192.168.1.10:8088
FRONTEND_URLS=http://192.168.1.10:8088
SANCTUM_STATEFUL_DOMAINS=192.168.1.10:8088
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=auto
VITE_API_URL=
```

HTTP jaringan lokal berguna untuk pengujian terbatas. Untuk pemindaian kamera dari ponsel, gunakan HTTPS karena browser umumnya membatasi API kamera ke secure context.

## phpMyAdmin Melalui SSH Tunnel

phpMyAdmin bersifat opsional. Konfigurasi bawaan hanya membuka akses pada loopback server.

Siapkan direktori runtime, PHP-FPM pool, konfigurasi phpMyAdmin, dan Apache site:

```bash
sudo install -d -o www-data -g www-data -m 700 /var/lib/php/sessions-phpmyadmin /var/lib/phpmyadmin/tmp
sudo cp deploy/php-fpm/phpmyadmin.conf /etc/php/8.3/fpm/pool.d/phpmyadmin.conf
sudo install -o root -g www-data -m 640 deploy/phpmyadmin/config.footer.inc.php /etc/phpmyadmin/config.footer.inc.php
sudo a2disconf phpmyadmin 2>/dev/null || true
sudo cp deploy/apache/phpmyadmin-loopback.conf /etc/apache2/sites-available/phpmyadmin-loopback.conf
sudo a2ensite phpmyadmin-loopback.conf
sudo apache2ctl configtest
sudo systemctl restart php8.3-fpm
sudo systemctl reload apache2
```

Buat tunnel dari komputer administrator:

```bash
ssh -L 8081:127.0.0.1:8081 SSH_USER@SERVER_HOST
```

Selama tunnel aktif, buka `http://localhost:8081`. Jangan membuka phpMyAdmin langsung melalui domain atau alamat jaringan publik.

## Backup dan Restore MariaDB

Cron bawaan menjalankan backup setiap hari pukul 02.17 dan menyimpan hasil selama 30 hari di `/var/backups/tppkk-absensi`.

Backup manual:

```bash
sudo scripts/backup-database.sh
```

Direktori dan retensi dapat diubah melalui `BACKUP_DIR` dan `BACKUP_RETENTION_DAYS`.

Restore contoh:

```bash
gunzip -c /var/backups/tppkk-absensi/tppkk_absensi-TIMESTAMP.sql.gz \
  | mariadb -h 127.0.0.1 -u tppkk_app -p tppkk_absensi
```

Uji prosedur restore secara berkala; backup yang belum pernah diuji belum dapat dianggap siap dipakai.

## Pemeriksaan Runtime

```bash
php backend/artisan about
php backend/artisan migrate:status
php backend/artisan schedule:list
curl https://app.example.com/api/v1/health
systemctl status apache2 php8.3-fpm mariadb redis-server tppkk-queue tppkk-reverb
```

Ganti domain contoh dengan domain deployment. Endpoint health memeriksa koneksi database dan Redis. Periksa juga login session, CSRF, CORS, kamera anggota, rotasi QR, dan WebSocket `/app` dari jaringan klien yang sebenarnya.
