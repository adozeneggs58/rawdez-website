<?php
/* ==========================================================================
   RAWdez admin - setup.php
   ONE-TIME page for choosing an admin password. It deletes itself when
   finished. Needs the setup code below so nobody else can claim a login
   in the minutes between uploading and setting the password.
   - First use: sets Bryan's password.
   - If Bryan's password already exists: adds Gavin's developer password
     (a second login on top of Bryan's). To remove Gavin's login later,
     delete the 'dev_hash' line from admin/private/config.php.
   ========================================================================== */
const SETUP_CODE = 'CHANGE-ME-BEFORE-UPLOADING';   // pick your own random code before uploading

require __DIR__ . '/lib.php';
if (SETUP_CODE === 'CHANGE-ME-BEFORE-UPLOADING') { http_response_code(403); exit('Set SETUP_CODE in admin/setup.php before using this page.'); }
$nonce = base64_encode(random_bytes(12));
secure_headers($nonce);
header('X-Robots-Tag: noindex');

function page($body) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>RAWdez setup</title><style>
:root{--cream:#efe6dd;--cherry:#9a0002;--ink:#171311;--gray:#6f6863}
body{margin:0;font-family:Archivo,Arial,sans-serif;background:var(--ink);color:var(--ink);display:grid;min-height:100vh;place-items:center;padding:20px}
.box{background:var(--cream);border-radius:24px;padding:32px;max-width:440px;width:100%}
h1{margin:0 0 6px;font-size:2rem;letter-spacing:-.05em}h1 b{color:var(--cherry)}
p{color:var(--gray);line-height:1.5}label{display:grid;gap:6px;font-weight:600;font-size:.85rem;margin-top:14px}
input{font:inherit;padding:13px 14px;border-radius:12px;border:1px solid rgba(23,19,17,.25);background:#fff}
button{margin-top:20px;width:100%;font:inherit;font-weight:900;padding:15px;border:0;border-radius:999px;background:var(--cherry);color:var(--cream);cursor:pointer}
.err{background:#fde8e8;color:#7d0002;padding:12px 14px;border-radius:12px;margin-top:14px}</style></head><body><div class="box">' . $body . '</div></body></html>';
    exit;
}

/* Which password is this page setting? */
$cfg = is_file(CONFIG) ? (require CONFIG) : null;
if (!is_array($cfg)) $cfg = null;
$mode = $cfg ? 'dev' : 'bryan';

if ($mode === 'dev' && !empty($cfg['dev_hash'])) {
    http_response_code(403);
    page('<h1>RAW<b>dez</b></h1><p>Setup is already done: both logins exist. Delete this file (<code>admin/setup.php</code>) in cPanel File Manager.</p>');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = 'setup-' . visitor_key();
    $wait = rl_wait($key);
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $p1 = (string)($_POST['p1'] ?? '');
    $p2 = (string)($_POST['p2'] ?? '');
    if ($wait) $err = 'Too many tries. Wait ' . ceil($wait / 60) . ' minutes.';
    elseif (!hash_equals(strtoupper(SETUP_CODE), $code)) { rl_fail($key); $err = 'That setup code is not right.'; }
    elseif (strlen($p1) < 10) $err = 'Use at least 10 characters. A few random words works well.';
    elseif ($p1 !== $p2) $err = 'The two passwords do not match.';
    elseif ($mode === 'dev' && password_verify($p1, $cfg['hash'])) $err = 'Pick a different password from Bryan\'s.';
    else {
        if ($mode === 'bryan') {
            $cfg = ['hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => date('c')];
        } else {
            $cfg['dev_hash'] = password_hash($p1, PASSWORD_DEFAULT);   // Bryan's password is kept as it is
        }
        $out = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents(CONFIG, $out, LOCK_EX) === false) {
            $err = 'Could not save. Make sure the admin/private folder exists and is writable.';
        } else {
            @chmod(CONFIG, 0600);
            @unlink(__FILE__);
            page('<h1>RAW<b>dez</b></h1><p><strong>All set.</strong> ' . ($mode === 'dev' ? 'Your developer login is added. Bryan\'s password still works too.' : 'Your password is saved.') . ' This setup page has removed itself.</p><p><a href="index.php">Go to the admin login</a></p>');
        }
    }
}

$intro = $mode === 'dev'
    ? 'Bryan\'s login already exists. This adds a <strong>second login for Gavin</strong> (developer). Bryan\'s password is not changed.'
    : 'Choose the password you will use to log in to the photo admin. Nobody else will ever see it.';
page('<h1>RAW<b>dez</b> setup</h1><p>' . $intro . '</p>'
   . ($err ? '<div class="err">' . htmlspecialchars($err) . '</div>' : '')
   . '<form method="post" autocomplete="off"><label>Setup code<input name="code" required autocomplete="off"></label>'
   . '<label>New password (10+ characters)<input type="password" name="p1" required autocomplete="new-password"></label>'
   . '<label>Type it again<input type="password" name="p2" required autocomplete="new-password"></label>'
   . '<button type="submit">Save password</button></form>');
