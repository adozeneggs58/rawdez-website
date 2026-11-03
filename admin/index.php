<?php
/* ==========================================================================
   RAWdez admin - index.php
   Login page and the photo dashboard for Bryan.
   - Website photos: choose which photo sits in each spot on the homepage.
   - Library: every uploaded photo, with category and Published/Hidden.
   - Only Published photos appear on the public gallery page.
   ========================================================================== */
require __DIR__ . '/lib.php';

$nonce = base64_encode(random_bytes(12));
secure_headers($nonce);
header('X-Robots-Tag: noindex');
start_session();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function out($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

function state() {
    $d = load_data();
    usort($d['photos'], function ($a, $b) { return ($b['added'] ?? 0) <=> ($a['added'] ?? 0); });
    return ['slots' => (object)$d['slots'], 'crops' => (object)$d['crops'], 'photos' => $d['photos']];
}

function shell_top($title) {
    global $nonce;
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><link rel="icon" href="../favicon.svg" type="image/svg+xml"><title>' . h($title) . '</title><style>
:root{--cream:#efe6dd;--light:#f8f3ee;--cherry:#9a0002;--ink:#171311;--gray:#6f6863;--line:rgba(23,19,17,.16)}
*{box-sizing:border-box}body{margin:0;font-family:Archivo,Arial,Helvetica,sans-serif;background:var(--ink);color:var(--ink)}
button,input,select{font:inherit}a{color:inherit}
.btn{border:0;border-radius:999px;padding:11px 18px;font-weight:800;cursor:pointer;background:var(--cherry);color:var(--cream);text-decoration:none;display:inline-block;font-size:.9rem}
.btn.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}.btn.sm{padding:8px 14px;font-size:.8rem}.btn.danger{background:transparent;color:var(--cherry);border:1px solid var(--cherry)}
.btn:disabled{opacity:.5;cursor:default}
.login{min-height:100vh;display:grid;place-items:center;padding:20px}
.box{background:var(--cream);border-radius:24px;padding:32px;max-width:420px;width:100%}
.logo{font-weight:900;font-size:1.8rem;letter-spacing:-.05em}.logo b{color:var(--cherry)}
label{display:grid;gap:6px;font-weight:600;font-size:.85rem;margin-top:14px}
input[type=password],input[type=text]{padding:13px 14px;border-radius:12px;border:1px solid rgba(23,19,17,.25);background:#fff}
.err{background:#fde8e8;color:#7d0002;padding:12px 14px;border-radius:12px;margin-top:14px}
</style></head><body>';
}

/* ---------- not set up yet ---------- */
if (!is_file(CONFIG)) {
    echo shell_top('RAWdez admin') . '<div class="login"><div class="box"><div class="logo">RAW<b>dez</b></div><p>The admin is not set up yet. Open <a href="setup.php">setup.php</a> to choose a password.</p></div></div></body></html>';
    exit;
}
$cfg = require CONFIG;

$action = $_POST['action'] ?? '';

/* ---------- login ---------- */
$loginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $key = 'login-' . visitor_key();
    $wait = rl_wait($key);
    if ($wait) {
        $loginErr = 'Too many tries. Please wait ' . ceil($wait / 60) . ' minutes and try again.';
    } elseif (csrf_ok() && password_verify((string)($_POST['pw'] ?? ''), $cfg['hash'])) {
        rl_clear($key);
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['last'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: index.php');
        exit;
    } else {
        rl_fail($key);
        sleep(1);
        $loginErr = 'That password is not right.';
    }
}

/* ---------- API (logged-in only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'login') {
    if (!authed()) out(['ok' => false, 'error' => 'Your session ended. Please log in again.', 'login' => true], 401);
    if (!csrf_ok()) out(['ok' => false, 'error' => 'Please refresh the page and try again.'], 403);
    $cats = cat_defs();
    $slots = slot_defs();
    try {
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);
        }

        if ($action === 'upload') {
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $m = ($f && ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)) ? 'That file is larger than this server allows.' : 'The upload did not finish. Try again.';
                throw new Exception($m);
            }
            if (!is_uploaded_file($f['tmp_name'])) throw new Exception('Upload failed.');
            $cat = $_POST['cat'] ?? 'life';
            if (!isset($cats[$cat])) $cat = 'life';
            $rec = save_upload($f['tmp_name'], $cat, ($_POST['publish'] ?? '') === '1');
            $slot = $_POST['slot'] ?? '';
            mutate(function (&$d) use ($rec, $slot, $slots) {
                $d['photos'][] = $rec;
                if ($slot !== '' && isset($slots[$slot])) { $d['slots'][$slot] = $rec['id']; unset($d['crops'][$slot]); }
            });
            out(['ok' => true, 'state' => state(), 'id' => $rec['id']]);
        }

        if ($action === 'assign') {
            $slot = $_POST['slot'] ?? ''; $id = $_POST['id'] ?? '';
            if (!isset($slots[$slot])) throw new Exception('Unknown spot.');
            mutate(function (&$d) use ($slot, $id) {
                if (find_photo($d, $id) < 0) throw new Exception('Photo not found.');
                $d['slots'][$slot] = $id;
                unset($d['crops'][$slot]);          // a new photo starts centered
            });
            out(['ok' => true, 'state' => state()]);
        }

        if ($action === 'crop') {
            $slot = $_POST['slot'] ?? '';
            if (!isset(crop_slots()[$slot])) throw new Exception('That spot does not need cropping.');
            $x = max(0, min(100, round((float)($_POST['x'] ?? 50), 1)));
            $y = max(0, min(100, round((float)($_POST['y'] ?? 50), 1)));
            $z = max(1, min(3, round((float)($_POST['z'] ?? 1), 2)));
            $reset = ($_POST['reset'] ?? '') === '1' || ($x == 50 && $y == 50 && $z == 1);
            mutate(function (&$d) use ($slot, $x, $y, $z, $reset) {
                if ($reset) unset($d['crops'][$slot]);
                else $d['crops'][$slot] = ['x' => $x, 'y' => $y, 'z' => $z];
            });
            out(['ok' => true, 'state' => state()]);
        }

        if ($action === 'update') {
            $id = $_POST['id'] ?? '';
            mutate(function (&$d) use ($id, $cats) {
                $i = find_photo($d, $id);
                if ($i < 0) throw new Exception('Photo not found.');
                if (isset($_POST['cat']) && isset($cats[$_POST['cat']])) $d['photos'][$i]['cat'] = $_POST['cat'];
                if (isset($_POST['published'])) $d['photos'][$i]['published'] = $_POST['published'] === '1';
            });
            out(['ok' => true, 'state' => state()]);
        }

        if ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            mutate(function (&$d) use ($id, $slots) {
                $i = find_photo($d, $id);
                if ($i < 0) throw new Exception('Photo not found.');
                foreach ($d['slots'] as $s => $pid) {
                    if ($pid === $id) throw new Exception('That photo is used on the homepage (' . ($slots[$s] ?? $s) . '). Put a different photo there first.');
                }
                $p = $d['photos'][$i];
                @unlink(ROOT . '/' . $p['file']);
                @unlink(ROOT . '/' . $p['thumb']);
                array_splice($d['photos'], $i, 1);
            });
            out(['ok' => true, 'state' => state()]);
        }

        if ($action === 'password') {
            $cur = (string)($_POST['cur'] ?? ''); $n1 = (string)($_POST['n1'] ?? ''); $n2 = (string)($_POST['n2'] ?? '');
            if (!password_verify($cur, $cfg['hash'])) throw new Exception('Your current password is not right.');
            if (strlen($n1) < 10) throw new Exception('Use at least 10 characters.');
            if ($n1 !== $n2) throw new Exception('The new passwords do not match.');
            $new = "<?php\nreturn " . var_export(['hash' => password_hash($n1, PASSWORD_DEFAULT), 'created' => $cfg['created'] ?? date('c')], true) . ";\n";
            if (file_put_contents(CONFIG, $new, LOCK_EX) === false) throw new Exception('Could not save the new password.');
            out(['ok' => true]);
        }

        throw new Exception('Unknown request.');
    } catch (Exception $e) {
        out(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

/* ---------- login page ---------- */
if (!authed()) {
    echo shell_top('RAWdez admin login') . '<div class="login"><form class="box" method="post" action="index.php">'
       . '<div class="logo">RAW<b>dez</b> admin</div><p style="color:var(--gray)">Log in to change the photos on the website.</p>'
       . ($loginErr ? '<div class="err">' . h($loginErr) . '</div>' : '')
       . '<input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
       . '<label>Password<input type="password" name="pw" required autofocus autocomplete="current-password"></label>'
       . '<p><button class="btn" type="submit" style="width:100%;padding:15px">Log in</button></p></form></div></body></html>';
    exit;
}

/* ---------- dashboard ---------- */
$inq = [];
$lf = PRIV . '/inquiries.jsonl';
if (is_file($lf)) {
    foreach (array_reverse(file($lf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
        $j = json_decode($line, true);
        if (is_array($j)) $inq[] = $j;
        if (count($inq) >= 50) break;
    }
}
$boot = json_encode([
    'inq' => $inq,
    'csrf' => csrf_token(),
    'state' => state(),
    'slots' => slot_defs(),
    'crop' => crop_slots(),
    'cats' => cat_defs(),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<title>RAWdez photo admin</title>
<style>
:root{--cream:#efe6dd;--light:#f8f3ee;--cherry:#9a0002;--ink:#171311;--gray:#6f6863;--line:rgba(23,19,17,.16)}
*{box-sizing:border-box}
body{margin:0;font-family:Archivo,Arial,Helvetica,sans-serif;background:var(--ink);color:var(--ink)}
button,input,select{font:inherit}
.wrap{max-width:1180px;margin:10px auto;background:var(--cream);border-radius:24px;overflow:hidden}
header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:18px 28px;border-bottom:1px solid var(--line);background:var(--light)}
.logo{font-weight:900;font-size:1.5rem;letter-spacing:-.05em}.logo b{color:var(--cherry)}
.hd{display:flex;gap:10px;flex-wrap:wrap}
main{padding:28px}
section{margin-bottom:44px}
h2{margin:0 0 6px;font-size:1.6rem;letter-spacing:-.04em}
.help{margin:0 0 18px;color:var(--gray);line-height:1.5;max-width:70ch}
.btn{border:0;border-radius:999px;padding:11px 18px;font-weight:800;cursor:pointer;background:var(--cherry);color:var(--cream);text-decoration:none;display:inline-block;font-size:.9rem}
.btn.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}
.btn.sm{padding:8px 14px;font-size:.8rem}
.btn.danger{background:transparent;color:var(--cherry);border:1px solid var(--cherry)}
.btn:disabled{opacity:.5;cursor:default}
.drop{border:2px dashed rgba(23,19,17,.3);border-radius:18px;padding:22px;background:var(--light)}
.controls{display:flex;gap:14px;flex-wrap:wrap;align-items:end;margin-bottom:14px}
label{display:grid;gap:6px;font-weight:600;font-size:.82rem}
select,input[type=text],input[type=password]{padding:10px 12px;border-radius:10px;border:1px solid rgba(23,19,17,.25);background:#fff}
.chk{display:flex;align-items:center;gap:8px;font-weight:600;font-size:.9rem;padding-bottom:10px}
#log{margin:14px 0 0;padding:0;list-style:none;font-size:.85rem}
#log li{padding:4px 0}#log .bad{color:var(--cherry);font-weight:600}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
.tile{background:var(--light);border-radius:14px;overflow:hidden;border:1px solid var(--line);display:flex;flex-direction:column}
.tile .im{aspect-ratio:4/3;background:#d9cfc5;position:relative}
.tile img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.tile .im .none{position:absolute;inset:0;display:grid;place-items:center;color:var(--gray);font-size:.85rem}
.tile .meta{padding:10px 12px;display:grid;gap:8px}
.tile .nm{font-weight:700;font-size:.85rem;line-height:1.25}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.badge{font-size:.7rem;font-weight:800;border-radius:999px;padding:3px 9px;background:#d9cfc5}
.badge.on{background:#cfe8d2;color:#14501f}.badge.off{background:#eadfd5;color:var(--gray)}
.tabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.tab{border:1px solid var(--line);background:transparent;border-radius:999px;padding:8px 16px;font-weight:600;cursor:pointer}
.tab[aria-pressed=true]{background:var(--ink);color:var(--cream);border-color:var(--ink)}
.modal{position:fixed;inset:0;background:rgba(15,12,11,.8);display:none;place-items:center;padding:16px;z-index:10}
.modal.on{display:grid}
.mbox{background:var(--cream);border-radius:20px;padding:22px;max-width:900px;width:100%;max-height:90vh;overflow:auto}
.mbox h3{margin:0 0 4px;font-size:1.3rem}
.pick{cursor:pointer;border:3px solid transparent;border-radius:12px;padding:0;background:none}
.pick:focus-visible,.pick:hover{border-color:var(--cherry)}
.pick img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:9px;display:block}
.pick.cur{border-color:var(--ink)}
#toast{position:fixed;left:50%;bottom:20px;transform:translateX(-50%);background:var(--ink);color:var(--cream);padding:12px 20px;border-radius:999px;font-weight:600;display:none;z-index:20;max-width:90vw}
#toast.bad{background:var(--cherry)}
details{margin-top:8px}summary{cursor:pointer;font-weight:700}
.inq{background:var(--light);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:10px;line-height:1.45}
.inq .top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-weight:700}
.inq .when{color:var(--gray);font-weight:400;font-size:.85rem}
.inq p{margin:8px 0 0;white-space:pre-wrap;word-break:break-word}
.cwrap{display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start;margin:12px 0}
.cwrap figure{margin:0}.cwrap figcaption{font-size:.78rem;font-weight:700;color:var(--gray);margin-top:6px}
.cbox{position:relative;overflow:hidden;border-radius:12px;background:#222;touch-action:none;cursor:grab;outline-offset:3px}
.cbox:active{cursor:grabbing}
.cbox img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;pointer-events:none;user-select:none;-webkit-user-drag:none}
.zoom{display:grid;gap:6px;max-width:360px;font-weight:600;font-size:.85rem;margin:6px 0 14px}
.zoom input{width:100%}
.pw{display:grid;gap:10px;max-width:340px;margin-top:12px}
@media(max-width:560px){main{padding:18px}header{padding:14px 18px}.grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="wrap">
<header>
  <div class="logo">RAW<b>dez</b> <span style="font-weight:600;letter-spacing:0;font-size:1rem;color:var(--gray)">photo admin</span></div>
  <div class="hd"><a class="btn ghost sm" href="../" target="_blank" rel="noopener">View site</a><a class="btn ghost sm" href="../gallery.html" target="_blank" rel="noopener">View gallery</a><button class="btn sm" id="out" type="button">Log out</button></div>
</header>
<main>

<section>
  <h2>1. Add photos</h2>
  <p class="help">Pick one or many photos from your phone or computer. They are resized automatically and location data is removed. New photos start as <strong>Hidden</strong> unless you tick the box, so nothing shows to clients until you say so.</p>
  <div class="drop">
    <div class="controls">
      <label>Category<select id="upcat"></select></label>
      <label class="chk"><input type="checkbox" id="uppub"> Show in the gallery right away</label>
      <button class="btn" id="upbtn" type="button">Choose photos</button>
      <input type="file" id="upfile" accept="image/jpeg,image/png,image/webp" multiple hidden>
    </div>
    <ul id="log" aria-live="polite"></ul>
  </div>
</section>

<section>
  <h2>2. Photos on the homepage</h2>
  <p class="help">These are the spots on the main page. Press <strong>Change</strong> to swap in any photo from your library (or upload a new one right there), or <strong>Crop</strong> to choose how it is framed. The change is live right away.</p>
  <div class="grid" id="slots"></div>
</section>

<section>
  <h2>3. Photo library</h2>
  <p class="help"><strong>Published</strong> photos appear on the public Gallery page. <strong>Hidden</strong> photos are kept as backups and only you can see them here.</p>
  <div class="tabs" id="tabs"></div>
  <div class="grid" id="lib"></div>
</section>

<section>
  <h2>4. Booking inquiries</h2>
  <p class="help">Every message sent through the website form is emailed to Bryan and also saved here, in case an email lands in spam. Showing the newest 50.</p>
  <div id="inq"></div>
</section>

<section>
  <h2>Account</h2>
  <details><summary>Change my password</summary>
    <form class="pw" id="pwform">
      <label>Current password<input type="password" name="cur" required autocomplete="current-password"></label>
      <label>New password (10+ characters)<input type="password" name="n1" required autocomplete="new-password"></label>
      <label>New password again<input type="password" name="n2" required autocomplete="new-password"></label>
      <button class="btn" type="submit">Save new password</button>
    </form>
  </details>
</section>
</main>
</div>

<div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="mt">
  <div class="mbox">
    <h3 id="mt">Choose a photo</h3>
    <p class="help" id="ms"></p>
    <div class="controls"><button class="btn sm" id="mup" type="button">Upload a new photo for this spot</button><button class="btn ghost sm" id="mclose" type="button">Cancel</button></div>
    <div class="grid" id="mgrid"></div>
  </div>
</div>
<div class="modal" id="cmodal" role="dialog" aria-modal="true" aria-labelledby="ct">
  <div class="mbox">
    <h3 id="ct">Crop</h3>
    <p class="help">Drag the photo to choose what shows. Use the slider to zoom in. The previews show how it will look on a computer and on a phone. Your original photo is never changed, and you can reset any time.</p>
    <div class="cwrap" id="cprev"></div>
    <label class="zoom">Zoom<input type="range" id="czoom" min="1" max="3" step="0.02" value="1"></label>
    <div class="row"><button class="btn" id="csave" type="button">Save crop</button><button class="btn ghost" id="creset" type="button">Reset to centered</button><button class="btn ghost" id="cclose" type="button">Cancel</button></div>
  </div>
</div>
<div id="toast" role="status"></div>

<script nonce="<?= h($nonce) ?>">
var B = <?= $boot ?>;
var S = B.state, slotNo = null, filter = 'all';

function $(id){ return document.getElementById(id); }
function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function toast(m, bad){ var t=$('toast'); t.textContent=m; t.className=bad?'bad':''; t.style.display='block'; clearTimeout(toast.t); toast.t=setTimeout(function(){t.style.display='none';}, 3500); }

function api(fd){
  fd.append('csrf', B.csrf);
  return fetch('index.php', {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){
    return r.json().then(function(j){
      if (j.login) { location.reload(); }
      if (!j.ok) throw new Error(j.error || 'Something went wrong.');
      return j;
    }, function(){ throw new Error('The server sent an unexpected reply.'); });
  });
}
function call(o){ var fd=new FormData(); for (var k in o) fd.append(k, o[k]); return api(fd); }
function apply(j){ if (j.state) { S = j.state; render(); } return j; }
function fail(e){ toast(e.message, true); }

function photo(id){ for (var i=0;i<S.photos.length;i++) if (S.photos[i].id===id) return S.photos[i]; return null; }
function slotNames(id){ var a=[]; for (var s in S.slots) if (S.slots[s]===id) a.push(B.slots[s]); return a; }

function render(){
  // homepage spots
  var h = '';
  Object.keys(B.slots).forEach(function(k){
    var p = photo(S.slots[k]);
    h += '<div class="tile"><div class="im">' + (p ? '<img src="../'+esc(p.thumb)+'" alt="">' : '<div class="none">No photo yet</div>') + '</div>' +
         '<div class="meta"><div class="nm">'+esc(B.slots[k])+'</div><div class="row"><button class="btn sm" data-slot="'+k+'" type="button">Change</button>' +
         (p && B.crop[k] ? '<button class="btn sm ghost" data-crop="'+k+'" type="button">Crop'+(S.crops && S.crops[k] ? ' ✓' : '')+'</button>' : '') + '</div></div></div>';
  });
  $('slots').innerHTML = h;

  // library tabs
  var n = {all:S.photos.length, pub:0, hid:0};
  S.photos.forEach(function(p){ if (p.published) n.pub++; else n.hid++; });
  $('tabs').innerHTML = [['all','All'],['pub','Published'],['hid','Hidden']].map(function(t){
    return '<button class="tab" data-f="'+t[0]+'" aria-pressed="'+(filter===t[0])+'" type="button">'+t[1]+' ('+n[t[0]]+')</button>';
  }).join('');

  var list = S.photos.filter(function(p){ return filter==='all' || (filter==='pub') === !!p.published; });
  var opts = function(sel){ return Object.keys(B.cats).map(function(c){ return '<option value="'+c+'"'+(c===sel?' selected':'')+'>'+esc(B.cats[c])+'</option>'; }).join(''); };
  $('lib').innerHTML = list.length ? list.map(function(p){
    var used = slotNames(p.id);
    return '<div class="tile"><div class="im"><img src="../'+esc(p.thumb)+'" alt="" loading="lazy"></div><div class="meta">' +
      '<div class="row"><span class="badge '+(p.published?'on':'off')+'">'+(p.published?'Published':'Hidden')+'</span>'+(used.length?'<span class="badge">On homepage</span>':'')+'</div>' +
      '<select data-cat="'+p.id+'" aria-label="Category">'+opts(p.cat)+'</select>' +
      '<div class="row"><button class="btn sm ghost" data-pub="'+p.id+'" type="button">'+(p.published?'Hide':'Publish')+'</button>' +
      '<button class="btn sm danger" data-del="'+p.id+'" type="button">Delete</button></div></div></div>';
  }).join('') : '<p class="help">Nothing here yet.</p>';
}

// category dropdown for uploads
$('upcat').innerHTML = Object.keys(B.cats).map(function(c){ return '<option value="'+c+'">'+esc(B.cats[c])+'</option>'; }).join('');

// ----- client-side shrinking so big camera files upload fast -----
function shrink(file){
  if (!window.createImageBitmap) return Promise.resolve(file);
  return createImageBitmap(file, {imageOrientation:'from-image'}).then(function(bmp){
    var s = Math.min(1, 2000/Math.max(bmp.width, bmp.height));
    var c = document.createElement('canvas'); c.width = Math.round(bmp.width*s); c.height = Math.round(bmp.height*s);
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
    return new Promise(function(res){ c.toBlob(function(b){ res(b ? new File([b], 'photo.jpg', {type:'image/jpeg'}) : file); }, 'image/jpeg', .9); });
  }).catch(function(){ return file; });
}

function uploadFiles(files, extra, logEl){
  var arr = Array.prototype.slice.call(files), ok = 0;
  function next(i){
    if (i >= arr.length) return Promise.resolve(ok);
    var li = document.createElement('li'); li.textContent = 'Uploading ' + (i+1) + ' of ' + arr.length + '…';
    if (logEl) logEl.appendChild(li);
    return shrink(arr[i]).then(function(f){
      var fd = new FormData();
      fd.append('action','upload'); fd.append('file', f);
      for (var k in extra) fd.append(k, extra[k]);
      return api(fd);
    }).then(function(j){ apply(j); ok++; li.textContent = '✓ ' + arr[i].name; })
      .catch(function(e){ li.className='bad'; li.textContent = '✗ ' + arr[i].name + ': ' + e.message; })
      .then(function(){ return next(i+1); });
  }
  return next(0);
}

$('upbtn').onclick = function(){ $('upfile').click(); };
$('upfile').onchange = function(){
  if (!this.files.length) return;
  var inp = this; $('upbtn').disabled = true; $('log').innerHTML = '';
  uploadFiles(inp.files, {cat:$('upcat').value, publish:$('uppub').checked?'1':'0'}, $('log')).then(function(n){
    $('upbtn').disabled = false; inp.value = '';
    toast(n + ' photo' + (n===1?'':'s') + ' added');
  });
};

// ----- homepage spot picker -----
function openPicker(k){
  slotNo = k; $('mt').textContent = 'Change: ' + B.slots[k];
  $('ms').textContent = 'Tap a photo to use it here.';
  var cur = S.slots[k];
  $('mgrid').innerHTML = S.photos.map(function(p){
    return '<button class="pick'+(p.id===cur?' cur':'')+'" data-id="'+p.id+'" type="button"><img src="../'+esc(p.thumb)+'" alt="Photo"></button>';
  }).join('') || '<p class="help">No photos yet. Upload one first.</p>';
  $('modal').classList.add('on');
}
function closePicker(){ $('modal').classList.remove('on'); slotNo = null; }
$('mclose').onclick = closePicker;
$('modal').addEventListener('click', function(e){ if (e.target === $('modal')) closePicker(); });
addEventListener('keydown', function(e){ if (e.key==='Escape') { closePicker(); closeCrop(); } });
$('mup').onclick = function(){
  var f = document.createElement('input'); f.type='file'; f.accept='image/jpeg,image/png,image/webp';
  f.onchange = function(){
    if (!f.files.length) return;
    var k = slotNo; $('mup').disabled = true; $('ms').textContent = 'Uploading…';
    uploadFiles(f.files, {cat:'life', publish:'0', slot:k}, null).then(function(n){
      $('mup').disabled = false;
      if (n) { closePicker(); toast('Photo changed'); } else { $('ms').textContent = 'That photo could not be added. Try another.'; }
    });
  };
  f.click();
};

// ----- clicks -----
document.addEventListener('click', function(e){
  var t = e.target.closest('button'); if (!t) return;
  if (t.dataset.slot) openPicker(t.dataset.slot);
  else if (t.dataset.crop) openCrop(t.dataset.crop);
  else if (t.dataset.f) { filter = t.dataset.f; render(); }
  else if (t.dataset.id && slotNo) {
    call({action:'assign', slot:slotNo, id:t.dataset.id}).then(apply).then(function(){ closePicker(); toast('Photo changed'); }).catch(fail);
  }
  else if (t.dataset.pub) {
    var p = photo(t.dataset.pub);
    call({action:'update', id:p.id, published:p.published?'0':'1'}).then(apply).then(function(){ toast(p.published ? 'Hidden from the gallery' : 'Published to the gallery'); }).catch(fail);
  }
  else if (t.dataset.del) {
    if (confirm('Delete this photo for good? This cannot be undone.')) call({action:'delete', id:t.dataset.del}).then(apply).then(function(){ toast('Deleted'); }).catch(fail);
  }
});
document.addEventListener('change', function(e){
  var s = e.target; if (s.dataset && s.dataset.cat) call({action:'update', id:s.dataset.cat, cat:s.value}).then(apply).then(function(){ toast('Category saved'); }).catch(fail);
});

$('pwform').onsubmit = function(e){
  e.preventDefault();
  var fd = new FormData(e.target); fd.append('action','password');
  api(fd).then(function(){ e.target.reset(); toast('Password changed'); }).catch(fail);
};
$('out').onclick = function(){ call({action:'logout'}).then(function(){ location.reload(); }).catch(function(){ location.reload(); }); };

// ----- crop editor -----
// The photo is shown with object-fit:cover; "x,y" is the focus point (percent) and "z" the zoom.
// The public site uses these same three numbers, so what you see here is what visitors get.
var cs = {slot:null, x:50, y:50, z:1}, boxes = [];
function paintCrop(){
  boxes.forEach(function(b){
    var im = b.querySelector('img');
    im.style.objectPosition = cs.x+'% '+cs.y+'%';
    im.style.transformOrigin = cs.x+'% '+cs.y+'%';
    im.style.transform = 'scale('+cs.z+')';
  });
}
function clamp(v){ return Math.max(0, Math.min(100, v)); }
function makeBox(src, ar, label){
  var f = document.createElement('figure'), b = document.createElement('div'), im = document.createElement('img');
  b.className = 'cbox'; b.tabIndex = 0; b.setAttribute('aria-label', label + ' preview. Drag, or use arrow keys, to move the photo.');
  if (ar >= 1) { f.style.width = 'min(100%, 460px)'; f.style.flex = '1 1 300px'; b.style.width = '100%'; b.style.aspectRatio = ar; }
  else { var h = ar < 0.7 ? 300 : 260; b.style.height = h+'px'; b.style.width = Math.round(h*ar)+'px'; }
  im.src = src; im.alt = ''; b.appendChild(im);
  var cap = document.createElement('figcaption'); cap.textContent = label;
  f.appendChild(b); f.appendChild(cap);
  var drag = null;
  b.addEventListener('pointerdown', function(e){ b.setPointerCapture(e.pointerId); drag = {x:e.clientX, y:e.clientY, px:cs.x, py:cs.y}; });
  b.addEventListener('pointermove', function(e){
    if (!drag) return;
    var bw = b.clientWidth, bh = b.clientHeight, iw = im.naturalWidth, ih = im.naturalHeight;
    if (!iw) return;
    var s = Math.max(bw/iw, bh/ih) * cs.z, dw = iw*s, dh = ih*s;   // photo size as shown
    if (dw - bw > 1) cs.x = clamp(drag.px + (e.clientX-drag.x)/(bw-dw)*100);
    if (dh - bh > 1) cs.y = clamp(drag.py + (e.clientY-drag.y)/(bh-dh)*100);
    paintCrop();
  });
  var end = function(){ drag = null; };
  b.addEventListener('pointerup', end); b.addEventListener('pointercancel', end);
  b.addEventListener('keydown', function(e){
    var d = {ArrowLeft:[2,0], ArrowRight:[-2,0], ArrowUp:[0,2], ArrowDown:[0,-2]}[e.key];
    if (!d) return; e.preventDefault();
    cs.x = clamp(cs.x + d[0]); cs.y = clamp(cs.y + d[1]); paintCrop();
  });
  boxes.push(b);
  return f;
}
function openCrop(k){
  var p = photo(S.slots[k]); if (!p) return;
  var c = (S.crops && S.crops[k]) || {x:50, y:50, z:1};
  cs = {slot:k, x:+c.x, y:+c.y, z:+c.z}; boxes = [];
  $('ct').textContent = 'Crop: ' + B.slots[k];
  var wrap = $('cprev'); wrap.innerHTML = '';
  var shapes = B.crop[k];
  wrap.appendChild(makeBox('../'+p.file, shapes[0], 'Computer'));
  if (shapes[1]) wrap.appendChild(makeBox('../'+p.file, shapes[1], 'Phone'));
  $('czoom').value = cs.z;
  paintCrop();
  $('cmodal').classList.add('on');
}
function closeCrop(){ $('cmodal').classList.remove('on'); cs.slot = null; }
$('cclose').onclick = closeCrop;
$('cmodal').addEventListener('click', function(e){ if (e.target === $('cmodal')) closeCrop(); });
$('czoom').oninput = function(){ cs.z = +this.value; paintCrop(); };
$('creset').onclick = function(){ cs.x = 50; cs.y = 50; cs.z = 1; $('czoom').value = 1; paintCrop(); };
$('csave').onclick = function(){
  call({action:'crop', slot:cs.slot, x:cs.x, y:cs.y, z:cs.z}).then(apply).then(function(){ closeCrop(); toast('Crop saved'); }).catch(fail);
};

function renderInq(){
  var h = (B.inq || []).map(function(q){
    var d = new Date(q.t*1000).toLocaleString([], {dateStyle:'medium', timeStyle:'short'});
    return '<div class="inq"><div class="top"><span>'+esc(q.name)+' &middot; '+esc(q.type)+'</span><span class="when">'+esc(d)+'</span></div>' +
      '<div><a href="mailto:'+esc(q.email)+'">'+esc(q.email)+'</a></div>' + (q.msg ? '<p>'+esc(q.msg)+'</p>' : '') + '</div>';
  }).join('');
  $('inq').innerHTML = h || '<p class="help">No inquiries yet.</p>';
}
renderInq();
render();
</script>
</body>
</html>
