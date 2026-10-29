<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

/* ---------- Output / flash helpers ---------- */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
    header('Location: ' . BASE_URL . $path);
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function show_flash(): void {
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['message']) . '</div>';
        }
        unset($_SESSION['flash']);
    }
}

/* ---------- CSRF protection ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }
}

/* ---------- Authentication ---------- */
function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_role(string ...$roles): void {
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.');
        redirect('/login.php');
    }
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied.');
    }
}

function dashboard_path(string $role): string {
    return match ($role) {
        'admin'  => '/admin/dashboard.php',
        'seller' => '/seller/dashboard.php',
        default  => '/buyer/dashboard.php',
    };
}

/* ---------- Formatting ---------- */
function money($amount): string {
    return 'KES ' . number_format((float)$amount, 0);
}

function age_label(int $months): string {
    if ($months < 12) {
        return $months . ' month' . ($months === 1 ? '' : 's');
    }
    $years = intdiv($months, 12);
    $rem   = $months % 12;
    return $years . ' yr' . ($years > 1 ? 's' : '') . ($rem ? ' ' . $rem . ' mo' : '');
}

/* ---------- Images ---------- */
function primary_image(PDO $pdo, int $dogId): string {
    $st = $pdo->prepare('SELECT file_name FROM dog_images WHERE dog_id = ? ORDER BY is_primary DESC, image_id ASC LIMIT 1');
    $st->execute([$dogId]);
    $file = $st->fetchColumn();
    return $file ? UPLOAD_URL . $file : BASE_URL . '/assets/img/no-photo.svg';
}

/**
 * Validates and stores uploaded dog photos. Returns list of saved file names.
 * Only real images (checked by content, not just extension), max 3 MB each.
 */
function save_dog_images(array $files, int $max = 5): array {
    $saved   = [];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (empty($files['name'][0])) {
        return $saved;
    }
    $count = min(count($files['name']), $max);
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > 3 * 1024 * 1024) continue;
        $mime = mime_content_type($files['tmp_name'][$i]);
        if (!isset($allowed[$mime])) continue;
        $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        if (move_uploaded_file($files['tmp_name'][$i], UPLOAD_DIR . $name)) {
            $saved[] = $name;
        }
    }
    return $saved;
}

function delete_image_file(string $fileName): void {
    $path = UPLOAD_DIR . basename($fileName);
    if (is_file($path)) {
        unlink($path);
    }
}

/* ---------- Contact helpers (call / WhatsApp) ---------- */
// Converts a Kenyan number such as 0712 345 678 or +254712345678 into 254712345678
function intl_phone(string $phone, string $countryCode = '254'): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strpos($digits, '00') === 0)                 $digits = substr($digits, 2);
    if (strpos($digits, $countryCode) === 0)         return $digits;
    if (strpos($digits, '0') === 0)                  return $countryCode . substr($digits, 1);
    return $countryCode . $digits;
}

function tel_link(string $phone): string {
    return 'tel:+' . intl_phone($phone);
}

function whatsapp_link(string $phone, string $dogName = ''): string {
    $text = $dogName !== ''
        ? "Hello, I saw {$dogName} on Heritage Paws and I'm interested. Is the dog still available?"
        : "Hello, I found you on Heritage Paws.";
    return 'https://wa.me/' . intl_phone($phone) . '?text=' . rawurlencode($text);
}

/* ---------- Visitor tracking (feeds the admin Reports page) ---------- */
function track_visit(?int $dogId = null): void {
    global $pdo;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (strpos($path, '/admin/') !== false) return;

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python|monitor/i', $ua)) return;

    if (BASE_URL !== '' && strpos($path, BASE_URL) === 0) $path = substr($path, strlen(BASE_URL));
    if ($path === '' || substr($path, -1) === '/') $path .= 'index.php';
    if ($path[0] !== '/') $path = '/' . $path;

    $vid = $_COOKIE['hp_vid'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
        $vid = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie('hp_vid', $vid, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
        }
    }

    $user   = $_SESSION['user'] ?? null;
    $device = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'mobile' : 'desktop';

    try {
        $pdo->prepare('INSERT INTO site_visits (visitor_id, user_id, user_role, page, dog_id, device) VALUES (?,?,?,?,?,?)')
            ->execute([$vid, $user['id'] ?? null, $user['role'] ?? 'guest', substr($path, 0, 190), $dogId, $device]);
    } catch (Throwable $e) {
        // tracking must never stop a page from loading
    }
}
/* ---------- Stud services helpers ---------- */
function fee_label(string $type, $amount): string {
    return match ($type) {
        'cash'       => money($amount),
        'puppy_pick' => 'Pick of the litter',
        default      => 'Negotiable',
    };
}

function heat_label(string $status, ?string $date = null): string {
    return match ($status) {
        'in_heat'       => 'In heat now',
        'expected_soon' => $date ? 'Expected in heat around ' . date('d M Y', strtotime($date)) : 'Expected in heat soon',
        default         => 'Heat timing not sure yet',
    };
}

// All photo file names of a stud dog or female request (primary first)
function listing_images(PDO $pdo, string $table, int $id): array {
    $cols = ['stud_images' => 'stud_id', 'female_images' => 'request_id'];   // whitelist
    if (!isset($cols[$table])) return [];
    $st = $pdo->prepare("SELECT file_name FROM {$table} WHERE {$cols[$table]} = ? ORDER BY is_primary DESC, image_id ASC");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function listing_image(PDO $pdo, string $table, int $id): string {
    $files = listing_images($pdo, $table, $id);
    return $files ? UPLOAD_URL . $files[0] : BASE_URL . '/assets/img/no-photo.svg';
}

// WhatsApp link with your own message text
function whatsapp_text_link(string $phone, string $text): string {
    return 'https://wa.me/' . intl_phone($phone) . '?text=' . rawurlencode($text);
}

// Photos are deleted from disk when a listing is removed
function delete_listing_files(PDO $pdo, string $table, int $id): void {
    foreach (listing_images($pdo, $table, $id) as $file) {
        delete_image_file($file);
    }
}