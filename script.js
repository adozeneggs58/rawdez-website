/* ==========================================================================
   RAWdez Photography - script.js
   Used by BOTH index.html and gallery.html. Each part checks that its
   section exists on the page, so one file safely serves both.

   1. Scrolling band      (homepage)
   2. Lightbox            (photo viewer, both pages)
   3. Photos              (reads data/photos.json, fills photo spots, builds gallery)
   4. Booking form        (homepage)
   ========================================================================== */


/* ---------- 1. Scrolling band ---------- */
var trk = document.getElementById('trk');
if (trk) {
  var band = '';
  for (var i = 0; i < 8; i++) band += '<span>Capturing RAW moments</span><span>Real moments, just RAW</span>';
  trk.innerHTML = band;
}


/* ---------- 2. Lightbox ----------
   lbOpen(list, index) shows list[index].file full screen.
   Arrows, keyboard left/right, and phone swipe move between photos. */
var lb = document.getElementById('lb');
var lbList = [], lbIdx = 0, lbFrom = null;
var lbImg = lb ? lb.querySelector('img') : null;

function lbShow() {
  var p = lbList[lbIdx];
  lbImg.src = p.file;
  lbImg.alt = p.alt || 'Photograph by Bryan Ruadez';
  // warm up the neighbours so the next click feels instant
  [lbIdx + 1, lbIdx - 1].forEach(function (n) {
    if (lbList[n]) (new Image()).src = lbList[n].file;
  });
}

function lbOpen(list, idx) {
  if (!lb || !list.length) return;
  lbList = list; lbIdx = idx; lbFrom = document.activeElement;
  lb.classList.toggle('single', list.length < 2);
  lbShow();
  lb.classList.add('on');
  document.body.style.overflow = 'hidden';       // stop the page scrolling behind
  document.getElementById('x').focus();
}

function lbClose() {
  lb.classList.remove('on');
  document.body.style.overflow = '';
  if (lbFrom && lbFrom.focus) lbFrom.focus();
}

function lbStep(d) {
  if (lbList.length < 2) return;
  lbIdx = (lbIdx + d + lbList.length) % lbList.length;
  lbShow();
}

if (lb) {
  document.getElementById('x').onclick = lbClose;
  lb.querySelector('.prev').onclick = function () { lbStep(-1); };
  lb.querySelector('.next').onclick = function () { lbStep(1); };
  lb.addEventListener('click', function (e) { if (e.target === lb) lbClose(); });   // click the dark area to close

  addEventListener('keydown', function (e) {
    if (!lb.classList.contains('on')) return;
    if (e.key === 'Escape') lbClose();
    if (e.key === 'ArrowLeft') lbStep(-1);
    if (e.key === 'ArrowRight') lbStep(1);
    if (e.key === 'Tab') {                        // keep keyboard focus inside the viewer
      var b = Array.prototype.filter.call(lb.querySelectorAll('button'), function (x) { return x.offsetParent; });
      var first = b[0], last = b[b.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  var sx = null;                                  // phone swipe
  lb.addEventListener('touchstart', function (e) { sx = e.changedTouches[0].clientX; }, { passive: true });
  lb.addEventListener('touchend', function (e) {
    if (sx === null) return;
    var dx = e.changedTouches[0].clientX - sx; sx = null;
    if (Math.abs(dx) > 50) lbStep(dx < 0 ? 1 : -1);
  }, { passive: true });
}


/* ---------- 3. Photos ----------
   data/photos.json is written by the admin page (/admin/). It lists every
   photo and which photo sits in each spot (data-slot="..." in index.html). */
var CATS = { graduation: 'Graduation', sports: 'Sports', portraits: 'Portraits', life: 'Life', product: 'Product' };

function pic(src, eager) {
  var im = document.createElement('img');
  im.src = src;
  im.alt = '';                                    // the spot's own label describes it
  im.decoding = 'async';
  if (eager) im.fetchPriority = 'high'; else im.loading = 'lazy';
  return im;
}

function fillSlots(d) {
  var byId = {};
  d.photos.forEach(function (p) { byId[p.id] = p; });

  document.querySelectorAll('[data-slot]').forEach(function (el) {
    var p = byId[d.slots[el.getAttribute('data-slot')]];
    if (!p) return;                               // no photo chosen: the placeholder stays
    var im = pic(p.file, el.getAttribute('data-slot') === 'hero');
    var c = d.crops && d.crops[el.getAttribute('data-slot')];     // framing chosen in the admin
    if (c) {
      im.style.objectPosition = c.x + '% ' + c.y + '%';
      im.style.transformOrigin = c.x + '% ' + c.y + '%';
      im.style.transform = 'scale(' + c.z + ')';
    }
    el.appendChild(im);
    el.classList.add('has');
    if (el.closest('.gal')) el.style.aspectRatio = p.w + '/' + p.h;   // show gallery photos uncropped
  });

  // Homepage "Selected work": clicking a photo opens the viewer
  var sel = [];
  document.querySelectorAll('.gal [data-slot]').forEach(function (el) {
    var p = byId[d.slots[el.getAttribute('data-slot')]];
    if (p) sel.push({ el: el.parentNode, p: p });
  });
  var list = sel.map(function (s) { return s.p; });
  sel.forEach(function (s, n) { s.el.onclick = function () { lbOpen(list, n); }; });
}

function buildGallery(d) {
  var grid = document.getElementById('grid');
  var chips = document.getElementById('chips');
  var empty = document.getElementById('empty');
  var all = d.photos.filter(function (p) { return p.published; })
                    .sort(function (a, b) { return b.added - a.added; });

  var cats = Object.keys(CATS).filter(function (c) { return all.some(function (p) { return p.cat === c; }); });
  var want = new URLSearchParams(location.search).get('c');
  var cur = cats.indexOf(want) > -1 ? want : 'all';

  function draw() {
    var show = all.filter(function (p) { return cur === 'all' || p.cat === cur; });
    grid.innerHTML = '';
    empty.hidden = show.length > 0;
    show.forEach(function (p, n) {
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('aria-label', 'Open photo ' + (n + 1));
      var ph = document.createElement('div');
      ph.className = 'ph has';
      ph.style.aspectRatio = p.w + '/' + p.h;
      ph.appendChild(pic(p.thumb, false));
      b.appendChild(ph);
      b.onclick = function () { lbOpen(show, n); };
      grid.appendChild(b);
    });
    Array.prototype.forEach.call(chips.children, function (c) {
      c.setAttribute('aria-pressed', c.getAttribute('data-c') === cur);
    });
  }

  if (cats.length > 1) {
    ['all'].concat(cats).forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'chip';
      b.setAttribute('data-c', c);
      b.textContent = c === 'all' ? 'All' : CATS[c];
      b.onclick = function () {
        cur = c; draw();
        if (history.replaceState) history.replaceState(null, '', c === 'all' ? 'gallery.html' : 'gallery.html?c=' + c);
      };
      chips.appendChild(b);
    });
  }
  draw();
}

if (document.querySelector('[data-slot]') || document.getElementById('grid')) {
  fetch('data/photos.json?v=' + Date.now(), { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      fillSlots(d);
      if (document.getElementById('grid')) buildGallery(d);
    })
    .catch(function () {                          // no photo list yet: leave placeholders
      var e = document.getElementById('empty');
      if (e) e.hidden = false;
    });
}


/* ---------- 4. Booking form ----------
   Sends the inquiry to contact.php, which emails Bryan. If the server can't
   be reached, falls back to opening the visitor's own email app. */
var form = document.getElementById('f');
if (form) {
  var loaded = Date.now();                        // used to spot bots that submit instantly
  form.onsubmit = function (e) {
    e.preventDefault();
    var d = new FormData(form);
    var btn = form.querySelector('.send'), label = document.getElementById('sl'), note = document.getElementById('fs');
    d.append('el', Date.now() - loaded);
    btn.disabled = true; label.textContent = 'Sending…'; note.textContent = ''; note.className = 'fs';

    function mailto() {
      var body = 'Name: ' + d.get('n') + '\nShoot: ' + d.get('t') + '\n\n' + d.get('m');
      location.href = 'mailto:ruadezphoto@gmail.com?subject=' +
        encodeURIComponent('Shoot inquiry: ' + d.get('t')) + '&body=' + encodeURIComponent(body);
    }

    fetch('contact.php', { method: 'POST', body: d })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.ok) {
          form.reset();
          note.className = 'fs good';
          note.textContent = j.message;
          label.textContent = 'Sent';
        } else {
          note.className = 'fs bad';
          note.textContent = j.message;
          btn.disabled = false; label.textContent = 'Send inquiry';
        }
      })
      .catch(function () {                        // server unreachable: use the visitor's email app
        btn.disabled = false; label.textContent = 'Send inquiry';
        mailto();
      });
  };
}
