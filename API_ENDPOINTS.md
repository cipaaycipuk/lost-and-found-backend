# Dokumentasi Lengkap Backend API — Lost & Found Kampus Widyatama

Dokumen ini merupakan panduan menyeluruh untuk **Backend REST API Lost & Found Kampus** (Laravel 13 + Sanctum + Laravel Socialite), mencakup arsitektur autentikasi **Manual & Google OAuth (`@widyatama.ac.id`)**, skema database, konfigurasi Google Cloud Console, hingga spesifikasi lengkap setiap endpoint.

---

## Daftar Isi
1. [Arsitektur & Konvensi Umum](#1-arsitektur--konvensi-umum)
2. [Fokus Utama: Arsitektur Login Manual & Google OAuth (`@widyatama.ac.id`)](#2-fokus-utama-arsitektur-login-manual--google-oauth-widyatamaacid)
   - [2.1. Kebijakan Domain Email & Tanpa Register](#21-kebijakan-domain-email--tanpa-register)
   - [2.2. Mekanisme Account Linking (Email yang Sama)](#22-mekanisme-account-linking-email-yang-sama)
   - [2.3. Dua Alur Login Google yang Didukung](#23-dua-alur-login-google-yang-didukung)
   - [2.4. Panduan Konfigurasi Google Cloud Console & `.env`](#24-panduan-konfigurasi-google-cloud-console--env)
3. [Skema Database & Struktur Tabel](#3-skema-database--struktur-tabel)
4. [Spesifikasi Lengkap Endpoint REST API](#4-spesifikasi-lengkap-endpoint-rest-api)
   - [4.1. Autentikasi (`AuthController`)](#41-autentikasi-authcontroller)
   - [4.2. Profil Pengguna (`ProfileController`)](#42-profil-pengguna-profilecontroller)
   - [4.3. Laporan Barang Hilang & Temuan (`PostController`)](#43-laporan-barang-hilang--temuan-postcontroller)
   - [4.4. Komentar & Balasan (`CommentController`)](#44-komentar--balasan-commentcontroller)
   - [4.5. Simpan Laporan / Bookmark (`SavedPostController`)](#45-simpan-laporan--bookmark-savedpostcontroller)
   - [4.6. Notifikasi (`NotificationController`)](#46-notifikasi-notificationcontroller)
5. [Data Dummy (Seeder) & Perintah Operasional](#5-data-dummy-seeder--perintah-operasional)

---

## 1. Arsitektur & Konvensi Umum

- **Framework & Bahasa:** Laravel 13 (PHP 8.4)
- **Autentikasi API:** Laravel Sanctum (Bearer Token — `Authorization: Bearer <token>`)
- **Autentikasi Google:** Laravel Socialite (`laravel/socialite` + `firebase/php-jwt`)
- **Database:** MySQL (`lostandfound`) / SQLite (Testing)
- **Base URL:** `http://localhost:8000/api`
- **Headers Standar:**
  - `Accept: application/json`
  - `Content-Type: application/json` (atau `multipart/form-data` saat upload foto)
  - `Authorization: Bearer <token>` (untuk endpoint yang membutuhkan autentikasi)

### Format Standar Response Sukses
```json
{
  "success": true,
  "message": "Berhasil mengambil daftar laporan",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "limit": 10,
    "total": 3,
    "total_pages": 1
  }
}
```

### Format Standar Response Error
```json
{
  "success": false,
  "message": "Validasi gagal",
  "errors": {
    "email": ["Hanya email kampus (@widyatama.ac.id) yang diperbolehkan untuk login."]
  }
}
```

---

## 2. Fokus Utama: Arsitektur Login Manual & Google OAuth (`@widyatama.ac.id`)

Implementasi autentikasi berada di `app/Http/Controllers/AuthController.php`.

### 2.1. Kebijakan Domain Email & Tanpa Register
1. **Hanya Email `@widyatama.ac.id`:**
   - Seluruh jalur masuk (**Login Manual** maupun **Login via Google**) memeriksa domain email menggunakan fungsi `isWidyatamaEmail()`.
   - Jika email tidak berakhiran `@widyatama.ac.id` (misalnya `@gmail.com` atau `@yahoo.com`), server langsung menolak dengan HTTP `403 Forbidden`:
     ```json
     {
       "success": false,
       "message": "Hanya email kampus (@widyatama.ac.id) yang diperbolehkan untuk login."
     }
     ```
2. **Fitur Register Dihapus (*Takeout*):**
   - Endpoint `/api/auth/register` telah dihapus.
   - Mahasiswa cukup login menggunakan akun kampus yang sudah terdaftar atau langsung klik **Continue with Google** menggunakan akun Google Kampus (`@widyatama.ac.id`).

---

### 2.2. Mekanisme Account Linking (Email yang Sama)

Meskipun terdapat **2 cara login (Manual & Google)**, sistem menjamin **tidak ada duplikasi email** di tabel `users`:

| Kondisi Awal User di Database | Saat User Login via Google (`@widyatama.ac.id`) | Hasil di Tabel `users` |
|---|---|---|
| Sudah punya akun manual (`email: syifa.aini@widyatama.ac.id`, `password: terisi`, `auth_provider: "manual"`) | Sistem mencari berdasarkan `email` atau `google_id`. Karena `email` sudah ada, sistem **menautkan** `google_id` ke akun tersebut. | Tetap **1 baris user** yang sama. `google_id` terisi, `auth_provider` berubah menjadi `"manual,google"`, user bisa login lewat password manual maupun lewat Google. |
| Belum pernah ada di tabel `users` | Sistem membuat akun baru otomatis dari profil Google yang terverifikasi. | Dibuat **1 baris user** baru dengan `auth_provider: "google"`, `password: null`, `email_verified_at: now()`. (User dapat menambahkan password manual kapan saja melalui menu Edit Profil). |

---

### 2.3. Dua Alur Login Google yang Didukung

Backend mendukung **dua metode Google OAuth** sekaligus melalui library resmi **Laravel Socialite (`GoogleProvider`)**:

#### Alur A — Popup Google Sign-In dari Frontend React (`POST /api/auth/google`)
Digunakan ketika frontend React memakai package `@react-oauth/google` (`useGoogleLogin` atau `<GoogleLogin />`):

```text
[React Frontend]
   │ 1. User klik "Continue with Google" (Popup Google terbuka)
   │ 2. Google mengembalikan `access_token` atau JWT `credential` (id_token)
   ▼
[POST /api/auth/google] (AuthController@googleAuth)
   │ 3. Socialite::driver('google')->stateless()->userFromToken($token)
   │    - Jika JWT `credential`: verifikasi signature terhadap Google JWKS
   │      (https://www.googleapis.com/oauth2/v3/certs), cek `iss` & `aud`
   │    - Jika `access_token`: verifikasi ke https://www.googleapis.com/oauth2/v3/userinfo
   │ 4. Cek apakah email berakhiran `@widyatama.ac.id` (Tolak 403 jika bukan)
   │ 5. findOrLinkGoogleUser(): Tautkan ke user yang memiliki email sama / buat user baru
   │ 6. Terbitkan Sanctum Bearer Token (`createToken('auth_token')`)
   ▼
[React Frontend] menerima `{ token, user }` & masuk ke halaman utama
```

#### Alur B — Backend OAuth Redirect (`GET /api/auth/google/redirect` & `/callback`)
Digunakan ketika browser diarahkan ke halaman OAuth Google oleh backend Laravel:

```text
[React Frontend]
   │ 1. Mengarahkan browser ke GET /api/auth/google/redirect
   ▼
[AuthController@redirectToGoogle]
   │ 2. Socialite::driver('google')->with(['hd' => 'widyatama.ac.id', 'prompt' => 'select_account'])
   │    Mengarahkan user ke https://accounts.google.com/o/oauth2/auth
   ▼
[GET /api/auth/google/callback?code=...] (AuthController@handleGoogleCallback)
   │ 3. Socialite::driver('google')->stateless()->user() menukar `code` dengan profil Google
   │ 4. Cek apakah email berakhiran `@widyatama.ac.id`:
   │    - Jika BUKAN `@widyatama.ac.id`:
   │      Redirect ke `http://localhost:5173/login?error=Hanya+email+kampus...`
   │    - Jika VALID `@widyatama.ac.id`:
   │      findOrLinkGoogleUser() -> buat Sanctum Token ->
   │      Redirect ke `http://localhost:5173/login?token=<sanctum_token>`
   ▼
[React Frontend (Login.jsx)] membaca `?token=...` atau `?error=...` dari URL
```

---

### 2.4. Panduan Konfigurasi Google Cloud Console & `.env`

1. Buka **[Google Cloud Console — Credentials](https://console.cloud.google.com/apis/credentials)**.
2. Pastikan **OAuth consent screen** sudah diisi (*App name*, *User support email*, *Developer contact email*) dan disimpan.
3. Buat/edit **OAuth 2.0 Client ID** (tipe **Web application**):
   - **Authorized JavaScript origins:**
     - `http://localhost:5173`
     - `http://localhost:8000`
   - **Authorized redirect URIs:**
     - `http://localhost:8000/api/auth/google/callback`
4. Pastikan file `.env` di backend berisi:
   ```env
   GOOGLE_CLIENT_ID=95457306930-xxxx.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-xxxx
   GOOGLE_REDIRECT_URI=http://localhost:8000/api/auth/google/callback
   FRONTEND_URL=http://localhost:5173
   ```
5. Jalankan `php artisan config:clear` setiap kali mengubah `.env`.

---

## 3. Skema Database & Struktur Tabel

### 3.1. Tabel `users`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | ID unik user |
| `name` | `VARCHAR(100)` | Nama lengkap |
| `email` | `VARCHAR(150) UNIQUE` | Email kampus (`@widyatama.ac.id`) |
| `google_id` | `VARCHAR(100) NULL UNIQUE` | ID (`sub`) dari akun Google |
| `auth_provider` | `VARCHAR(30) DEFAULT 'manual'` | `'manual'`, `'google'`, atau `'manual,google'` |
| `email_verified_at` | `TIMESTAMP NULL` | Waktu verifikasi email |
| `password` | `VARCHAR(255) NULL` | Hash password (`NULL` jika baru login via Google) |
| `whatsapp` | `VARCHAR(25) NULL` | Nomor WhatsApp aktif |
| `avatar_url` | `TEXT NULL` | URL foto profil (lokal `/storage/avatars/...` atau URL Google) |
| `role` | `VARCHAR(20) DEFAULT 'user'` | `'user'` atau `'admin'` |
| `created_at` / `updated_at` | `TIMESTAMP` | Waktu pembuatan & pembaruan |

### 3.2. Tabel `posts`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | ID unik laporan |
| `user_id` | `BIGINT UNSIGNED FK` | Relasi ke `users(id)` (`ON DELETE CASCADE`) |
| `type` | `VARCHAR(20)` | `'kehilangan'` atau `'ditemukan'` |
| `status` | `VARCHAR(20) DEFAULT 'open'` | `'open'`, `'selesai'`, atau `'resolved'` |
| `title` | `VARCHAR(150)` | Judul / nama barang |
| `category` | `VARCHAR(80)` | Kategori barang (`Elektronik`, `Lainnya`, `Kunci`, dll.) |
| `location` | `VARCHAR(120)` | Lokasi kejadian (`Gedung A`, `Foodcourt / Kantin`, `Perpustakaan`, dll.) |
| `incident_date` | `DATE` | Tanggal hilang / ditemukan (`YYYY-MM-DD`) |
| `description` | `TEXT` | Deskripsi ciri-ciri barang & detail lokasi |
| `created_at` / `updated_at` | `TIMESTAMP` | Waktu posting & perubahan |

### 3.3. Tabel `post_images`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | ID unik gambar |
| `post_id` | `BIGINT UNSIGNED FK` | Relasi ke `posts(id)` (`ON DELETE CASCADE`) |
| `image_url` | `TEXT` | Path/URL gambar (`/storage/posts/...`) |
| `sort_order` | `INT DEFAULT 0` | Urutan gambar (`0` = gambar utama) |

### 3.4. Tabel `comments`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | ID unik komentar |
| `post_id` | `BIGINT UNSIGNED FK` | Relasi ke `posts(id)` (`ON DELETE CASCADE`) |
| `user_id` | `BIGINT UNSIGNED FK` | Relasi ke `users(id)` (`ON DELETE CASCADE`) |
| `parent_id` | `BIGINT UNSIGNED NULL FK` | Relasi ke `comments(id)` untuk balasan (*nested reply*) |
| `content` | `TEXT` | Isi komentar |

### 3.5. Tabel `saved_posts`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | ID unik bookmark |
| `user_id` | `BIGINT UNSIGNED FK` | Relasi ke `users(id)` (`ON DELETE CASCADE`) |
| `post_id` | `BIGINT UNSIGNED FK` | Relasi ke `posts(id)` (`ON DELETE CASCADE`) |
| *Unique Index* | `UNIQUE(user_id, post_id)` | Mencegah duplikasi simpanan |

### 3.6. Tabel `notifications`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | ID unik notifikasi |
| `user_id` | `BIGINT UNSIGNED FK` | Penerima notifikasi |
| `actor_id` | `BIGINT UNSIGNED NULL FK` | Pemicu notifikasi (misal pengirim komentar) |
| `post_id` | `BIGINT UNSIGNED NULL FK` | Laporan terkait |
| `type` | `VARCHAR(40)` | `'comment'`, `'reply'`, `'status_update'` |
| `message` | `TEXT` | Pesan notifikasi |
| `is_read` | `BOOLEAN DEFAULT FALSE` | Status sudah dibaca |

---

## 4. Spesifikasi Lengkap Endpoint REST API

### 4.1. Autentikasi (`AuthController`)

#### A. `POST /api/auth/login` (Alias: `POST /api/login`)
Login manual menggunakan email `@widyatama.ac.id` dan password.
- **Auth:** Tidak
- **Request Body (`application/json`):**
  ```json
  {
    "email": "syifa.aini@widyatama.ac.id",
    "password": "password123"
  }
  ```
- **Response Sukses (`200 OK`):**
  ```json
  {
    "success": true,
    "message": "Login berhasil",
    "data": {
      "token": "1|sanctum_plain_text_token...",
      "user": {
        "id": 1,
        "name": "Syifa Nur Aini",
        "email": "syifa.aini@widyatama.ac.id",
        "whatsapp": "0813 1212 3476",
        "avatar": "/users/emily.jpg",
        "avatar_url": "/users/emily.jpg",
        "role": "user",
        "auth_provider": "manual",
        "has_password": true
      }
    }
  }
  ```
- **Response Ditolak jika Bukan `@widyatama.ac.id` (`403 Forbidden`):**
  ```json
  {
    "success": false,
    "message": "Hanya email kampus (@widyatama.ac.id) yang diperbolehkan untuk login.",
    "errors": {
      "email": ["Hanya email kampus (@widyatama.ac.id) yang diperbolehkan untuk login."]
    }
  }
  ```

#### B. `POST /api/auth/google` (Alias: `POST /api/google`)
Verifikasi token Google Sign-In dari frontend React menggunakan Laravel Socialite.
- **Auth:** Tidak
- **Request Body (`application/json`):**
  ```json
  {
    "credential": "eyJhbGciOiJSUzI1NiIsImtpZCI6..."
  }
  ```
  *(Atau menggunakan `"access_token": "ya29.a0..."`)*
- **Response Sukses (`200 OK`):**
  ```json
  {
    "success": true,
    "message": "Login Google berhasil",
    "data": {
      "token": "2|sanctum_plain_text_token...",
      "user": {
        "id": 1,
        "name": "Syifa Nur Aini",
        "email": "syifa.aini@widyatama.ac.id",
        "whatsapp": "0813 1212 3476",
        "avatar": "/users/emily.jpg",
        "avatar_url": "/users/emily.jpg",
        "role": "user",
        "auth_provider": "manual,google",
        "has_password": true
      }
    }
  }
  ```

#### C. `GET /api/auth/google/redirect`
Mengarahkan user ke halaman persetujuan Google OAuth 2.0 (dengan parameter `hd=widyatama.ac.id`). Jika dipanggil dengan header `Accept: application/json`, mengembalikan JSON berisi URL redirect Google:
```json
{
  "success": true,
  "message": "URL OAuth Google berhasil dibuat",
  "data": {
    "url": "https://accounts.google.com/o/oauth2/auth?hd=widyatama.ac.id&prompt=select_account&client_id=..."
  }
}
```

#### D. `GET /api/auth/google/callback`
Menerima `code` dari Google OAuth 2.0, memvalidasi domain `@widyatama.ac.id`, menautkan/membuat akun, dan me-redirect kembali ke Frontend:
- Sukses: `http://localhost:5173/login?token=<sanctum_token>`
- Gagal / Bukan `@widyatama.ac.id`: `http://localhost:5173/login?error=Hanya+email+kampus+(@widyatama.ac.id)+yang+diperbolehkan+untuk+login.`

#### E. `POST /api/auth/logout` (Alias: `POST /api/logout`)
Mencabut (*revoke*) token Sanctum yang sedang digunakan.
- **Auth:** **Ya** (`Authorization: Bearer <token>`)

---

### 4.2. Profil Pengguna (`ProfileController`)

| Method | Endpoint | Alias | Auth | Parameter | Fungsi |
|---|---|---|---|---|---|
| `GET` | `/api/auth/me` | `/api/profile` | **Ya** | - | Mengambil data diri user yang sedang login |
| `PUT` / `PATCH` / `POST` | `/api/auth/profile` | `/api/profile` | **Ya** | `name`, `whatsapp` (atau `contact`), `password` (opsional, min. 6 karakter), `avatar` (file gambar JPG/PNG/WEBP maks. 5MB) | Mengubah nama, nomor WA, password, atau upload foto avatar (`multipart/form-data`) |

---

### 4.3. Laporan Barang Hilang & Temuan (`PostController`)

| Method | Endpoint | Auth | Parameter | Fungsi |
|---|---|---|---|---|
| `GET` | `/api/posts` | Opsional | Query: `search`, `type`, `category`, `location`, `status`, `sort`, `page`, `limit` | Mengambil daftar laporan + search + filter + pagination |
| `GET` | `/api/posts/me` | **Ya** | Query sama dengan `GET /api/posts` | Mengambil daftar laporan milik user yang sedang login |
| `GET` | `/api/posts/{id}` | Opsional | - | Mengambil detail 1 laporan beserta array gambar & komentar |
| `POST` | `/api/posts` | **Ya** | `multipart/form-data`: `type` (`kehilangan`/`ditemukan`), `title`, `category`, `location`, `incident_date` (`YYYY-MM-DD`), `description`, `images[]` (maks. 5 file, @5MB) | Membuat laporan baru beserta upload foto |
| `PUT` / `PATCH` | `/api/posts/{id}` | **Ya** (Owner) | Field yang ingin diubah (`title`, `category`, `location`, `incident_date`, `description`, `status`, `images[]`) | Mengubah data laporan atau status (`open` / `selesai` / `resolved`) |
| `PATCH` | `/api/posts/{id}/status` | **Ya** (Owner) | `{ "status": "selesai" }` (atau `"resolved"` / `"open"`) | Mengubah status laporan saja |
| `DELETE` | `/api/posts/{id}` | **Ya** (Owner) | - | Menghapus laporan beserta file gambarnya dari storage |

#### Contoh Item Post pada Response `GET /api/posts`
```json
{
  "id": 1,
  "type": "kehilangan",
  "status": "open",
  "title": "Tumbler Minum Motif Leopard",
  "category": "Lainnya",
  "location": "Foodcourt / Kantin",
  "incident_date": "2026-09-10",
  "description": "Dicari tumbler dengan motif leopard warna cokelat hitam...",
  "image": "/posts-img/tumbler.jpg",
  "images": ["/posts-img/tumbler.jpg"],
  "comments_count": 2,
  "is_saved": false,
  "created_at": "2026-11-21T07:30:00.000000Z",
  "updated_at": "2026-11-21T07:30:00.000000Z",
  "author": {
    "id": 2,
    "name": "Rony Parulian",
    "email": "rony.parulian@widyatama.ac.id",
    "whatsapp": "0813 1212 3476",
    "avatar": "/users/rony.jpg",
    "avatar_url": "/users/rony.jpg"
  }
}
```

---

### 4.4. Komentar & Balasan (`CommentController`)

| Method | Endpoint | Alias | Auth | Body Parameter | Fungsi |
|---|---|---|---|---|---|
| `GET` | `/api/posts/{postId}/comments` | - | Tidak | - | Mengambil daftar komentar utama beserta balasan pada laporan |
| `POST` | `/api/posts/{postId}/comments` | `POST /api/comments` (tambah `post_id`) | **Ya** | `content` (wajib), `parent_id` (opsional, ID komentar induk untuk balasan) | Mengirim komentar baru atau balasan (*reply*) & memicu notifikasi otomatis |
| `DELETE` | `/api/comments/{commentId}` | - | **Ya** (Owner) | - | Menghapus komentar milik user |

---

### 4.5. Simpan Laporan / Bookmark (`SavedPostController`)

| Method | Endpoint | Alias | Auth | Fungsi |
|---|---|---|---|---|
| `GET` | `/api/posts/saved` | `/api/saved-posts` | **Ya** | Menampilkan daftar laporan yang disimpan oleh user yang sedang login |
| `POST` | `/api/posts/{postId}/bookmark` | `/api/saved-posts/{postId}/toggle` atau `/api/saved-posts/toggle` | **Ya** | Menyimpan laporan jika belum ada di `saved_posts`, atau menghapusnya jika sudah disimpan |

---

### 4.6. Notifikasi (`NotificationController`)

Setiap objek notifikasi menyertakan **URL langsung ke post terkait** (`url`, `post_url`, `api_post_url`) beserta informasi `actor` (pelaku) dan `post` (ringkasan laporan), sehingga ketika diklik di Frontend akan langsung membuka halaman detail post terkait (`/?post={post_id}`).

#### Pemicu Notifikasi Otomatis (Real-time Lifecycle):
1. **`post_created`**: Saat user mempublikasikan laporan baru (`POST /api/posts`).
2. **`comment`**: Saat user lain mengirim komentar baru pada laporan Anda (`POST /api/posts/{id}/comments`).
3. **`reply`**:
   - Dikirim ke **penulis komentar induk** saat komentarnya dibalas (`parent_id` diisi).
   - Dikirim ke **pemilik laporan (post owner)** saat ada balasan di dalam laporannya.
   - Dikirim ke **partisipan lain di thread diskusi yang sama** saat ada balasan baru.
4. **`bookmark`**: Saat user lain menyimpan (*bookmark*) laporan Anda (`POST /api/posts/{id}/bookmark`).
5. **`status_update` / `post_updated`**:
   - Dikirim ke **pemilik laporan** saat laporan diperbarui atau statusnya diubah menjadi `selesai` / `resolved`.
   - Dikirim ke **semua user yang pernah mengomentari atau menyimpan laporan tersebut** ketika status laporan berubah menjadi `selesai`.

| Method | Endpoint | Auth | Fungsi |
|---|---|---|---|
| `GET` | `/api/notifications` | **Ya** | Mengambil daftar notifikasi milik user yang sedang login (lengkap dengan `url`, `post_url`, `actor`, `post`) |
| `PATCH` | `/api/notifications/{id}/read` | **Ya** | Menandai 1 notifikasi sebagai sudah dibaca (`is_read: true`) |
| `PATCH` | `/api/notifications/read-all` | **Ya** | Menandai seluruh notifikasi user sebagai sudah dibaca |

#### Contoh Item Response `GET /api/notifications`
```json
{
  "id": 1,
  "user_id": 1,
  "actor_id": 4,
  "post_id": 1,
  "postId": 1,
  "url": "/?post=1",
  "post_url": "http://localhost:5173/?post=1",
  "api_post_url": "http://localhost:8000/api/posts/1",
  "type": "comment",
  "message": "Agatha Chelsea mengomentari laporan Anda \"Tumbler Minum Motif Leopard\": \"Tadi sempat lihat di dekat dispenser...\"",
  "is_read": false,
  "isRead": false,
  "timeAgo": "10 menit yang lalu",
  "created_at": "2026-11-21T15:40:00.000000Z",
  "actor": {
    "id": 4,
    "name": "Agatha Chelsea",
    "email": "agatha.chelsea@widyatama.ac.id",
    "avatar": "/users/emily.jpg",
    "avatar_url": "/users/emily.jpg"
  },
  "post": {
    "id": 1,
    "title": "Tumbler Minum Motif Leopard",
    "type": "kehilangan",
    "status": "open",
    "url": "/?post=1",
    "post_url": "http://localhost:5173/?post=1"
  }
}
```

---

## 5. Data Dummy (Seeder) & Perintah Operasional

### Akun Dummy (`database/seeders/DatabaseSeeder.php`)
Seluruh akun dummy menggunakan domain `@widyatama.ac.id` dan password **`password123`**:
1. `syifa.aini@widyatama.ac.id` — **Syifa Nur Aini** (`0813 1212 3476`)
2. `rony.parulian@widyatama.ac.id` — **Rony Parulian** (`0813 1212 3476`)
3. `alyssa.putri@widyatama.ac.id` — **Alyssa Putri** (`0821 9876 5432`)
4. `agatha.chelsea@widyatama.ac.id` — **Agatha Chelsea** (`0812 3456 7890`)

### Perintah Artisan Penting
```bash
# 1. Reset tabel database & isi data dummy seeder sekaligus
php artisan migrate:fresh --seed

# 2. Buat symlink folder storage (agar foto laporan & avatar dapat diakses publik)
php artisan storage:link

# 3. Bersihkan cache konfigurasi setelah mengubah .env
php artisan config:clear

# 4. Jalankan pengujian otomatis (PHPUnit Feature Tests)
php artisan test

# 5. Jalankan server pengembangan Laravel (http://localhost:8000)
php artisan serve
```
