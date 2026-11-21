# Dokumentasi REST API Endpoints — Lost & Found Kampus

- **Base URL:** `http://localhost:8000/api`
- **Headers Umum:**
  - `Accept: application/json`
  - `Content-Type: application/json` (atau `multipart/form-data` saat upload foto)
- **Header Autentikasi (untuk endpoint yang membutuhkan login):**
  - `Authorization: Bearer <token>`

---

## Akun Dummy (Seeder)

Jalankan `php artisan migrate:fresh --seed` untuk mengisi akun berikut (Password semua akun: `password123`):

| Nama | Email | WhatsApp | Role |
|---|---|---|---|
| Syifa Nur Aini | `syifa.aini@widyatama.ac.id` | `0813 1212 3476` | `user` |
| Rony Parulian | `rony.parulian@widyatama.ac.id` | `0813 1212 3476` | `user` |
| Alyssa Putri | `alyssa.putri@widyatama.ac.id` | `0821 9876 5432` | `user` |
| Agatha Chelsea | `agatha.chelsea@widyatama.ac.id` | `0812 3456 7890` | `user` |

---

## 1. Autentikasi — Manual & Google Khusus `@widyatama.ac.id` (`AuthController`)

- **Wajib Domain Kampus (`@widyatama.ac.id`):** Baik login manual maupun login via Google **hanya mengizinkan email dengan akhiran `@widyatama.ac.id`**. Email selain `@widyatama.ac.id` akan langsung ditolak (`403 Forbidden`).
- **Tanpa Endpoint Register:** Fitur registrasi mandiri telah dihapus (*takeout*).
- **Account Linking (Email yang Sama):** Jika akun `@widyatama.ac.id` sudah terdaftar di database lalu login via Google dengan email yang sama, sistem otomatis menautkan `google_id` ke akun tersebut (`auth_provider: "manual,google"`).

| Method | Endpoint | Alias | Auth | Body / Query Parameter | Deskripsi |
|---|---|---|---|---|---|
| `POST` | `/api/auth/login` | `/api/login` | Tidak | `email` (wajib `@widyatama.ac.id`), `password` | Login manual & mendapatkan Bearer Token |
| `POST` | `/api/auth/google` | `/api/google` | Tidak | `credential` / `id_token` (JWT dari Google Identity Services / `@react-oauth/google`) atau `access_token` | Verifikasi token Google asli via **Laravel Socialite**, wajib `@widyatama.ac.id`, & otomatis tautkan akun dengan email yang sama |
| `GET` | `/api/auth/google/redirect` | - | Tidak | - | Redirect (atau ambil URL JSON) ke halaman OAuth 2.0 Google (`hd=widyatama.ac.id`) via **Laravel Socialite** |
| `GET` | `/api/auth/google/callback` | - | Tidak | Query: `code` | Callback OAuth 2.0 Google via **Laravel Socialite**, tolak jika bukan `@widyatama.ac.id`, tautkan akun & berikan token |
| `POST` | `/api/auth/logout` | `/api/logout` | **Ya** | - | Logout & mencabut (*revoke*) token aktif |

### Contoh Request `POST /api/auth/login`
```json
{
  "email": "syifa.aini@widyatama.ac.id",
  "password": "password123"
}
```

### Contoh Response `200/201`
```json
{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "token": "1|laravel_sanctum_token...",
    "user": {
      "id": 1,
      "name": "Syifa Nur Aini",
      "email": "syifa.aini@widyatama.ac.id",
      "whatsapp": "0813 1212 3476",
      "avatar": "/users/emily.jpg",
      "avatar_url": "/users/emily.jpg",
      "role": "user"
    }
  }
}
```

---

## 2. Profil Pengguna (`ProfileController`)

| Method | Endpoint | Alias | Auth | Body Parameter | Deskripsi |
|---|---|---|---|---|---|
| `GET` | `/api/auth/me` | `/api/profile` | **Ya** | - | Mengambil data profil user yang sedang login |
| `PUT` / `PATCH` / `POST` | `/api/auth/profile` | `/api/profile` | **Ya** | `name` (opsional), `whatsapp` / `contact` (opsional), `avatar` (file gambar, maks. 5MB) | Mengubah nama, nomor WhatsApp, dan/atau upload foto avatar (`multipart/form-data`) |

---

## 3. Laporan Barang (`PostController`)

| Method | Endpoint | Auth | Query / Body Parameter | Deskripsi |
|---|---|---|---|---|
| `GET` | `/api/posts` | Opsional | Query: `search`, `type`, `category`, `location`, `status`, `sort`, `page`, `limit` | Mengambil daftar laporan dengan pencarian, filter, urutan, dan pagination |
| `GET` | `/api/posts/me` | **Ya** | Query sama dengan `GET /api/posts` | Mengambil daftar laporan milik user yang sedang login |
| `GET` | `/api/posts/{id}` | Opsional | - | Mengambil detail 1 laporan beserta gambar & komentar |
| `POST` | `/api/posts` | **Ya** | `type`, `title`, `category`, `location`, `incident_date`, `description`, `images[]` (atau `image`) | Membuat laporan baru + upload foto (`multipart/form-data`) |
| `PUT` / `PATCH` | `/api/posts/{id}` | **Ya** (Owner) | Field yang ingin diubah (`title`, `category`, `location`, `description`, `status`, `images[]`, dll.) | Mengubah data laporan atau status (`open` / `selesai` / `resolved`) |
| `PATCH` | `/api/posts/{id}/status` | **Ya** (Owner) | `status` (`open` / `selesai` / `resolved`) | Mengubah status laporan saja |
| `DELETE` | `/api/posts/{id}` | **Ya** (Owner) | - | Menghapus laporan beserta file gambarnya dari storage |

### Detail Query Parameter `GET /api/posts`
- `search`: Kata kunci pencarian pada `title`, `description`, `location`, dan `category`
- `type`: `kehilangan` | `ditemukan` (atau kosongkan / `Semua`)
- `category`: Nama kategori (misal: `Elektronik`, `Dompet & Tas`, `Kunci`, `Dokumen & Kartu`, `Lainnya`, atau `Semua kategori`)
- `location`: Nama lokasi (misal: `Gedung A`, `Foodcourt`, `Perpustakaan`, atau `Semua`)
- `status`: `open` | `selesai` | `resolved`
- `sort`: `latest` (default) | `oldest`
- `page`: Nomor halaman (default: `1`)
- `limit`: Jumlah item per halaman (default: `10`)

### Contoh Form-Data `POST /api/posts`
- `type`: `kehilangan` (atau `ditemukan`)
- `title`: `Tumbler Minum Motif Leopard`
- `category`: `Lainnya`
- `location`: `Foodcourt / Kantin`
- `incident_date`: `2026-09-10`
- `description`: `Dicari tumbler dengan motif leopard warna cokelat hitam.`
- `images[]`: *(File gambar JPEG/PNG/WEBP, maks. 5MB per file, hingga 5 foto)*

---

## 4. Komentar & Balasan (`CommentController`)

| Method | Endpoint | Alias | Auth | Body Parameter | Deskripsi |
|---|---|---|---|---|---|
| `GET` | `/api/posts/{postId}/comments` | - | Tidak | - | Mengambil daftar komentar dan balasan pada laporan |
| `POST` | `/api/posts/{postId}/comments` | `POST /api/comments` (dengan `post_id`) | **Ya** | `content` (wajib), `parent_id` (opsional, ID komentar induk untuk balasan) | Mengirim komentar baru atau balasan (*reply*) |
| `DELETE` | `/api/comments/{commentId}` | - | **Ya** (Owner) | - | Menghapus komentar |

### Contoh Request `POST /api/posts/{postId}/comments`
```json
{
  "content": "Tadi sempat lihat di dekat dispenser lantai 4 kak.",
  "parent_id": null
}
```

---

## 5. Simpan Laporan / Bookmark (`SavedPostController`)

| Method | Endpoint | Alias | Auth | Query / Body Parameter | Deskripsi |
|---|---|---|---|---|---|
| `GET` | `/api/posts/saved` | `/api/saved-posts` | **Ya** | Query: `page`, `limit` | Menampilkan daftar barang/laporan yang disimpan oleh user |
| `POST` | `/api/posts/{postId}/bookmark` | `/api/saved-posts/{postId}/toggle` atau `/api/saved-posts/toggle` (body `post_id`) | **Ya** | - | Toggle simpan laporan (menyimpan jika belum ada, menghapus dari daftar simpanan jika sudah ada) |

### Contoh Response `POST /api/posts/{postId}/bookmark`
```json
{
  "success": true,
  "message": "Laporan berhasil disimpan",
  "data": {
    "post_id": 2,
    "is_saved": true
  }
}
```

---

## 6. Notifikasi (`NotificationController`)

| Method | Endpoint | Auth | Deskripsi |
|---|---|---|---|
| `GET` | `/api/notifications` | **Ya** | Mengambil daftar notifikasi untuk user yang sedang login |
| `PATCH` | `/api/notifications/{id}/read` | **Ya** | Menandai 1 notifikasi sebagai sudah dibaca (`is_read: true`) |
| `PATCH` | `/api/notifications/read-all` | **Ya** | Menandai seluruh notifikasi user sebagai sudah dibaca |
