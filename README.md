# Tambal Ban (PHP + MySQL)

Aplikasi pesan tambal ban dengan sisi pelanggan dan panel admin.
Butuh hosting dengan **PHP 7.4 atau lebih baru** dan **MySQL/MariaDB** (cPanel biasa sudah cukup).

## Isi paket

| File | Fungsi |
|---|---|
| `index.html` | Tampilan aplikasi (pelanggan dan admin) |
| `api.php` | Server: login, pesanan, layanan, tukang, pengaturan |
| `install.php` | Membuat tabel dan akun admin. Jalankan sekali, lalu hapus |
| `config.php` | Data database dan akun admin pertama |
| `.htaccess` | Menutup akses langsung ke `config.php` |

## Cara pasang

1. Di cPanel, buka **MySQL Databases**. Buat database baru, buat user, lalu beri user itu semua izin pada database tersebut.
2. Buka `config.php`, isi `db_name`, `db_user`, `db_pass`. Isi juga `admin_user` dan `admin_pass` (minimal 8 karakter) untuk akun admin pertama.
3. Unggah semua file ke folder situs Anda (misalnya `public_html/ban/`).
4. Buka `https://situsanda.com/ban/install.php` di browser. Jika muncul "Instalasi berhasil", lanjut.
5. **Hapus `install.php`** dari hosting, dan hapus baris `admin_pass` dari `config.php`.
6. Buka `https://situsanda.com/ban/`. Pelanggan daftar dengan nomor WhatsApp dan PIN. Admin masuk lewat tombol **Masuk Admin** di pojok kanan atas.

Data awal berisi 4 layanan dan 4 tukang contoh. Ubah atau hapus lewat panel admin.

## Cara kerja

- **Pelanggan** mendaftar dengan nama, nomor WhatsApp, dan PIN 4 sampai 6 angka. Lokasi diubah dengan mengetuk peta lalu menyimpan alamat.
- **Admin** mengelola pesanan (ubah status), layanan, tukang beserta posisinya di peta, daftar pelanggan, teks banner, dan metode pembayaran.
- Data diperbarui otomatis tiap 8 detik, tanpa perlu memuat ulang halaman.
- Harga dan nama layanan pada pesanan selalu diambil dari database di server, bukan dari kiriman browser.

## Keamanan yang sudah ada

- Password dan PIN disimpan sebagai hash (`password_hash`), bukan teks asli.
- Semua query memakai prepared statement.
- Percobaan masuk dibatasi: 8 kali gagal per 15 menit untuk setiap nomor atau username.
- Permintaan yang mengubah data wajib membawa header khusus dan memakai sesi cookie `HttpOnly`.
- Pelanggan hanya bisa melihat dan membatalkan pesanannya sendiri, dan hanya saat status masih Baru.

Saran tambahan: aktifkan HTTPS di hosting (gratis lewat AutoSSL di cPanel). Jika hosting memakai Nginx, `.htaccess` tidak berlaku, jadi tambahkan aturan yang menolak akses ke `config.php`.

## Keterbatasan

- Peta berupa denah sederhana, bukan peta asli. Jarak adalah perkiraan di denah. Untuk peta asli, perlu layanan peta pihak ketiga (misalnya Leaflet + OpenStreetMap), yang bisa ditambahkan nanti.
- Pilihan DANA / OVO / GoPay hanya mencatat metode bayar. Belum ada pembayaran online yang diproses.
- Belum ada notifikasi otomatis (WhatsApp atau email) saat ada pesanan baru. Admin melihatnya di panel.
- Pelanggan yang lupa PIN belum punya fitur reset sendiri. Admin bisa mengatur ulang lewat phpMyAdmin (hapus baris pelanggan itu agar bisa daftar lagi).

## Pencadangan

Ekspor database secara berkala lewat phpMyAdmin (tab **Export**). Semua data ada di database, bukan di file.
