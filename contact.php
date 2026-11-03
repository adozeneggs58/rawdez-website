<?php
/* ==========================================================================
   RAWdez - contact.php
   Receives the booking inquiry form (sent by script.js) and emails it to
   Bryan. Also keeps a copy in admin/private/inquiries.jsonl so an inquiry
   is never lost if Gmail sends one to spam. Bryan can read them in /admin/.
   ========================================================================== */
const TO_EMAIL = 'ruadezphoto@gmail.com';
const TYPES = ['Graduation', 'Sports', 'Portraits', 'Life'];

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply($ok, $msg, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, 'Not allowed.', 405);

/* Same-site check: only accept forms sent from this website */
$host = preg_replace('/:\d+$/', '', preg_replace('/^www\./i', '', strtolower($_SERVER['HTTP_HOST'] ?? '')));   // no www, no port
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $oh = preg_replace('/^www\./i', '', strtolower((string)parse_url($origin, PHP_URL_HOST)));
    if ($oh !== $host) reply(false, 'Not allowed.', 403);
}

/* Spam traps: a hidden field real people never fill in, and a minimum time on the page */
if (trim((string)($_POST['website'] ?? '')) !== '') reply(true, 'Thanks!');          // pretend success for bots
if ((int)($_POST['el'] ?? 0) < 2500) reply(false, 'Please take a moment to fill in the form, then try again.', 400);

$clean = function ($s, $max) {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$s));
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
};
$name  = str_replace(["\r", "\n"], ' ', $clean($_POST['n'] ?? '', 100));
$email = str_replace(["\r", "\n"], '', $clean($_POST['e'] ?? '', 150));
$type  = $clean($_POST['t'] ?? '', 30);
$msg   = $clean($_POST['m'] ?? '', 3000);

if ($name === '') reply(false, 'Please enter your name.', 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(false, 'Please enter a valid email address.', 400);
if (!in_array($type, TYPES, true)) reply(false, 'Please choose a shoot type.', 400);

/* Limit: 5 inquiries per hour from one visitor */
$priv = __DIR__ . '/admin/private';
$rf = $priv . '/mail-rate.json';
$key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0');
$now = time();
$rate = is_file($rf) ? json_decode((string)file_get_contents($rf), true) : [];
if (!is_array($rate)) $rate = [];
foreach ($rate as $k => $v) {
    $rate[$k] = array_values(array_filter($v, function ($t) use ($now) { return $t > $now - 3600; }));
    if (!$rate[$k]) unset($rate[$k]);
}
if (count($rate[$key] ?? []) >= 5) reply(false, 'Too many messages from this device. Please try again later or email Bryan directly.', 429);
$rate[$key][] = $now;
if (is_dir($priv)) @file_put_contents($rf, json_encode($rate), LOCK_EX);

/* Keep a copy (newest 200) */
$entry = ['t' => $now, 'name' => $name, 'email' => $email, 'type' => $type, 'msg' => $msg];
if (is_dir($priv)) {
    $lf = $priv . '/inquiries.jsonl';
    $lines = is_file($lf) ? file($lf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE);
    $lines = array_slice($lines, -200);
    @file_put_contents($lf, implode("\n", $lines) . "\n", LOCK_EX);
}

/* Send the email */
$from = 'noreply@' . ($host ?: 'rawdez.com');
$subject = 'RAWdez inquiry: ' . $type . ' - ' . $name;
$when = (new DateTime('@' . $now))->setTimezone(new DateTimeZone('America/New_York'))->format('D M j, Y g:i A');
$body = "New inquiry from the RAWdez website\r\n\r\n"
      . "Name: $name\r\nEmail: $email\r\nShoot type: $type\r\nReceived: $when (Eastern)\r\n\r\n"
      . "Details:\r\n" . ($msg !== '' ? $msg : '(none)') . "\r\n\r\n"
      . "Reply to this email to answer the person directly.\r\n";
$hn = trim(preg_replace('/[<>",;:]/', '', $name));
$headers = "From: RAWdez Website <$from>\r\nReply-To: $hn <$email>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: RAWdez";
$subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$sent = @mail(TO_EMAIL, $subject, $body, $headers, '-f' . $from);
if (!$sent) $sent = @mail(TO_EMAIL, $subject, $body, $headers);

if (!$sent) reply(false, 'Sorry, the message could not be sent. Please email ruadezphoto@gmail.com directly.', 500);
reply(true, 'Thanks! Bryan will be in touch soon.');
