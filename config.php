<?php
// Isi sesuai data database dari hosting Anda (cPanel > MySQL Databases).
return [
    'db_host' => 'localhost',
    'db_name' => 'nama_database',
    'db_user' => 'user_database',
    'db_pass' => 'password_database',

    // Akun admin pertama. Dipakai sekali oleh install.php.
    // Ganti password ini sebelum membuka install.php (minimal 8 karakter).
    'admin_user' => 'admin',
    'admin_pass' => 'GANTI-PASSWORD-INI',

    // Opsional, untuk pengujian: DSN PDO lengkap (misalnya sqlite). Kosongkan di hosting.
    'db_dsn' => '',
];
