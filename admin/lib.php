<?php
/* ==========================================================================
   RAWdez admin - lib.php
   Shared helpers: settings, login safety, photo list (data/photos.json) and
   image processing. Not opened directly; index.php and setup.php load it.
   ========================================================================== */

define('ROOT', dirname(__DIR__));
define('DATA_FILE', ROOT . '/data/photos.json');
define('UP_DIR', ROOT . '/uploads');
define('THUMB_DIR', ROOT . '/uploads/t');
define('PRIV', __DIR__ . '/private');
define('CONFIG', PRIV . '/config.php');

/* The photo spots on the site. Key = data-slot in index.html. */
function slot_defs() {
    return [
        'hero'            => 'Homepage: big top photo (wide photos work best)',
        'card_graduation' => 'Work card: Graduation',
        'card_sports'     => 'Work card: Sports',
        'card_portraits'  => 'Work card: Portraits',
        'card_life'       => 'Work card: Life',
        'sel_1'           => 'Selected work 1',
        'sel_2'           => 'Selected work 2',
        'sel_3'           => 'Selected work 3',
        'sel_4'           => 'Selected work 4',
        'sel_5'           => 'Selected work 5',
        'sel_6'           => 'Selected work 6',
        'about'           => 'About Bryan (portrait works best)',
    ];
}

/* Spots whose photo is cropped to a fixed shape, so Bryan can choose the framing.
   Value = [computer shape, phone shape or null] as width/height. (Selected work and the
   gallery always show the whole photo, so they need no crop.) */
function crop_slots() {
    return [
        'hero'            => [1.8, 0.5],
        'card_graduation' => [0.62, 0.9],
        'card_sports'     => [0.62, 0.9],
        'card_portraits'  => [0.62, 0.9],
        'card_life'       => [0.62, 0.9],
        'about'           => [0.8, null],
    ];
}

/* Photo categories (also the gallery filter buttons). Add one here AND in
   the CATS line of script.js to add a new category. */
function cat_defs() {
    return ['graduation' => 'Graduation', 'sports' => 'Sports', 'portraits' => 'Portraits', 'life' => 'Life', 'product' => 'Product'];
}

/* ---------- security headers + session ---------- */
function secure_headers($nonce) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'nonce-$nonce'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

function start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
    session_name('rawdez_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function authed() {
    if (empty($_SESSION['ok'])) return false;
    if (time() - ($_SESSION['last'] ?? 0) > 7200) {   // logged out after 2 hours idle
        $_SESSION = [];
        return false;
    }
    $_SESSION['last'] = time();
    return true;
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_ok() {
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/* ---------- login attempt limit: 5 misses per 15 minutes per visitor ---------- */
function rl_file() { return PRIV . '/attempts.json'; }

function rl_load() {
    $d = is_file(rl_file()) ? json_decode((string)file_get_contents(rl_file()), true) : [];
    return is_array($d) ? $d : [];
}

function rl_wait($key) {            // seconds the visitor must still wait (0 = fine)
    $d = rl_load();
    $now = time();
    $hits = array_values(array_filter($d[$key] ?? [], function ($t) use ($now) { return $t > $now - 900; }));
    if (count($hits) >= 5) return max(1, $hits[0] + 900 - $now);
    return 0;
}

function rl_fail($key) {
    $d = rl_load();
    $now = time();
    foreach ($d as $k => $v) {
        $d[$k] = array_values(array_filter($v, function ($t) use ($now) { return $t > $now - 900; }));
        if (!$d[$k]) unset($d[$k]);
    }
    $d[$key][] = $now;
    file_put_contents(rl_file(), json_encode($d), LOCK_EX);
}

function rl_clear($key) {
    $d = rl_load();
    unset($d[$key]);
    file_put_contents(rl_file(), json_encode($d), LOCK_EX);
}

function visitor_key() { return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0'); }

/* ---------- photo list ---------- */
function load_data() {
    $d = is_file(DATA_FILE) ? json_decode((string)file_get_contents(DATA_FILE), true) : null;
    if (!is_array($d)) $d = [];
    if (!isset($d['slots']) || !is_array($d['slots'])) $d['slots'] = [];
    if (!isset($d['photos']) || !is_array($d['photos'])) $d['photos'] = [];
    if (!isset($d['crops']) || !is_array($d['crops'])) $d['crops'] = [];
    return $d;
}

/* Read, change, and save the photo list in one locked step so two clicks
   at once can never overwrite each other. $fn receives the list by reference. */
function mutate($fn) {
    $fh = fopen(PRIV . '/lock', 'c');
    flock($fh, LOCK_EX);
    try {
        $d = load_data();
        $r = $fn($d);
        $d['slots'] = (object)$d['slots'];     // keep {} (not []) when empty
        $d['crops'] = (object)$d['crops'];
        $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $d['slots'] = (array)$d['slots'];
        $d['crops'] = (array)$d['crops'];
        $tmp = DATA_FILE . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, DATA_FILE)) {
            throw new Exception('Could not save. Check that the data folder is writable.');
        }
        return $r;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function find_photo(array $d, $id) {
    foreach ($d['photos'] as $i => $p) if ($p['id'] === $id) return $i;
    return -1;
}

/* ---------- image processing ---------- */
function gd_open($path, $type) {
    if ($type === IMAGETYPE_JPEG) return @imagecreatefromjpeg($path);
    if ($type === IMAGETYPE_PNG)  return @imagecreatefrompng($path);
    if ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($path);
    return false;
}

function fit($im, $max) {           // shrink to $max px on the long edge; flatten onto white
    $w = imagesx($im); $h = imagesy($im);
    $s = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
    $o = imagecreatetruecolor($nw, $nh);
    imagefill($o, 0, 0, imagecolorallocate($o, 255, 255, 255));
    imagecopyresampled($o, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $o;
}

function mem_bytes() {
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
    return $n;
}

/* Validate, fix rotation, strip camera/location data (re-encoding drops it),
   and save a 1600px version plus a 640px thumbnail. Returns the new record. */
function save_upload($tmp, $cat, $publish) {
    $info = @getimagesize($tmp);
    if (!$info) throw new Exception('That file is not a picture.');
    $type = $info[2];
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new Exception('Please use JPG, PNG or WebP pictures.');
    $w = (int)$info[0]; $h = (int)$info[1];
    if ($w < 300 || $h < 300) throw new Exception('That picture is too small (needs at least 300 pixels).');
    if ($w * $h > 100000000) throw new Exception('That picture is huge. Shrink it first.');
    if (mem_bytes() < $w * $h * 6 + 64 * 1048576) {
        @ini_set('memory_limit', '512M');
        if (mem_bytes() < $w * $h * 6 + 64 * 1048576) throw new Exception('That picture is too big for this server. Shrink it first.');
    }
    $im = gd_open($tmp, $type);
    if (!$im) throw new Exception('Could not read that picture.');

    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($tmp);
        $o = is_array($ex) ? (int)($ex['Orientation'] ?? 1) : 1;
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg) { $r = imagerotate($im, $deg, 0); if ($r) $im = $r; }
    }

    $main = fit($im, 1600);
    $thumb = fit($im, 640);
    imagedestroy($im);

    $id = 'p' . bin2hex(random_bytes(5));
    imageinterlace($main, 1); imageinterlace($thumb, 1);
    if (!is_dir(THUMB_DIR)) @mkdir(THUMB_DIR, 0755, true);
    if (!imagejpeg($main, UP_DIR . "/$id.jpg", 82) || !imagejpeg($thumb, THUMB_DIR . "/$id.jpg", 78)) {
        throw new Exception('Could not save the picture. Check that the uploads folder is writable.');
    }
    $rec = [
        'id' => $id, 'file' => "uploads/$id.jpg", 'thumb' => "uploads/t/$id.jpg",
        'w' => imagesx($main), 'h' => imagesy($main),
        'cat' => $cat, 'published' => (bool)$publish, 'added' => time(),
    ];
    imagedestroy($main); imagedestroy($thumb);
    return $rec;
}
