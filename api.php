<?php
// API Tambal Ban. Butuh PHP 7.4+ dengan PDO MySQL.

const STATUSES  = ['Baru', 'Diproses', 'Dalam perjalanan', 'Selesai', 'Dibatalkan'];
const WS_STATUS = ['Siap Datang', 'Sibuk', 'Tutup'];
const ICONS     = ['tambal', 'ganti', 'angin', 'servis', 'aki', 'oli'];

function out(array $d, int $code = 200)
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $m, int $code = 400)
{
    out(['ok' => false, 'error' => $m], $code);
}
function body(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $j = json_decode((string) file_get_contents('php://input'), true);
    $b = is_array($j) ? $j : [];
    return $b;
}
function cut($v, int $max): string
{
    $v = trim((string) $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}
function phone_norm($p): string
{
    $d = preg_replace('/\D+/', '', (string) $p);
    if (strpos($d, '62') === 0) $d = '0' . substr($d, 2);
    elseif (strpos($d, '8') === 0) $d = '0' . $d;
    return $d;
}
function cid(): ?int
{
    return isset($_SESSION['cid']) ? (int) $_SESSION['cid'] : null;
}
function is_admin(): bool
{
    return !empty($_SESSION['aid']);
}
function need_admin()
{
    if (!is_admin()) fail('Masuk sebagai admin dulu.', 401);
}
function need_customer(): int
{
    $c = cid();
    if (!$c) fail('Masuk atau daftar dulu.', 401);
    return $c;
}

/* ---------- pembatasan percobaan masuk ---------- */
function throttle_key(string $key): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '?') . '|' . $key);
}
function throttle_check(PDO $pdo, string $key): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE k = ?');
    $st->execute([throttle_key($key)]);
    if ((int) $st->fetchColumn() >= 8) fail('Terlalu banyak percobaan. Coba lagi 15 menit lagi.', 429);
}
function throttle_fail(PDO $pdo, string $key): void
{
    $pdo->prepare('INSERT INTO login_attempts (k, ts) VALUES (?, ?)')->execute([throttle_key($key), time()]);
}
function throttle_clear(PDO $pdo, string $key): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE k = ?')->execute([throttle_key($key)]);
}

/* ---------- bentuk data untuk frontend ---------- */
function svc_row(array $r): array
{
    return ['id' => (string) $r['id'], 'name' => $r['name'], 'icon' => $r['icon'], 'price' => (int) $r['price'],
        'desc' => $r['descr'], 'active' => (bool) $r['active'], 'order' => (int) $r['sort']];
}
function ws_row(array $r): array
{
    return ['id' => (string) $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'rating' => (float) $r['rating'],
        'reviews' => (int) $r['reviews'], 'x' => (float) $r['x'], 'y' => (float) $r['y'], 'active' => (bool) $r['active']];
}
function cust_row(array $r): array
{
    return ['id' => (string) $r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'address' => $r['address'],
        'x' => $r['x'] === null ? null : (float) $r['x'], 'y' => $r['y'] === null ? null : (float) $r['y'],
        'updatedAt' => (int) $r['updated_at']];
}
function order_row(array $r): array
{
    return ['id' => (string) $r['id'], 'uid' => (string) $r['customer_id'], 'customerName' => $r['customer_name'] ?? '',
        'workshopId' => (string) $r['workshop_id'], 'workshopName' => $r['workshop_name'],
        'serviceId' => (string) $r['service_id'], 'serviceName' => $r['service_name'], 'price' => (int) $r['price'],
        'address' => $r['address'], 'x' => $r['x'] === null ? null : (float) $r['x'], 'y' => $r['y'] === null ? null : (float) $r['y'],
        'payment' => $r['payment'], 'note' => $r['note'], 'status' => $r['status'],
        'createdAt' => (int) $r['created_at'], 'updatedAt' => (int) $r['updated_at']];
}

/* ---------- simpan baris dengan daftar kolom yang diizinkan ---------- */
function save_row(PDO $pdo, string $table, ?int $id, array $patch, array $spec, array $defaults): int
{
    $cols = [];
    foreach ($spec as $key => $def) {
        if (!array_key_exists($key, $patch)) continue;
        [$col, $type, $extra] = $def;
        $v = $patch[$key];
        switch ($type) {
            case 'str':
                $v = cut($v, $extra);
                if ($col === 'name' && $v === '') fail('Nama tidak boleh kosong.');
                break;
            case 'int':
                $v = max(0, min(100000000, (int) $v));
                break;
            case 'float':
                $v = round(max($extra[0], min($extra[1], (float) $v)), 1);
                break;
            case 'bool':
                $v = !empty($v) ? 1 : 0;
                break;
            case 'enum':
                if (!in_array($v, $extra, true)) fail('Nilai tidak valid.');
                break;
        }
        $cols[$col] = $v;
    }
    if ($id === null) {
        foreach ($defaults as $c => $v) if (!array_key_exists($c, $cols)) $cols[$c] = $v;
        $names = array_keys($cols);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($cols));
        return (int) $pdo->lastInsertId();
    }
    if ($cols) {
        $set = implode(',', array_map(function ($c) { return $c . ' = ?'; }, array_keys($cols)));
        $vals = array_values($cols);
        $vals[] = $id;
        $pdo->prepare('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?')->execute($vals);
    }
    return $id;
}

/* ---------- mulai ---------- */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

$cfg = require __DIR__ . '/config.php';
try {
    $dsn = !empty($cfg['db_dsn']) ? $cfg['db_dsn']
        : 'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $cfg['db_user'] ?? null, $cfg['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fail('Database tidak bisa dihubungi. Periksa config.php.', 500);
}

$a = $_GET['a'] ?? '';
if ($a !== 'bootstrap') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Metode tidak valid.', 405);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'tambalban') fail('Permintaan tidak valid.', 403);
}
$b = body();

try {
    switch ($a) {

        case 'bootstrap': {
            $set = [];
            foreach ($pdo->query('SELECT k, v FROM settings') as $r) $set[$r['k']] = $r['v'];
            $res = [
                'ok' => true,
                'config' => [
                    'appName' => $set['appName'] ?? 'Tambal Ban',
                    'tagline' => $set['tagline'] ?? 'Cepat • Dekat • Praktis',
                    'bannerTitle' => $set['bannerTitle'] ?? 'Butuh tambal ban?',
                    'bannerText' => $set['bannerText'] ?? 'Pesan sekarang, kami siap membantu!',
                    'ewalletLabel' => $set['ewalletLabel'] ?? 'DANA / OVO / GoPay',
                    'payCash' => ($set['payCash'] ?? '1') === '1',
                    'payEwallet' => ($set['payEwallet'] ?? '1') === '1',
                ],
                'services' => array_map('svc_row', $pdo->query('SELECT * FROM services ORDER BY sort, id')->fetchAll()),
                'workshops' => array_map('ws_row', $pdo->query('SELECT * FROM workshops ORDER BY id')->fetchAll()),
                'me' => ['role' => null, 'customerId' => null, 'name' => ''],
            ];
            if (cid()) {
                $st = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
                $st->execute([cid()]);
                $c = $st->fetch();
                if ($c) {
                    $res['me']['customerId'] = (string) $c['id'];
                    $res['me']['name'] = $c['name'];
                    $res['profile'] = cust_row($c);
                    $st = $pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 100');
                    $st->execute([cid()]);
                    $res['orders'] = array_map('order_row', $st->fetchAll());
                } else {
                    unset($_SESSION['cid']);
                }
            }
            if (is_admin()) {
                $res['me']['role'] = 'admin';
                $res['adminOrders'] = array_map('order_row', $pdo->query(
                    'SELECT o.*, c.name AS customer_name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id ORDER BY o.created_at DESC LIMIT 300'
                )->fetchAll());
                $res['customers'] = array_map('cust_row', $pdo->query('SELECT * FROM customers ORDER BY updated_at DESC, id DESC LIMIT 500')->fetchAll());
            }
            out($res);
        }

        case 'register': {
            $name = cut($b['name'] ?? '', 60);
            $phone = phone_norm($b['phone'] ?? '');
            $pin = (string) ($b['pin'] ?? '');
            if (strlen($name) < 2) fail('Isi nama Anda.');
            if (!preg_match('/^0\d{8,13}$/', $phone)) fail('Nomor WhatsApp tidak valid.');
            if (!preg_match('/^\d{4,6}$/', $pin)) fail('PIN harus 4 sampai 6 angka.');
            $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ?');
            $st->execute([$phone]);
            if ($st->fetch()) fail('Nomor ini sudah terdaftar. Silakan masuk.', 409);
            $t = now_ms();
            $pdo->prepare('INSERT INTO customers (name, phone, pin_hash, address, x, y, updated_at, created_at) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?)')
                ->execute([$name, $phone, password_hash($pin, PASSWORD_DEFAULT), '', $t, $t]);
            session_regenerate_id(true);
            $_SESSION['cid'] = (int) $pdo->lastInsertId();
            out(['ok' => true]);
        }

        case 'login': {
            $phone = phone_norm($b['phone'] ?? '');
            $pin = (string) ($b['pin'] ?? '');
            throttle_check($pdo, 'c:' . $phone);
            $st = $pdo->prepare('SELECT id, pin_hash FROM customers WHERE phone = ?');
            $st->execute([$phone]);
            $c = $st->fetch();
            if (!$c || !password_verify($pin, $c['pin_hash'])) {
                throttle_fail($pdo, 'c:' . $phone);
                usleep(400000);
                fail('Nomor atau PIN salah.', 401);
            }
            throttle_clear($pdo, 'c:' . $phone);
            session_regenerate_id(true);
            $_SESSION['cid'] = (int) $c['id'];
            out(['ok' => true]);
        }

        case 'admin_login': {
            $u = cut($b['username'] ?? '', 40);
            $p = (string) ($b['password'] ?? '');
            throttle_check($pdo, 'a:' . $u);
            $st = $pdo->prepare('SELECT id, pass_hash FROM admins WHERE username = ?');
            $st->execute([$u]);
            $ad = $st->fetch();
            if (!$ad || !password_verify($p, $ad['pass_hash'])) {
                throttle_fail($pdo, 'a:' . $u);
                usleep(400000);
                fail('Username atau password salah.', 401);
            }
            throttle_clear($pdo, 'a:' . $u);
            session_regenerate_id(true);
            $_SESSION['aid'] = (int) $ad['id'];
            out(['ok' => true]);
        }

        case 'logout': {
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);
        }

        case 'profile_save': {
            $c = need_customer();
            $name = cut($b['name'] ?? '', 60);
            $phone = phone_norm($b['phone'] ?? '');
            $addr = cut($b['address'] ?? '', 200);
            if (strlen($name) < 2) fail('Isi nama Anda.');
            if (!preg_match('/^0\d{8,13}$/', $phone)) fail('Nomor WhatsApp tidak valid.');
            $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND id <> ?');
            $st->execute([$phone, $c]);
            if ($st->fetch()) fail('Nomor itu sudah dipakai akun lain.', 409);
            $x = isset($b['x']) && is_numeric($b['x']) ? round(max(0, min(100, (float) $b['x'])), 1) : null;
            $y = isset($b['y']) && is_numeric($b['y']) ? round(max(0, min(80, (float) $b['y'])), 1) : null;
            $pdo->prepare('UPDATE customers SET name = ?, phone = ?, address = ?, x = ?, y = ?, updated_at = ? WHERE id = ?')
                ->execute([$name, $phone, $addr, $x, $y, now_ms(), $c]);
            out(['ok' => true]);
        }

        case 'order_create': {
            $c = need_customer();
            $st = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
            $st->execute([$c]);
            $cu = $st->fetch();
            if (!$cu) fail('Akun tidak ditemukan.', 401);
            if (trim($cu['address']) === '') fail('Atur alamat Anda dulu di peta.');
            $st = $pdo->prepare('SELECT * FROM workshops WHERE id = ?');
            $st->execute([(int) ($b['workshopId'] ?? 0)]);
            $ws = $st->fetch();
            if (!$ws || !$ws['active']) fail('Tukang tidak tersedia.');
            if ($ws['status'] === 'Tutup') fail('Tukang ini sedang tutup.');
            $st = $pdo->prepare('SELECT * FROM services WHERE id = ?');
            $st->execute([(int) ($b['serviceId'] ?? 0)]);
            $sv = $st->fetch();
            if (!$sv || !$sv['active']) fail('Layanan tidak tersedia.');
            $pay = (string) ($b['payment'] ?? '');
            $set = [];
            foreach ($pdo->query('SELECT k, v FROM settings') as $r) $set[$r['k']] = $r['v'];
            $ok = ($pay === 'cash' && ($set['payCash'] ?? '1') === '1') || ($pay === 'ewallet' && ($set['payEwallet'] ?? '1') === '1');
            if (!$ok) fail('Metode pembayaran tidak tersedia.');
            $id = bin2hex(random_bytes(5));
            $t = now_ms();
            $pdo->prepare('INSERT INTO orders (id, customer_id, workshop_id, workshop_name, service_id, service_name, price, address, x, y, payment, note, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $c, $ws['id'], $ws['name'], $sv['id'], $sv['name'], (int) $sv['price'], $cu['address'], $cu['x'], $cu['y'], $pay, cut($b['note'] ?? '', 200), 'Baru', $t, $t]);
            out(['ok' => true, 'id' => $id]);
        }

        case 'order_cancel': {
            $c = need_customer();
            $st = $pdo->prepare("UPDATE orders SET status = 'Dibatalkan', updated_at = ? WHERE id = ? AND customer_id = ? AND status = 'Baru'");
            $st->execute([now_ms(), (string) ($b['id'] ?? ''), $c]);
            if ($st->rowCount() < 1) fail('Pesanan sudah diproses dan tidak bisa dibatalkan.');
            out(['ok' => true]);
        }

        case 'order_status': {
            need_admin();
            $s = (string) ($b['status'] ?? '');
            if (!in_array($s, STATUSES, true)) fail('Status tidak valid.');
            $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')->execute([$s, now_ms(), (string) ($b['id'] ?? '')]);
            out(['ok' => true]);
        }

        case 'service_save': {
            need_admin();
            $patch = is_array($b['patch'] ?? null) ? $b['patch'] : [];
            $id = isset($b['id']) && $b['id'] !== '' ? (int) $b['id'] : null;
            $spec = [
                'name' => ['name', 'str', 60], 'icon' => ['icon', 'enum', ICONS], 'price' => ['price', 'int', 0],
                'desc' => ['descr', 'str', 120], 'active' => ['active', 'bool', 0],
            ];
            $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM services')->fetchColumn();
            $newId = save_row($pdo, 'services', $id, $patch, $spec, ['name' => 'Layanan baru', 'icon' => 'servis', 'price' => 0, 'descr' => '', 'active' => 1, 'sort' => $sort]);
            out(['ok' => true, 'id' => (string) $newId]);
        }

        case 'service_delete': {
            need_admin();
            $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
            out(['ok' => true]);
        }

        case 'workshop_save': {
            need_admin();
            $patch = is_array($b['patch'] ?? null) ? $b['patch'] : [];
            $id = isset($b['id']) && $b['id'] !== '' ? (int) $b['id'] : null;
            $spec = [
                'name' => ['name', 'str', 60], 'status' => ['status', 'enum', WS_STATUS],
                'rating' => ['rating', 'float', [0, 5]], 'reviews' => ['reviews', 'int', 0],
                'x' => ['x', 'float', [0, 100]], 'y' => ['y', 'float', [0, 80]], 'active' => ['active', 'bool', 0],
            ];
            $newId = save_row($pdo, 'workshops', $id, $patch, $spec, ['name' => 'Tukang baru', 'status' => 'Siap Datang', 'rating' => 5, 'reviews' => 0, 'x' => 50, 'y' => 40, 'active' => 1]);
            out(['ok' => true, 'id' => (string) $newId]);
        }

        case 'workshop_delete': {
            need_admin();
            $pdo->prepare('DELETE FROM workshops WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
            out(['ok' => true]);
        }

        case 'config_save': {
            need_admin();
            $patch = is_array($b['patch'] ?? null) ? $b['patch'] : [];
            $strs = ['appName', 'tagline', 'bannerTitle', 'bannerText', 'ewalletLabel'];
            $bools = ['payCash', 'payEwallet'];
            foreach ($patch as $k => $v) {
                if (in_array($k, $strs, true)) {
                    $val = cut($v, 120);
                    if ($val === '') continue;
                } elseif (in_array($k, $bools, true)) {
                    $val = !empty($v) ? '1' : '0';
                } else {
                    continue;
                }
                $u = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
                $u->execute([$val, $k]);
                $e = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE k = ?');
                $e->execute([$k]);
                if ((int) $e->fetchColumn() === 0) $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, $val]);
            }
            out(['ok' => true]);
        }

        case 'change_password': {
            need_admin();
            $old = (string) ($b['old'] ?? '');
            $new = (string) ($b['new'] ?? '');
            if (strlen($new) < 8) fail('Password baru minimal 8 karakter.');
            $st = $pdo->prepare('SELECT pass_hash FROM admins WHERE id = ?');
            $st->execute([$_SESSION['aid']]);
            $h = $st->fetchColumn();
            if (!$h || !password_verify($old, $h)) fail('Password lama salah.', 401);
            $pdo->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['aid']]);
            out(['ok' => true]);
        }

        default:
            fail('Aksi tidak dikenal.', 404);
    }
} catch (Throwable $e) {
    error_log('tambal-ban api: ' . $e->getMessage());
    fail('Terjadi kesalahan di server.', 500);
}
