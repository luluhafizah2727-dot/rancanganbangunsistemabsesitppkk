# Absensi Harian TP PKK Balangan

Aplikasi web untuk mencatat kehadiran harian anggota. Gawai terdaftar menampilkan QR sesuai jadwal, anggota memindainya melalui akun sendiri, dan Super Admin mengelola hasil serta permohonan koreksi.

## Fitur

- Anggota, persetujuan akun, foto profil, dan import CSV/XLSX.
- Jadwal mingguan serta pengecualian hari libur atau jam khusus.
- QR Gawai yang diperbarui setiap 10 detik.
- Check-in, check-out, izin, cuti, sakit, dinas, dan alpa.
- Permohonan koreksi atau ketidakhadiran dengan approval Super Admin.
- Laporan PDF/XLSX serta Log perubahan.

## Kebutuhan

Project ini tidak memakai `requirements.txt` karena itu umumnya dipakai untuk Python. Dependency aplikasi dikunci oleh:

- `backend/composer.json` dan `backend/composer.lock` untuk Laravel/PHP.
- `package.json`, `frontend/package.json`, dan `pnpm-lock.yaml` untuk Node/React.
- `backend/.env.example` dan `frontend/.env.example` untuk contoh konfigurasi.
- `scripts/check-requirements.sh` untuk memeriksa kesiapan mesin.
- `scripts/prepare-database.sh` untuk membuat database awal dari isi `.env`.

Jalankan pemeriksaan berikut sebelum install atau deployment:

```bash
pnpm check:requirements
```

Untuk development lokal:

- Linux, macOS, atau Windows dengan WSL.
- PHP 8.3+ dengan Composer.
- Node.js 24+ dan pnpm.
- MariaDB/MySQL 10.6+/8.0+ atau PostgreSQL 16. MariaDB menjadi contoh utama karena mudah dicek lewat phpMyAdmin.
- Redis 7.
- OpenSSL.

Ekstensi PHP yang perlu aktif:

- Umum: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `redis`, `tokenizer`, `xml`, dan `zip`.
- Database: `pdo_mysql` untuk MariaDB/MySQL, atau `pdo_pgsql` untuk PostgreSQL.

Port internal development: API `8000`, frontend `5173`, dan Reverb `8080`.
Untuk uji satu alamat, gunakan reverse proxy lokal pada `http://localhost:8088`.

Untuk server yang stabil:

- Ubuntu 24.04 atau Debian 12 lebih disarankan.
- PHP 8.4-FPM disarankan untuk production, PHP 8.3 masih dapat dipakai selama dependency terpenuhi.
- Nginx 1.24+ atau Apache 2.4+ dengan reverse proxy dan WebSocket aktif.
- Supervisor atau systemd untuk menjaga queue, scheduler, dan Reverb tetap berjalan.
- Cron setiap menit untuk `php artisan schedule:run`, atau jalankan `php artisan schedule:work` lewat process manager.
- HTTPS wajib untuk server publik agar cookie login dan cookie Gawai aman.
- Waktu server harus sinkron lewat NTP; aplikasi memakai zona waktu `Asia/Makassar`.
- Minimal uji coba kecil: 2 vCPU, RAM 4 GB, SSD 20 GB. Untuk penggunaan banyak anggota bersamaan, gunakan 4 vCPU, RAM 8 GB, dan SSD yang lebih lega.
- Siapkan backup database harian dan lokasi backup terpisah dari server aplikasi.

Contoh paket Ubuntu/Debian yang biasanya dibutuhkan:

```bash
sudo apt update
sudo apt install -y \
  git curl unzip ca-certificates supervisor cron \
  nginx redis-server \
  php-cli php-fpm php-bcmath php-curl php-gd php-intl php-mbstring \
  php-xml php-zip php-pgsql php-mysql php-redis
```

Tambahkan salah satu database:

```bash
# MariaDB/MySQL
sudo apt install -y mariadb-server mariadb-client

# atau PostgreSQL
sudo apt install -y postgresql postgresql-contrib postgresql-client
```

Node.js 24 dapat dipasang dari paket resmi NodeSource atau pengelola versi seperti `nvm`. Setelah Node aktif, pasang pnpm:

```bash
corepack enable
corepack prepare pnpm@latest --activate
```

Pengaturan PHP yang disarankan:

```ini
memory_limit=256M
upload_max_filesize=8M
post_max_size=10M
max_execution_time=60
opcache.enable=1
opcache.enable_cli=1
```

Folder `backend/storage` dan `backend/bootstrap/cache` harus bisa ditulis oleh user webserver atau process manager.

Service yang harus hidup saat runtime:

| Service | Fungsi |
| --- | --- |
| PHP-FPM atau `artisan serve` | Menjalankan API Laravel. Production sebaiknya PHP-FPM. |
| Webserver | Menyajikan React build dan meneruskan `/api` serta WebSocket. |
| Database | Menyimpan akun, jadwal, kehadiran, log, dan permohonan. |
| Redis | Cache, session, queue, lock rotasi QR, dan realtime. |
| Queue worker | Memproses pekerjaan background. |
| Scheduler | Membentuk hari absensi, memperbarui status, dan rotasi QR. |
| Reverb | Koneksi realtime untuk Gawai dan dashboard. |

## Install Pertama Kali

1. Periksa kebutuhan mesin:

```bash
pnpm check:requirements
```

Jika perintah ini gagal, lengkapi bagian yang masih `FAIL` terlebih dahulu.

2. Jalankan setup dari folder project:

```bash
pnpm setup
```

Perintah ini memasang dependency, membuat `backend/.env` dan `frontend/.env`, serta menghasilkan key aplikasi dan Reverb. File `.env` yang sudah ada tidak ditimpa.

3. Isi koneksi database pada `backend/.env`.

Untuk MariaDB/MySQL:

```env
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tppkk_absensi
DB_USERNAME=tppkk_app
DB_PASSWORD=ganti-password-ini
```

Untuk PostgreSQL:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=tppkk_absensi
DB_USERNAME=tppkk_app
DB_PASSWORD=ganti-password-ini
DB_TIMEZONE=Asia/Makassar
```

4. Jika ingin `pnpm setup:database` membuat database dan user secara otomatis, isi kredensial admin database di `backend/.env`.

Untuk MariaDB/MySQL lokal:

```env
DB_CREATE_DATABASE=true
DB_ADMIN_USE_SUDO=false
DB_ADMIN_USERNAME=root
DB_ADMIN_PASSWORD=password-root-mysql
```

Untuk PostgreSQL lokal:

```env
DB_CREATE_DATABASE=true
DB_ADMIN_USE_SUDO=false
DB_ADMIN_USERNAME=postgres
DB_ADMIN_PASSWORD=password-admin-postgres
DB_ADMIN_DATABASE=postgres
```

Jika admin database lokal hanya bisa dipakai lewat `sudo`, gunakan:

```env
DB_CREATE_DATABASE=true
DB_ADMIN_USE_SUDO=true
DB_ADMIN_USERNAME=root
DB_ADMIN_PASSWORD=
```

Untuk PostgreSQL dengan `sudo -u postgres psql`, set `DB_ADMIN_USE_SUDO=true`, `DB_ADMIN_USERNAME=postgres`, dan `DB_ADMIN_DATABASE=postgres`.

Saat menjalankan dari terminal biasa, `sudo` akan meminta password seperti biasa. Untuk deploy otomatis tanpa prompt, kirim password hanya melalui environment sekali jalan:

```bash
SUDO_PASSWORD='password-sudo-anda' pnpm setup:database
```

Jangan tulis password sudo ke file `.env`.

Jika database dan user sudah dibuat manual, bagian `DB_ADMIN_*` boleh kosong. Perintah `pnpm setup:database` akan langsung lanjut ke migrasi bila user aplikasi sudah bisa terhubung.

Contoh SQL manual bila tidak ingin memakai kredensial admin di `.env`:

```sql
-- MariaDB/MySQL
CREATE DATABASE tppkk_absensi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tppkk_app'@'localhost' IDENTIFIED BY 'ganti-password-ini';
GRANT ALL PRIVILEGES ON tppkk_absensi.* TO 'tppkk_app'@'localhost';
FLUSH PRIVILEGES;

-- PostgreSQL
CREATE USER tppkk_app WITH PASSWORD 'ganti-password-ini';
CREATE DATABASE tppkk_absensi OWNER tppkk_app;
```

5. Atur URL lokal pada `backend/.env` bila belum sesuai:

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
FRONTEND_URLS=http://localhost:5173,http://127.0.0.1:5173,http://localhost:8088,http://127.0.0.1:8088
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8088,127.0.0.1:8088
SESSION_DOMAIN=localhost
SESSION_SECURE_COOKIE=false
REVERB_HOST=localhost
REVERB_PORT=8080
```

Untuk uji single-port lokal, `APP_URL` dan `FRONTEND_URL` boleh diganti menjadi `http://localhost:8088`.
Untuk server HTTPS, gunakan domain asli dan set `SESSION_SECURE_COOKIE=true`.

6. Pastikan Redis berjalan, lalu siapkan database, tabel, dan data awal dengan satu perintah:

```bash
pnpm setup:database
```

Perintah ini membaca `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` dari `backend/.env`. Bila database belum bisa diakses, script akan memakai `DB_ADMIN_USERNAME` dan `DB_ADMIN_PASSWORD` untuk membuat database serta memberi akses ke user aplikasi. Setelah itu Laravel menjalankan migrasi dan seed.

Data awal menggunakan Senin-Jumat, jam masuk `08.00`, jam pulang `16.00`, serta rentang pemindaian 30 menit sebelum dan sesudahnya.

## Akun Awal

| Peran | ID pengguna | Password |
| --- | --- | --- |
| Super Admin | `admin` | `ChangeMe123!` |
| Operator | `operator` | `Operator123!` |
| Anggota demo | `220340096` | `MemberDemo123!` |

Password awal hanya untuk development dan wajib diganti sebelum aplikasi digunakan di luar komputer lokal. Nilai Super Admin dapat diatur melalui `SEED_ADMIN_LOGIN_ID` dan `SEED_ADMIN_PASSWORD` di `backend/.env`.

## Menjalankan Aplikasi

Single-port berarti pengguna cukup membuka satu alamat. Service internal tetap berjalan terpisah karena API, frontend, queue, scheduler, dan WebSocket punya tugas berbeda.

### Mode cepat: langsung dari Vite

Jalankan semua service internal:

```bash
pnpm dev:services
```

Buka `http://localhost:5173`.

Pada mode ini Vite meneruskan `/api`, `/sanctum`, `/broadcasting`, `/storage`, dan `/app` ke service internal. Jika ingin debug backend langsung tanpa proxy Vite, isi `frontend/.env`:

```env
VITE_API_URL=http://localhost:8000
```

### Mode single-port lokal: lewat reverse proxy

Jalankan service internal:

```bash
pnpm dev:services
```

Lalu jalankan Nginx atau Apache lokal pada port `8088`. Setelah itu cukup buka:

```text
http://localhost:8088
```

Route proxy development:

| Path publik | Diteruskan ke |
| --- | --- |
| `/` | Vite `127.0.0.1:5173` |
| `/api`, `/sanctum`, `/broadcasting`, `/storage` | Laravel `127.0.0.1:8000` |
| `/app` | Reverb `127.0.0.1:8080` |

Contoh Nginx lokal:

```nginx
server {
    listen 8088;
    server_name localhost 127.0.0.1;

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location ~ ^/(api|sanctum|broadcasting|storage)(/|$) {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location / {
        proxy_pass http://127.0.0.1:5173;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Contoh Apache lokal:

```apache
Listen 8088

<VirtualHost *:8088>
    ServerName localhost
    ProxyPreserveHost On

    ProxyPass /app ws://127.0.0.1:8080/app
    ProxyPassReverse /app ws://127.0.0.1:8080/app

    ProxyPass /api http://127.0.0.1:8000/api
    ProxyPassReverse /api http://127.0.0.1:8000/api
    ProxyPass /sanctum http://127.0.0.1:8000/sanctum
    ProxyPassReverse /sanctum http://127.0.0.1:8000/sanctum
    ProxyPass /broadcasting http://127.0.0.1:8000/broadcasting
    ProxyPassReverse /broadcasting http://127.0.0.1:8000/broadcasting
    ProxyPass /storage http://127.0.0.1:8000/storage
    ProxyPassReverse /storage http://127.0.0.1:8000/storage

    ProxyPass / http://127.0.0.1:5173/
    ProxyPassReverse / http://127.0.0.1:5173/
</VirtualHost>
```

Modul Apache yang dibutuhkan: `proxy`, `proxy_http`, `proxy_wstunnel`, `headers`, dan `rewrite`.

Jika tidak memakai helper, service internal bisa dijalankan manual:

```bash
# API
php backend/artisan serve --host=127.0.0.1 --port=8000

# Frontend
pnpm --dir frontend dev

# Queue
php backend/artisan queue:work redis

# Jadwal harian dan pembaruan QR
php backend/artisan schedule:work

# Realtime
php backend/artisan reverb:start --host=127.0.0.1 --port=8080
```

## Coba Alur Utama

1. Masuk sebagai Super Admin dan periksa jadwal pada **Pengaturan**.
2. Bila sedang di luar jam absensi, buat **Pengecualian Tanggal** untuk hari ini.
3. Buka **Gawai**, tambahkan satu record, lalu buat kode aktivasi.
4. Buka `/gawai` pada layar yang akan digunakan, misalnya `http://localhost:8088/gawai` untuk mode single-port, lalu masukkan kode tersebut.
5. Masuk sebagai anggota pada browser lain, lalu pindai QR.
6. Anggota dapat membuka **Permohonan** untuk mengajukan koreksi, izin, cuti, sakit, atau dinas.
7. Saat pertama kali membuka **Scan QR**, anggota mengajukan perangkat yang dipakai. Super Admin menyetujui dari menu **Akun > Perangkat Anggota**.
8. Super Admin meninjau pengajuan kehadiran melalui menu **Permohonan**. Operator hanya dapat melihat.

Satu record Gawai hanya berlaku untuk satu browser/perangkat. Aktivasi bertahan hingga 400 hari, diperpanjang saat rutin tersambung, dan langsung berhenti ketika dicabut.

Aturan perangkat anggota berada di **Akun > Perangkat Anggota**. Mode **Perlu persetujuan** lebih aman karena perangkat baru harus disetujui sebelum bisa scan. Mode **Audit saja** memudahkan uji coba karena scan tetap bisa, tetapi perangkat baru tetap dicatat di Log.

## Pemeriksaan

```bash
pnpm lint
pnpm test
pnpm build
pnpm --dir frontend e2e
```

Jika Chromium Playwright belum tersedia, jalankan `pnpm --dir frontend exec playwright install chromium`.

Untuk mode single-port lokal, cek juga:

```bash
curl http://localhost:8088/api/v1/health
```

Lalu login dari `http://localhost:8088` dan pastikan browser tidak menampilkan error CORS, CSRF, mixed content, atau WebSocket.

## Build Production

Sebelum build production, pastikan command berikut lulus:

```bash
pnpm check:requirements
pnpm lint
pnpm test
pnpm build
```

Pada server production, install dependency dari lockfile:

```bash
composer install --working-dir=backend --no-dev --optimize-autoloader
pnpm install --frozen-lockfile
pnpm build
php backend/artisan migrate --force
php backend/artisan optimize
```

Pastikan permission folder benar:

```bash
sudo chown -R www-data:www-data backend/storage backend/bootstrap/cache
sudo chmod -R ug+rwX backend/storage backend/bootstrap/cache
```

Variabel penting pada `backend/.env` production:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://absensi.example.test
FRONTEND_URL=https://absensi.example.test
FRONTEND_URLS=https://absensi.example.test
SANCTUM_STATEFUL_DOMAINS=absensi.example.test
SESSION_DOMAIN=absensi.example.test
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REVERB_HOST=absensi.example.test
REVERB_PORT=443
REVERB_SCHEME=https
```

Pastikan `frontend/.env` production tidak memaksa alamat API terpisah:

```env
VITE_API_URL=
VITE_REVERB_HOST=
VITE_REVERB_PORT=
VITE_REVERB_SCHEME=
```

Jalankan queue, scheduler, dan Reverb menggunakan Supervisor atau systemd. Gunakan HTTPS agar cookie Gawai berstatus `Secure`.

Contoh Supervisor ringkas:

```ini
[program:tppkk-queue]
command=php /var/www/tppkk-absensi/backend/artisan queue:work redis --sleep=1 --tries=3 --timeout=90
directory=/var/www/tppkk-absensi
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/tppkk-queue.log

[program:tppkk-reverb]
command=php /var/www/tppkk-absensi/backend/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/tppkk-absensi
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/tppkk-reverb.log
```

Cron scheduler:

```cron
* * * * * cd /var/www/tppkk-absensi && php backend/artisan schedule:run >> /dev/null 2>&1
```

Gunakan `schedule:work` lewat Supervisor hanya bila server memang disiapkan untuk proses long-running dan tidak memakai cron.

Setelah deployment, cek:

```bash
php backend/artisan about
php backend/artisan migrate:status
curl -I https://absensi.example.test
```

### Contoh Nginx

Contoh ini memakai satu domain publik. React disajikan sebagai file static, Laravel dipanggil lewat PHP-FPM, dan Reverb tetap berjalan internal di `127.0.0.1:8080`.

```nginx
server {
    server_name absensi.example.test;
    root /var/www/tppkk-absensi/frontend/dist;
    index index.html;

    location / {
        try_files $uri /index.html;
    }

    location /storage/ {
        alias /var/www/tppkk-absensi/backend/public/storage/;
        try_files $uri =404;
    }

    location ~ ^/(api|sanctum|broadcasting)(/|$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/tppkk-absensi/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param PATH_INFO $uri;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

### Contoh Apache

Aktifkan `rewrite`, `headers`, `proxy`, `proxy_http`, dan `proxy_wstunnel`. Contoh ini memakai backend Laravel internal di `127.0.0.1:8000`, sehingga cocok bila server production menjalankan Laravel lewat app server internal. Jika memakai PHP-FPM langsung di Apache, arahkan `/api`, `/sanctum`, dan `/broadcasting` ke `backend/public/index.php` dengan konfigurasi `proxy_fcgi`.

```apache
<VirtualHost *:80>
    ServerName absensi.example.test
    DocumentRoot /var/www/tppkk-absensi/frontend/dist
    ProxyPreserveHost On

    <Directory /var/www/tppkk-absensi/frontend/dist>
        AllowOverride All
        Require all granted
        FallbackResource /index.html
    </Directory>

    Alias /storage /var/www/tppkk-absensi/backend/public/storage
    <Directory /var/www/tppkk-absensi/backend/public/storage>
        Require all granted
    </Directory>

    ProxyPass /app ws://127.0.0.1:8080/app
    ProxyPassReverse /app ws://127.0.0.1:8080/app

    ProxyPass /api http://127.0.0.1:8000/api
    ProxyPassReverse /api http://127.0.0.1:8000/api
    ProxyPass /sanctum http://127.0.0.1:8000/sanctum
    ProxyPassReverse /sanctum http://127.0.0.1:8000/sanctum
    ProxyPass /broadcasting http://127.0.0.1:8000/broadcasting
    ProxyPassReverse /broadcasting http://127.0.0.1:8000/broadcasting
</VirtualHost>
```

Pada server publik, isi `APP_URL`, `FRONTEND_URL`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `REVERB_HOST`, dan `SESSION_SECURE_COOKIE=true` sesuai domain.

## Catatan

- Semua waktu ditampilkan dalam WITA (`Asia/Makassar`).
- Lampiran permohonan disimpan privat dan hanya dapat dibuka pemilik atau staf.
- Jangan commit `.env`, password, kode aktivasi, token QR, dependency, build, cache, atau hasil test.
- Logo aplikasi berada di `frontend/public/tp-pkk-logo.png` dan dapat diganti dengan master resmi Balangan tanpa perubahan kode.
- Dokumen skripsi dan gambar rancangan tetap menjadi referensi, bukan panduan instalasi.

## Lisensi

Kode aplikasi menggunakan [MIT License](LICENSE). Dokumen penelitian, logo, dan aset referensi mengikuti hak cipta pemilik masing-masing.
