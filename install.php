<?php
// Jalankan SEKALI dari browser (https://situsanda.com/install.php), lalu hapus file ini.
header('Content-Type: text/html; charset=utf-8');

function page(string $title, string $html, int $code = 200): void
{
    http_response_code($code);
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:40px auto;padding:0 16px;line-height:1.5">'
        . '<h1 style="font-size:22px">' . htmlspecialchars($title) . '</h1>' . $html . '</body></html>';
    exit;
}

$lock = __DIR__ . '/installed.lock';
if (file_exists($lock)) {
    page('Sudah terpasang', '<p>Instalasi sudah pernah dijalankan. Hapus file <code>install.php</code> dari hosting Anda.</p>', 403);
}

$cfg = require __DIR__ . '/config.php';
if (strlen((string) $cfg['admin_pass']) < 8 || $cfg['admin_pass'] === 'GANTI-PASSWORD-INI') {
    page('Ganti password admin dulu', '<p>Buka <code>config.php</code>, isi <code>admin_pass</code> dengan password baru (minimal 8 karakter), lalu muat ulang halaman ini.</p>', 400);
}

try {
    $pdo = new PDO(
        'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4',
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    page('Database tidak bisa dihubungi', '<p>Periksa <code>db_host</code>, <code>db_name</code>, <code>db_user</code>, dan <code>db_pass</code> di <code>config.php</code>.</p>', 500);
}

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$tables = [
    'CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL)' . $tail,
    'CREATE TABLE IF NOT EXISTS admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(40) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL)' . $tail,
    'CREATE TABLE IF NOT EXISTS customers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, phone VARCHAR(20) NOT NULL UNIQUE, pin_hash VARCHAR(255) NOT NULL,
        address VARCHAR(200) NOT NULL DEFAULT \'\', x DOUBLE NULL, y DOUBLE NULL, updated_at BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL DEFAULT 0)' . $tail,
    'CREATE TABLE IF NOT EXISTS services (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, icon VARCHAR(20) NOT NULL DEFAULT \'servis\', price INT NOT NULL DEFAULT 0,
        descr VARCHAR(120) NOT NULL DEFAULT \'\', active TINYINT(1) NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0)' . $tail,
    'CREATE TABLE IF NOT EXISTS workshops (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'Siap Datang\', rating DOUBLE NOT NULL DEFAULT 5,
        reviews INT NOT NULL DEFAULT 0, x DOUBLE NOT NULL DEFAULT 50, y DOUBLE NOT NULL DEFAULT 40, active TINYINT(1) NOT NULL DEFAULT 1)' . $tail,
    'CREATE TABLE IF NOT EXISTS orders (id CHAR(10) NOT NULL PRIMARY KEY, customer_id INT NOT NULL, workshop_id INT NULL, workshop_name VARCHAR(60) NOT NULL, service_id INT NULL,
        service_name VARCHAR(60) NOT NULL, price INT NOT NULL DEFAULT 0, address VARCHAR(200) NOT NULL, x DOUBLE NULL, y DOUBLE NULL, payment VARCHAR(10) NOT NULL,
        note VARCHAR(200) NOT NULL DEFAULT \'\', status VARCHAR(20) NOT NULL DEFAULT \'Baru\', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        KEY idx_customer (customer_id), KEY idx_status (status), KEY idx_created (created_at))' . $tail,
    'CREATE TABLE IF NOT EXISTS login_attempts (k CHAR(64) NOT NULL, ts INT NOT NULL, KEY idx_k (k), KEY idx_ts (ts))' . $tail,
];

try {
    foreach ($tables as $sql) $pdo->exec($sql);

    $defaults = [
        'appName' => 'Tambal Ban', 'tagline' => 'Cepat • Dekat • Praktis', 'bannerTitle' => 'Butuh tambal ban?',
        'bannerText' => 'Pesan sekarang, kami siap membantu!', 'ewalletLabel' => 'DANA / OVO / GoPay', 'payCash' => '1', 'payEwallet' => '1',
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

    if ((int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
        $s = $pdo->prepare('INSERT INTO services (name, icon, price, descr, active, sort) VALUES (?, ?, ?, ?, 1, ?)');
        foreach ([['Tambal Ban', 'tambal', 15000, 'Tambal ban bocor'], ['Ganti Ban', 'ganti', 120000, 'Ganti ban baru'],
                  ['Isi Angin', 'angin', 5000, 'Isi angin / nitrogen'], ['Servis Ban', 'servis', 35000, 'Cek dan servis ban']] as $i => $r) {
            $s->execute([$r[0], $r[1], $r[2], $r[3], $i + 1]);
        }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM workshops')->fetchColumn() === 0) {
        $w = $pdo->prepare('INSERT INTO workshops (name, status, rating, reviews, x, y, active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        foreach ([['Budi Tambal Ban', 'Siap Datang', 4.8, 1200, 43, 34], ['Sinar Ban Motor', 'Siap Datang', 4.6, 860, 72, 28],
                  ['Maju Jaya Ban', 'Sibuk', 4.5, 410, 22, 52], ['Ban 24 Jam Cahaya', 'Siap Datang', 4.7, 530, 78, 54]] as $r) {
            $w->execute($r);
        }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO admins (username, pass_hash) VALUES (?, ?)')
            ->execute([$cfg['admin_user'], password_hash($cfg['admin_pass'], PASSWORD_DEFAULT)]);
    }
} catch (Throwable $e) {
    error_log('tambal-ban install: ' . $e->getMessage());
    page('Instalasi gagal', '<p>Tabel tidak bisa dibuat. Pastikan user database punya izin CREATE dan INSERT, lalu coba lagi.</p>', 500);
}

@file_put_contents($lock, date('c'));
page('Instalasi berhasil', '<p>Tabel dan data awal sudah dibuat. Admin: <b>' . htmlspecialchars($cfg['admin_user']) . '</b>.</p>'
    . '<p><b>Langkah terakhir:</b> hapus file <code>install.php</code> dari hosting, lalu hapus <code>admin_pass</code> dari <code>config.php</code>.</p>'
    . '<p><a href="index.html">Buka aplikasi</a></p>');
