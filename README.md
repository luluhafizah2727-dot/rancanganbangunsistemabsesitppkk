# Absensi Harian TP PKK Balangan

Aplikasi web untuk mencatat kehadiran harian anggota TP PKK Kabupaten Balangan. Anggota melakukan check-in dan check-out dengan memindai QR dinamis dari layar absensi, sedangkan Super Admin dan Operator memantau data melalui panel administrasi. Seluruh waktu aplikasi menggunakan `Asia/Makassar` (WITA).

## Cara Kerja Aplikasi

1. Super Admin mengatur hari kerja serta rentang waktu check-in dan check-out.
2. Super Admin mendaftarkan sebuah **Gawai**, yaitu layar khusus yang akan menampilkan QR absensi.
3. Gawai yang sudah diaktivasi menampilkan QR baru setiap 10 detik selama rentang absensi aktif.
4. Anggota login melalui ponsel, memastikan perangkatnya diizinkan, lalu memindai QR.
5. Backend memeriksa akun, perangkat anggota, Gawai, masa berlaku QR, dan jadwal sebelum mencatat kehadiran.
6. Data terbaru muncul pada dashboard dan dapat diekspor menjadi PDF atau XLSX.

QR yang sudah kedaluwarsa, berasal dari Gawai nonaktif, atau dipindai di luar jadwal akan ditolak dan dicatat dalam log pemindaian.

## Pengguna dan Hak Akses

| Pengguna | Kemampuan utama |
| --- | --- |
| Super Admin | Mengelola akun, anggota, jadwal, Gawai, perangkat anggota, kehadiran, permohonan, laporan, pengaturan keamanan, dan log audit. |
| Operator | Memantau dashboard, anggota, Gawai, permohonan, kehadiran, serta laporan. Perubahan dan persetujuan penting tetap dilakukan Super Admin. |
| Anggota | Melihat status hari ini, memindai QR, melihat riwayat, mengajukan izin/koreksi, dan memperbarui profil pribadi. |
| Gawai | Bukan akun manusia. Gawai adalah browser layar absensi yang diaktivasi dengan kode sementara dan menyimpan credential perangkat. |

### Istilah penting

| Istilah | Arti dalam proyek ini |
| --- | --- |
| Akun | Identitas login, password, status, dan role pengguna. |
| Anggota | Data keanggotaan yang terhubung ke akun ber-role `member`. Nomor anggota juga menjadi ID login. |
| Gawai | Layar tepercaya yang menampilkan QR check-in/check-out, misalnya komputer atau tablet di lokasi absensi. |
| Perangkat anggota | Browser atau ponsel milik anggota yang digunakan untuk memindai QR. Ini berbeda dari Gawai. |
| Binding perangkat | Aturan apakah perangkat anggota harus disetujui Super Admin atau cukup dicatat untuk audit. |

## Fitur Utama

- Jadwal mingguan dan pengecualian jadwal untuk tanggal tertentu.
- QR Gawai yang berubah otomatis setiap 10 detik.
- Check-in/check-out dengan penilaian tepat waktu, terlambat, atau pulang lebih awal.
- Registrasi mandiri anggota dengan persetujuan Super Admin.
- Pengelolaan akun, anggota, Gawai, dan perangkat anggota.
- Permohonan lupa check-in, lupa check-out, koreksi waktu, izin, cuti, sakit, dinas, dan lainnya.
- Kehadiran harian, dashboard, laporan PDF/XLSX, dan log audit.
- Session login berbasis Laravel Sanctum, Redis queue/cache, dan pembaruan real-time melalui Reverb.

## Arsitektur Singkat

```text
Browser Admin/Anggota ──> React + Vite ──> Laravel API ──> MariaDB/PostgreSQL
                               │                ├──> Redis (session, cache, queue)
Layar Gawai <──── QR + Reverb ─┴────────────────└──> Scheduler + Reverb
```

| Bagian | Teknologi | Tanggung jawab |
| --- | --- | --- |
| Frontend | React 19, TypeScript, Vite, Tailwind CSS | Antarmuka admin, anggota, scanner, dan layar Gawai. |
| Backend | Laravel 13, Sanctum, Spatie Permission | API, autentikasi, validasi, jadwal, absensi, dan laporan. |
| Database | MariaDB/MySQL atau PostgreSQL | Menyimpan akun, anggota, jadwal, kehadiran, perangkat, dan audit. |
| Runtime | Redis, queue worker, scheduler, Reverb | Session/cache, pekerjaan latar belakang, rotasi QR, dan real-time event. |

Queue worker, scheduler, Redis, dan Reverb harus berjalan agar seluruh fitur bekerja. Khususnya, rotasi QR dijalankan scheduler setiap 10 detik.

## Struktur Repository

```text
.
├── backend/              Laravel API, migration, seeder, dan test backend
├── frontend/             React SPA, halaman aplikasi, dan test frontend
├── deploy/               Contoh Apache, systemd, cron, PHP-FPM, dan phpMyAdmin
├── scripts/              Setup, development, deployment, backup, dan pemeriksaan
├── .env.example          Template environment bersama
├── package.json          Command utama repository
└── README.md
```

Backend dan frontend membaca satu file `.env` di root repository. Jangan membuat `backend/.env` atau `frontend/.env`.

## Quick Start Development

### 1. Siapkan kebutuhan

- Linux Ubuntu 22.04/24.04 atau sistem setara.
- PHP `8.3+` dan Composer `2.7+`.
- Node.js `24+` dan pnpm `11+`.
- MariaDB `10.6+` atau PostgreSQL `16+`.
- Redis `7+`.

Ekstensi PHP yang diperlukan: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `redis`, `tokenizer`, `xml`, `zip`, serta driver `pdo_mysql` atau `pdo_pgsql`.

Dari root repository, periksa mesin:

```bash
pnpm check:requirements
```

### 2. Pasang dependency dan buat key

```bash
pnpm setup
```

Command tersebut akan:

1. Menyalin `.env.example` menjadi `.env` jika belum tersedia.
2. Memasang dependency Composer dan pnpm dari lockfile.
3. Membuat `APP_KEY` dan credential Reverb jika masih kosong.
4. Membuat symbolic link storage Laravel.

### 3. Atur database

Edit `.env` dan isi setidaknya bagian berikut:

```env
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tppkk_absensi
DB_USERNAME=tppkk_app
DB_PASSWORD=ganti-dengan-password-lokal
```

Untuk development, buat database, jalankan migration, dan isi data demo:

```bash
pnpm setup:database
```

Jika MariaDB/PostgreSQL lokal menggunakan autentikasi administrator melalui sudo:

```bash
sudo -v
pnpm setup:database
```

Pastikan `DB_ADMIN_USE_SUDO=true` di `.env`. Jangan menaruh password sudo langsung dalam command karena dapat tersimpan di shell history.

> `pnpm setup:database` memasukkan akun dan data demo. Jangan gunakan command ini untuk inisialisasi production.

### 4. Jalankan semua service

```bash
pnpm dev:services
```

Command ini menjalankan Laravel API, Vite, Redis queue worker, scheduler, dan Reverb dalam satu terminal. Tekan `Ctrl+C` untuk menghentikan semuanya.

| Service | Alamat default |
| --- | --- |
| Aplikasi/Vite | `http://localhost:5173` |
| Laravel API internal | `http://127.0.0.1:8000` |
| Reverb WebSocket internal | `ws://127.0.0.1:8080` |

Vite meneruskan `/api`, `/sanctum`, `/broadcasting`, `/storage`, dan `/app` ke service terkait, sehingga browser cukup membuka `http://localhost:5173`.

## Data Demo Development

Seeder membuat delapan anggota dengan nomor `220340096` sampai `220340103`, jadwal Senin–Jumat pukul 08.00–16.00 WITA, satu Gawai pending, dan beberapa contoh kehadiran.

| Role | ID pengguna | Password awal |
| --- | --- | --- |
| Super Admin | Nilai `SEED_ADMIN_LOGIN_ID`, default `admin` | Nilai `SEED_ADMIN_PASSWORD`, default `ChangeMe123!` |
| Operator | `operator` | `Operator123!` |
| Anggota demo | `220340096`–`220340103` | `MemberDemo123!` |

Semua akun seed wajib mengganti password. Credential tersebut hanya untuk development/demo dan tidak boleh dipakai untuk data nyata.

## Alur Uji Manual Pertama

1. Login sebagai Super Admin dan ganti password awal.
2. Buka **Pengaturan** lalu periksa jadwal hari ini.
3. Buka **Gawai**, pilih `GAWAI-001`, lalu buat kode aktivasi. Kode berlaku selama 15 menit.
4. Buka `/gawai` pada browser layar lain dan masukkan kode aktivasi.
5. Login sebagai anggota dari ponsel. Jika binding perangkat memerlukan persetujuan, ajukan perangkat lalu setujui melalui panel Super Admin.
6. Buka menu **Pindai** dan arahkan kamera ke QR saat rentang check-in/check-out aktif.
7. Periksa hasil pada **Kehadiran**, **Laporan**, dan **Log**.

Jika hari ini libur atau berada di luar rentang absensi, buat pengecualian jadwal sementara untuk pengujian lalu hapus setelah selesai.

## Konfigurasi Environment

Gunakan [.env.example](.env.example) sebagai referensi lengkap. Variabel yang paling sering diubah:

| Variabel | Fungsi |
| --- | --- |
| `APP_URL` | URL utama aplikasi yang digunakan Laravel. |
| `FRONTEND_URLS` | Daftar origin frontend yang diizinkan oleh CORS. |
| `DB_*` | Koneksi database aplikasi dan opsi pembuatan database. |
| `REDIS_*` | Koneksi Redis untuk session, cache, dan queue. |
| `SESSION_SECURE_COOKIE` | `auto` untuk development campuran; gunakan `true` pada production HTTPS-only. |
| `SANCTUM_STATEFUL_DOMAINS` | Host frontend yang boleh memakai autentikasi session Sanctum, tanpa skema URL. |
| `TRUSTED_PROXIES` | Alamat reverse proxy yang boleh mengirim header forwarded. |
| `VITE_DEV_HOST` / `VITE_DEV_PORT` | Host dan port Vite development. |
| `VITE_ALLOWED_HOSTS` | Hostname tambahan untuk Vite; biarkan kosong untuk localhost. |

Untuk PostgreSQL, ubah minimal:

```env
DB_CONNECTION=pgsql
DB_PORT=5432
DB_ADMIN_USERNAME=postgres
DB_ADMIN_DATABASE=postgres
```

Aplikasi dan setup database mendukung PostgreSQL, tetapi contoh bootstrap server, service, dan backup di `deploy/` dioptimalkan untuk MariaDB. Lihat panduan deployment sebelum memilih database production.

## Command Penting

| Command | Kegunaan |
| --- | --- |
| `pnpm setup` | Memasang dependency dan membuat key aplikasi. |
| `pnpm database:prepare` | Membuat database/user tanpa migration atau data demo. |
| `pnpm setup:database` | Menyiapkan database, migration, dan data demo development. |
| `pnpm dev:services` | Menjalankan semua service development. |
| `pnpm lint` | Menjalankan Pint, PHPStan, dan ESLint. |
| `pnpm test` | Menjalankan test backend dan frontend. |
| `pnpm build` | Type-check dan membuat build frontend production. |
| `pnpm --dir frontend e2e` | Menjalankan smoke test Playwright; jalankan `pnpm build` terlebih dahulu. |

Verifikasi lengkap sebelum mengirim perubahan:

```bash
pnpm check:requirements
pnpm lint
pnpm test
pnpm build
pnpm --dir frontend e2e
```

## Deployment Production

Aset deployment bawaan menargetkan Ubuntu, Apache, PHP 8.3-FPM, MariaDB, Redis, cron, dan systemd. Nginx dapat digunakan, tetapi repository belum menyediakan contoh konfigurasinya.

Alur aman untuk database production baru adalah:

```bash
pnpm setup
pnpm database:prepare
scripts/deploy-production.sh
```

`scripts/deploy-production.sh` menjalankan migration tanpa seeder, membangun frontend, mengoptimalkan Laravel, dan dapat me-restart service. Jangan menjalankan `pnpm setup:database` di production karena command tersebut memasukkan data demo.

Panduan mengenai Apache, HTTPS/reverse proxy, systemd, cron, deployment jaringan lokal, phpMyAdmin, backup, dan pemeriksaan runtime tersedia di [docs/deployment.md](docs/deployment.md).

## Troubleshooting

### Vite menampilkan `Blocked request`

Tambahkan hostname development ke `VITE_ALLOWED_HOSTS`. Jangan menggunakan wildcard atau `allowedHosts=true`. Untuk akses dari perangkat lain, atur `VITE_DEV_HOST=0.0.0.0` dan gunakan firewall jaringan yang sesuai.

### Database gagal dibuat

Periksa `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, dan `DB_ADMIN_*`. Jika memakai autentikasi socket lokal, aktifkan `DB_ADMIN_USE_SUDO=true`, jalankan `sudo -v`, lalu ulangi command database.

### Session, queue, atau health check gagal

Pastikan Redis aktif dan merespons:

```bash
redis-cli ping
```

### Kamera tidak tersedia di ponsel

Scanner menggunakan API kamera browser. Gunakan HTTPS pada deployment yang diakses ponsel, lalu pastikan izin kamera diberikan. HTTP lokal cocok untuk pengujian terbatas, tetapi banyak browser hanya mengaktifkan kamera pada secure context.

### Port sudah digunakan

Periksa port development `5173`, `8000`, dan `8080`, lalu hentikan proses lama sebelum menjalankan `pnpm dev:services` kembali.

## Catatan Keamanan

- Jangan commit `.env`, password, token, credential database, dependency, hasil build, cache, atau hasil test.
- Ganti seluruh password akun seed sebelum menggunakan database untuk data nyata.
- Gunakan HTTPS untuk production, terutama agar session dan kamera browser bekerja secara aman.
- Credential Gawai dan perangkat anggota disimpan sebagai hash di database.
- Batasi Redis, Reverb, database, dan PHP-FPM ke jaringan internal atau reverse proxy tepercaya.
- Jika phpMyAdmin dipasang, akses hanya melalui loopback dan SSH tunnel.

## Lisensi

Kode aplikasi menggunakan [MIT License](LICENSE).
