/**
 * Lifeline Daily — Stage 3 Interactions Script
 * Keyboard-accessible desktop menu (FR3-07), account hub tab navigation (FR3-06).
 */
(function () {
  'use strict';

  function initDesktopUserMenu() {
    const chip = document.querySelector('.lld-chip');
    if (!chip) return;

    // The links come from PHP (a data attribute on the hub), and data-lld-sign-out names the hub's sign-out form:
    // a POST to the Stage 2 sign-out carrying its nonce. Without them no menu is built and the chip stays a plain
    // link to settings.
    const hub = document.querySelector('[data-lld-menu][data-lld-sign-out]');
    if (!hub) return;
    let links;
    try {
      links = JSON.parse(hub.getAttribute('data-lld-menu') || '{}');
    } catch (err) {
      return;
    }
    const signOut = document.getElementById(hub.getAttribute('data-lld-sign-out') || '');
    if (!signOut || signOut.tagName !== 'FORM' || !links.profile || !links.subscription || !links.data || !links.help) return;

    let menu = document.getElementById('lld-desktop-user-menu');
    if (!menu) {
      menu = document.createElement('div');
      menu.id = 'lld-desktop-user-menu';
      menu.className = 'lld-desktop-menu';
      menu.hidden = true;
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-label', 'User account menu');

      const items = [
        [links.profile, 'Profile', ''],
        [links.subscription, 'My subscription', ''],
        [links.data, 'My data', ''],
        [links.help, 'Help', ''],
        null,
        [signOut, 'Sign out', ' lld-menu-signout']
      ];
      items.forEach(function (item) {
        if (!item) {
          const hr = document.createElement('hr');
          hr.className = 'lld-menu-divider';
          menu.appendChild(hr);
          return;
        }
        let a;
        if (item[0] === signOut) {
          // Sign out posts the hub's sign-out form (with its nonce); it is never a link.
          a = document.createElement('button');
          a.type = 'button';
          a.addEventListener('click', function () {
            signOut.submit();
          });
        } else {
          a = document.createElement('a');
          a.href = item[0];
        }
        a.textContent = item[1];
        a.setAttribute('role', 'menuitem');
        a.className = 'lld-menu-item' + item[2];
        menu.appendChild(a);
      });

      // Wrap chip in container if not wrapped
      const parent = chip.parentNode;
      if (!parent.classList.contains('lld-chip-wrap')) {
        const wrap = document.createElement('div');
        wrap.className = 'lld-chip-wrap';
        parent.insertBefore(wrap, chip);
        wrap.appendChild(chip);
        wrap.appendChild(menu);
      } else {
        parent.appendChild(menu);
      }
    }

    chip.setAttribute('aria-haspopup', 'true');
    chip.setAttribute('aria-expanded', 'false');

    function toggleMenu(open) {
      const willOpen = typeof open === 'boolean' ? open : menu.hidden;
      menu.hidden = !willOpen;
      chip.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) {
        const first = menu.querySelector('[role="menuitem"]');
        if (first) first.focus();
      }
    }

    // On desktop viewports (>= 768px), intercept click on chip to toggle menu
    chip.addEventListener('click', function (e) {
      if (window.innerWidth >= 768) {
        e.preventDefault();
        toggleMenu();
      }
    });

    // Keyboard support on the chip
    chip.addEventListener('keydown', function (e) {
      if (window.innerWidth >= 768) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
          e.preventDefault();
          toggleMenu(true);
        }
      }
    });

    // Keyboard navigation inside the menu (TC-FR3-07-AS-03)
    menu.addEventListener('keydown', function (e) {
      const items = Array.from(menu.querySelectorAll('[role="menuitem"]'));
      const currentIndex = items.indexOf(document.activeElement);

      if (e.key === 'Escape') {
        e.preventDefault();
        toggleMenu(false);
        chip.focus();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        const next = (currentIndex + 1) % items.length;
        items[next].focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = (currentIndex - 1 + items.length) % items.length;
        items[prev].focus();
      } else if (e.key === 'Tab') {
        // Close menu if tabbing out
        setTimeout(function () {
          if (!menu.contains(document.activeElement) && document.activeElement !== chip) {
            toggleMenu(false);
          }
        }, 10);
      }
    });

    // Close when clicking outside
    document.addEventListener('click', function (e) {
      if (!menu.hidden && !chip.contains(e.target) && !menu.contains(e.target)) {
        toggleMenu(false);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDesktopUserMenu);
  } else {
    initDesktopUserMenu();
  }
})();

/*
 * Calculator pages (P3-57 to P3-5A): pressing an answer or a button must not move focus to the page's main area. The
 * Stage 2 script brings a newly focused element into view (lld-members.js keepInView), so focusing a tall <main> on
 * press scrolled the page under the pointer and the release missed the answer. Preventing the press's default keeps
 * focus where it is; the click still reaches the label, which checks its radio and focuses it.
 */
(function () {
  'use strict';
  document.addEventListener('mousedown', function (e) {
    // The radio and the buttons too: WebKit focuses neither on click, so focus would still fall to <main>.
    var hit = e.target && e.target.closest ? e.target.closest('.lld-calc-option, .lld-stage3-calc button') : null;
    if (hit) {
      e.preventDefault();
    }
  });
})();

/*
 * Barcode scanning in the browser (P3-44, FR3-37): /members/food/scan/.
 *
 * LLDBarcodeDecoder reads EAN-13 (and UPC-A, which is EAN-13 starting 0), EAN-8 and UPC-E from camera frames, for
 * browsers without the built-in BarcodeDetector (Safari on iPhone). It reads straight lines across the picture: each
 * line is turned into dark and light runs against a local average, the start, middle and end guards are found, and
 * each digit is the nearest of the GS1 width patterns. A read counts only with a correct check digit and when two lines
 * agree, so a misread is very unlikely. No code is fetched from anywhere else.
 */
(function (root) {
  'use strict';

  // Module widths (space, bar, space, bar) of the L-code digits; R-codes have the same widths as bar, space, bar, space;
  // G-codes are the L widths reversed.
  var L = ['3211', '2221', '2122', '1411', '1132', '1231', '1114', '1312', '1213', '3112'].map(function (s) { return s.split('').map(Number); });
  var G = L.map(function (p) { return p.slice().reverse(); });
  var EAN13_FIRST = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLL', 'LGLGGL', 'LGGLGL'];
  // UPC-E, number system 0: parity of the six digits by check digit (O = L-code, E = G-code). Number system 1 is the inverse.
  var UPCE_PARITY = ['EEEOOO', 'EEOEOO', 'EEOOEO', 'EEOOOE', 'EOEEOO', 'EOOEEO', 'EOOOEE', 'EOEOEO', 'EOEOOE', 'EOOEOE'];

  function checkOk(code) {
    if (!/^\d{8,14}$/.test(code)) return false;
    var sum = 0;
    for (var i = code.length - 2, w = 3; i >= 0; i--, w = 4 - w) sum += Number(code[i]) * w;
    return (10 - (sum % 10)) % 10 === Number(code[code.length - 1]);
  }

  function upceToUpca(e) {
    if (!/^[01]\d{7}$/.test(e)) return '';
    var d = e.slice(1, 7), last = Number(d[5]), body;
    if (last <= 2) body = d[0] + d[1] + d[5] + '0000' + d[2] + d[3] + d[4];
    else if (last === 3) body = d[0] + d[1] + d[2] + '00000' + d[3] + d[4];
    else if (last === 4) body = d[0] + d[1] + d[2] + d[3] + '00000' + d[4];
    else body = d[0] + d[1] + d[2] + d[3] + d[4] + '0000' + d[5];
    var a = e[0] + body + e[7];
    return checkOk(a) ? a : '';
  }

  // Dark and light runs along one line of brightness values (0 to 255). A pixel is dark or light against a moving
  // average, with a small dead band so noise does not split a bar; each edge is placed where the brightness crosses the
  // average, to a fraction of a pixel, so narrow bars keep their true widths.
  function runs(line) {
    var n = line.length;
    if (n < 60) return [];
    var lo = 255, hi = 0;
    for (var i = 0; i < n; i++) {
      if (line[i] < lo) lo = line[i];
      if (line[i] > hi) hi = line[i];
    }
    if (hi - lo < 40) return [];
    // The threshold is halfway between the darkest and lightest values nearby, so the white margin beside the bars
    // does not pull it up; where there is little contrast nearby, the pixel counts as light.
    var half = Math.max(16, Math.round(n / 10));
    var band = Math.max(3, (hi - lo) * 0.05);
    var d = new Float64Array(n);
    // Sliding-window minimum and maximum (two queues of positions), so each line costs one pass.
    var qmin = new Int32Array(n), qmax = new Int32Array(n), h1 = 0, t1 = 0, h2 = 0, t2 = 0, next = 0;
    for (var x = 0; x < n; x++) {
      for (; next <= Math.min(n - 1, x + half); next++) {
        while (t1 > h1 && line[qmin[t1 - 1]] >= line[next]) t1--;
        qmin[t1++] = next;
        while (t2 > h2 && line[qmax[t2 - 1]] <= line[next]) t2--;
        qmax[t2++] = next;
      }
      while (qmin[h1] < x - half) h1++;
      while (qmax[h2] < x - half) h2++;
      var mn = line[qmin[h1]], mx = line[qmax[h2]];
      d[x] = mx - mn < (hi - lo) * 0.25 ? band + 1 : line[x] - (mn + mx) / 2;
    }
    var out = [], state = -1, edge = 0;
    for (var y = 0; y < n; y++) {
      var s = d[y] < -band ? 1 : d[y] > band ? 0 : state;
      if (state === -1) {
        if (s !== -1) {
          state = s;
          edge = 0;
        }
        continue;
      }
      if (s === state) continue;
      // The crossing: the last place before y where the difference changes sign.
      var k = y, pos = y;
      while (k > 0 && (d[k - 1] < 0) === (d[y] < 0)) k--;
      if (k > 0) pos = k - 1 + d[k - 1] / (d[k - 1] - d[k]);
      if (pos <= edge) pos = edge + 0.5;
      out.push({ dark: state, len: pos - edge });
      edge = pos;
      state = s;
    }
    if (state !== -1) out.push({ dark: state, len: n - edge });
    return out;
  }

  function near(w, unit, modules) {
    var m = w / unit;
    return m > modules * 0.5 - 0.15 && m < modules * 1.5 + 0.35;
  }

  // The nearest digit for four run widths; null when nothing is close enough.
  function digit(widths, tables) {
    var total = widths[0] + widths[1] + widths[2] + widths[3];
    var m = widths.map(function (w) { return (w * 7) / total; });
    var best = null, bestErr = 99, second = 99;
    tables.forEach(function (table, ti) {
      table.forEach(function (p, d) {
        var err = Math.abs(m[0] - p[0]) + Math.abs(m[1] - p[1]) + Math.abs(m[2] - p[2]) + Math.abs(m[3] - p[3]);
        if (err < bestErr) {
          second = bestErr;
          bestErr = err;
          best = { digit: d, table: ti };
        } else if (err < second) {
          second = err;
        }
      });
    });
    if (!best || bestErr > 1.6 || second - bestErr < 0.15) return null;
    return best;
  }

  function widthsAt(r, i) {
    return [r[i].len, r[i + 1].len, r[i + 2].len, r[i + 3].len];
  }

  function guards(r, at, count, unit) {
    for (var k = 0; k < count; k++) if (!near(r[at + k].len, unit, 1)) return false;
    return true;
  }

  // A white margin of at least three bars' width before the start and after the end of the barcode.
  function quiet(r, i, count, unit) {
    return (i === 0 || r[i - 1].len >= unit * 3) && (i + count >= r.length || r[i + count].len >= unit * 3);
  }

  function ean13(r, i) {
    if (i + 59 > r.length) return null;
    var total = 0;
    for (var k = 0; k < 59; k++) total += r[i + k].len;
    var unit = total / 95;
    if (!guards(r, i, 3, unit) || !guards(r, i + 27, 5, unit) || !guards(r, i + 56, 3, unit) || !quiet(r, i, 59, unit)) return null;
    var code = '', parity = '';
    for (var a = 0; a < 6; a++) {
      var d = digit(widthsAt(r, i + 3 + a * 4), [L, G]);
      if (!d) return null;
      code += d.digit;
      parity += d.table ? 'G' : 'L';
    }
    for (var b = 0; b < 6; b++) {
      var e = digit(widthsAt(r, i + 32 + b * 4), [L]);
      if (!e) return null;
      code += e.digit;
    }
    var first = EAN13_FIRST.indexOf(parity);
    if (first < 0) return null;
    code = String(first) + code;
    return checkOk(code) ? code : null;
  }

  function ean8(r, i) {
    if (i + 43 > r.length) return null;
    var total = 0;
    for (var k = 0; k < 43; k++) total += r[i + k].len;
    var unit = total / 67;
    if (!guards(r, i, 3, unit) || !guards(r, i + 19, 5, unit) || !guards(r, i + 40, 3, unit) || !quiet(r, i, 43, unit)) return null;
    var code = '';
    for (var a = 0; a < 8; a++) {
      var d = digit(widthsAt(r, i + 3 + a * 4 + (a >= 4 ? 5 : 0)), [L]);
      if (!d) return null;
      code += d.digit;
    }
    return checkOk(code) ? code : null;
  }

  function upce(r, i) {
    if (i + 33 > r.length) return null;
    var total = 0;
    for (var k = 0; k < 33; k++) total += r[i + k].len;
    var unit = total / 51;
    if (!guards(r, i, 3, unit) || !guards(r, i + 27, 6, unit) || !quiet(r, i, 33, unit)) return null;
    var digits = '', parity = '';
    for (var a = 0; a < 6; a++) {
      var d = digit(widthsAt(r, i + 3 + a * 4), [L, G]);
      if (!d) return null;
      digits += d.digit;
      parity += d.table ? 'E' : 'O';
    }
    var inverse = parity.replace(/[OE]/g, function (c) { return c === 'O' ? 'E' : 'O'; });
    var ns = -1, check = UPCE_PARITY.indexOf(parity);
    if (check >= 0) ns = 0;
    else if ((check = UPCE_PARITY.indexOf(inverse)) >= 0) ns = 1;
    if (ns < 0) return null;
    var code = String(ns) + digits + String(check);
    return upceToUpca(code) ? code : null;
  }

  // Every barcode read along one line, in one direction.
  function readRuns(r) {
    var found = [];
    for (var i = 0; i < r.length; i++) {
      if (!r[i].dark) continue;
      var c = ean13(r, i) || ean8(r, i) || upce(r, i);
      if (c) found.push(c);
    }
    return found;
  }

  function decodeLine(line) {
    var r = runs(line);
    if (!r.length) return [];
    var forward = readRuns(r);
    var back = readRuns(r.slice().reverse());
    return forward.concat(back);
  }

  // Brightness lines across an RGBA picture: rows between 15% and 85% of the height, and a few columns for a barcode
  // held upright. A code read on two lines wins.
  function decodeImage(data, width, height) {
    var counts = {};
    var lines = [];
    var rows = 17;
    for (var k = 0; k < rows; k++) lines.push({ row: Math.round(height * (0.15 + (0.7 * k) / (rows - 1))) });
    for (var c = 0; c < 7; c++) lines.push({ col: Math.round(width * (0.2 + (0.6 * c) / 6)) });
    for (var n = 0; n < lines.length; n++) {
      var spec = lines[n], line;
      // Each line is the average of a band of five, which takes out most of the camera's noise.
      if (spec.row !== undefined) {
        line = new Float64Array(width);
        for (var dy = -2; dy <= 2; dy++) {
          var row = Math.min(height - 1, Math.max(0, spec.row + dy));
          for (var x = 0, p = row * width * 4; x < width; x++, p += 4) line[x] += (data[p] * 299 + data[p + 1] * 587 + data[p + 2] * 114) / 5000;
        }
      } else {
        line = new Float64Array(height);
        for (var dx = -2; dx <= 2; dx++) {
          var col = Math.min(width - 1, Math.max(0, spec.col + dx));
          for (var y = 0, q = col * 4; y < height; y++, q += width * 4) line[y] += (data[q] * 299 + data[q + 1] * 587 + data[q + 2] * 114) / 5000;
        }
      }
      var hits = decodeLine(line);
      for (var h = 0; h < hits.length; h++) {
        counts[hits[h]] = (counts[hits[h]] || 0) + 1;
        if (counts[hits[h]] >= 2) return hits[h];
      }
    }
    return null;
  }

  root.LLDBarcodeDecoder = { checkOk: checkOk, upceToUpca: upceToUpca, runs: runs, decodeLine: decodeLine, decodeImage: decodeImage };
})(typeof window !== 'undefined' ? window : globalThis);

(function () {
  'use strict';
  if (typeof document === 'undefined') return;

  function init() {
    var page = document.querySelector('[data-lld-scan]');
    if (!page) return;
    var api = page.getAttribute('data-lld-api') || '';
    var nonce = page.getAttribute('data-lld-nonce') || '';
    var today = page.getAttribute('data-lld-today') || '';
    var consent = page.getAttribute('data-lld-consent') === 'yes';
    var hideAll = page.getAttribute('data-lld-hide-nutrition') === 'yes';
    var hideProtein = page.getAttribute('data-lld-hide-protein') === 'yes';
    var words = {};
    try {
      words = JSON.parse(page.getAttribute('data-lld-words') || '{}');
    } catch (err) {
      words = {};
    }
    var resultBox = page.querySelector('[data-lld-scan-result]');
    var video = page.querySelector('[data-lld-scan-video]');
    var view = page.querySelector('[data-lld-scan-view]');
    var startBtn = page.querySelector('[data-lld-scan-start]');
    var stopBtn = page.querySelector('[data-lld-scan-stop]');
    var status = page.querySelector('[data-lld-scan-status]');
    var typedForm = page.querySelector('[data-lld-barcode-form]');
    var typedInput = page.querySelector('#lld-barcode-number');
    var searchForm = page.querySelector('[data-lld-food-search-form]');
    var searchStatus = page.querySelector('[data-lld-search-status]');
    var hitsList = page.querySelector('[data-lld-food-results]');
    var stream = null, timer = null, detector = null, lastRead = '', busy = false;

    function el(tag, attrs, text) {
      var node = document.createElement(tag);
      Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
      if (text !== undefined && text !== null) node.textContent = String(text);
      return node;
    }

    function call(method, path, body) {
      var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, Accept: 'application/json' } };
      if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
      return fetch(api + path, opts).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      });
    }

    function say(node, text) {
      if (node) node.textContent = text || '';
    }

    function show(nodes) {
      if (!resultBox) return;
      resultBox.textContent = '';
      nodes.forEach(function (n) { resultBox.appendChild(n); });
      resultBox.hidden = false;
      resultBox.focus();
    }

    // ---- Camera --------------------------------------------------------------------------------------------------

    function stopCamera() {
      if (timer) clearTimeout(timer);
      timer = null;
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
      if (video) video.srcObject = null;
      if (view) view.hidden = true;
      if (stopBtn) stopBtn.hidden = true;
      if (startBtn) startBtn.hidden = false;
    }

    function cameraUnavailable(refused) {
      stopCamera();
      page.setAttribute('data-lld-camera', refused ? 'refused' : 'missing');
      say(status, refused ? words.camera_refused : words.camera_missing);
      if (startBtn) startBtn.hidden = true;
      if (typedInput) typedInput.focus();
    }

    function makeDetector() {
      if (!('BarcodeDetector' in window)) return Promise.resolve(null);
      var wanted = ['ean_13', 'ean_8', 'upc_a', 'upc_e'];
      var formats = window.BarcodeDetector.getSupportedFormats ? window.BarcodeDetector.getSupportedFormats() : Promise.resolve(wanted);
      return Promise.resolve(formats).then(function (have) {
        var use = wanted.filter(function (f) { return have.indexOf(f) >= 0; });
        return use.length ? new window.BarcodeDetector({ formats: use }) : null;
      }).catch(function () { return null; });
    }

    var canvas = null, ctx = null;
    function readFrame() {
      if (!video || video.readyState < 2 || !video.videoWidth) return Promise.resolve(null);
      if (detector) {
        return Promise.resolve(detector.detect(video)).then(function (codes) {
          for (var i = 0; i < codes.length; i++) {
            var v = String(codes[i].rawValue || '').replace(/\D/g, '');
            if (window.LLDBarcodeDecoder.checkOk(v) || window.LLDBarcodeDecoder.upceToUpca(v)) return v;
          }
          return null;
        }).catch(function () { return null; });
      }
      // Own reader: a picture at most 1280 pixels wide, read along lines.
      var scale = Math.min(1, 1280 / video.videoWidth);
      var w = Math.round(video.videoWidth * scale), h = Math.round(video.videoHeight * scale);
      if (!canvas) {
        canvas = document.createElement('canvas');
        ctx = canvas.getContext('2d', { willReadFrequently: true });
      }
      canvas.width = w;
      canvas.height = h;
      ctx.drawImage(video, 0, 0, w, h);
      return Promise.resolve(window.LLDBarcodeDecoder.decodeImage(ctx.getImageData(0, 0, w, h).data, w, h));
    }

    function loop() {
      if (!stream) return;
      var frame;
      try {
        frame = readFrame();
      } catch (err) {
        // One frame that cannot be read (the camera still starting, say) is skipped; the next one is tried.
        if (window.console) console.warn('lld-scan: frame skipped', err && err.name);
        frame = Promise.resolve(null);
      }
      frame.then(function (code) {
        if (!stream) return;
        // The built-in detector is trusted on one read; the script's own reader needs the same code in two frames.
        if (code && (detector || code === lastRead)) {
          stopCamera();
          if (navigator.vibrate) navigator.vibrate(60);
          lookup(code);
          return;
        }
        lastRead = code || lastRead;
        timer = setTimeout(loop, 120);
      });
    }

    function startCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        cameraUnavailable(false);
        return;
      }
      say(status, 'Starting the camera…');
      var ask = function (constraints) {
        try {
          return Promise.resolve(navigator.mediaDevices.getUserMedia(constraints));
        } catch (err) {
          return Promise.reject(err);
        }
      };
      // The back camera at a good size if the phone has one; a browser that cannot meet those wishes gets asked
      // for any camera instead.
      var asked = ask({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false }).catch(function (err) {
        if (err && (err.name === 'OverconstrainedError' || err.name === 'ConstraintNotSatisfiedError' || err.name === 'TypeError')) {
          return ask({ video: true, audio: false });
        }
        throw err;
      });
      // Only the camera request itself decides "refused" or "no camera"; what happens after it has started does not.
      Promise.resolve(asked).then(function (s) {
        stream = s;
        video.srcObject = s;
        view.hidden = false;
        startBtn.hidden = true;
        stopBtn.hidden = false;
        page.setAttribute('data-lld-camera', 'on');
        say(status, 'Looking for a barcode…');
        var play;
        try {
          play = video.play();
        } catch (err) {
          play = null;
        }
        Promise.resolve(play).catch(function () {}).then(makeDetector).then(function (d) {
          detector = d;
          lastRead = '';
          loop();
        });
      }, function (err) {
        var name = err && err.name ? err.name : '';
        if (window.console) console.warn('lld-scan: camera not available', name);
        cameraUnavailable(name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError');
      });
    }

    // ---- Product cards -------------------------------------------------------------------------------------------

    function round(v, places) {
      var f = Math.pow(10, places);
      return Math.round(v * f) / f;
    }

    function productCard(food, note, hidden) {
      var nodes = [];
      nodes.push(el('h2', { 'data-lld-product-name': '' }, food.name));
      if (food.brand) nodes.push(el('p', { class: 'lld-hint', 'data-lld-product-brand': '' }, food.brand));
      if (note) nodes.push(el('p', { class: 'lld-notice-box', 'data-lld-safety-note': '' }, note));

      var form = el('form', { class: 'lld-scan-log', 'data-lld-log-form': '' });
      var portionId = 'lld-scan-portion', gramsId = 'lld-scan-grams', mealId = 'lld-scan-meal';
      var pWrap = el('p');
      pWrap.appendChild(el('label', { for: portionId }, 'Portion'));
      var portion = el('select', { id: portionId });
      (food.portions || []).forEach(function (p, i) {
        var o = el('option', { value: String(p.grams) }, p.name + (/\d\s*g\b/.test(p.name) ? '' : ' (' + round(p.grams, 1) + ' g)'));
        if (i === 0) o.selected = true;
        portion.appendChild(o);
      });
      pWrap.appendChild(portion);
      form.appendChild(pWrap);

      var gWrap = el('p');
      gWrap.appendChild(el('label', { for: gramsId }, 'Amount in grams'));
      var grams = el('input', { id: gramsId, type: 'number', inputmode: 'decimal', min: '1', max: '5000', step: 'any', required: '' });
      grams.value = String(round(Number(food.default_grams) || 100, 1));
      gWrap.appendChild(grams);
      form.appendChild(gWrap);

      var mWrap = el('p');
      mWrap.appendChild(el('label', { for: mealId }, 'Meal'));
      var meal = el('select', { id: mealId });
      var hour = new Date().getHours();
      var guess = hour < 11 ? 'breakfast' : hour < 15 ? 'lunch' : hour < 21 ? 'dinner' : 'snacks';
      [['breakfast', 'Breakfast'], ['lunch', 'Lunch'], ['dinner', 'Dinner'], ['snacks', 'Snacks']].forEach(function (m) {
        var o = el('option', { value: m[0] }, m[1]);
        if (m[0] === guess) o.selected = true;
        meal.appendChild(o);
      });
      mWrap.appendChild(meal);
      form.appendChild(mWrap);

      // Nutrition for the chosen amount. Left out entirely where the safety matrix hides calories (FR3-69).
      var table = null;
      if (!hidden && !hideAll && !food.nutrition_hidden) {
        table = el('table', { class: 'lld-calc-table lld-scan-nutrition', 'data-lld-nutrition': '' });
        table.appendChild(el('caption', {}, 'Nutrition for the amount above'));
        table.appendChild(el('tbody'));
        form.appendChild(table);
      }
      var fill = function () {
        if (!table) return;
        var g = Number(grams.value) || 0;
        var f = g / (Number(food.basis_grams) || 100);
        var body = table.querySelector('tbody');
        body.textContent = '';
        [['kcal', 'Calories', ' kcal', 0], ['protein_g', 'Protein', ' g', 1], ['carb_g', 'Carbohydrate', ' g', 1], ['fat_g', 'Fat', ' g', 1], ['fibre_g', 'Fibre', ' g', 1]].forEach(function (n) {
          var v = food.per ? food.per[n[0]] : null;
          if (n[0] === 'protein_g' && (hideProtein || food.protein_hidden)) return; // no protein figure where the matrix hides it
          var tr = el('tr', { 'data-lld-nutrient': n[0] });
          tr.appendChild(el('th', { scope: 'row' }, n[1]));
          tr.appendChild(el('td', {}, v === null || v === undefined ? 'Not known' : round(v * f, n[3]) + n[2]));
          body.appendChild(tr);
        });
      };
      portion.addEventListener('change', function () {
        grams.value = portion.value;
        fill();
      });
      grams.addEventListener('input', fill);
      fill();

      if (food.source_name || food.attribution) nodes.push(el('p', { class: 'lld-hint lld-scan-source' }, [food.source_name, food.attribution].filter(Boolean).join(' · ')));
      var send = el('button', { type: 'submit', class: 'lld-button lld-button-primary', 'data-lld-log': '' }, 'Add to my food diary');
      form.appendChild(send);
      var done = el('p', { class: 'lld-scan-status', role: 'status', 'aria-live': 'polite', 'data-lld-log-status': '' });
      form.appendChild(done);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!consent) {
          say(done, words.consent);
          return;
        }
        var g = Number(grams.value);
        if (!(g > 0)) {
          say(done, 'Enter an amount in grams.');
          grams.focus();
          return;
        }
        send.disabled = true;
        call('POST', 'food-log', { entry_date: today, meal: meal.value, food_id: food.food_id, food_kind: food.food_kind, grams: g, source: food.from || 'barcode' }).then(function (res) {
          send.disabled = false;
          if (res.ok) {
            page.setAttribute('data-lld-logged', String(food.food_kind) + ':' + String(food.food_id));
            say(done, words.logged);
          } else {
            say(done, (res.data && res.data.message) || words.error);
          }
        }).catch(function () {
          send.disabled = false;
          say(done, words.error);
        });
      });
      nodes.push(form);
      return nodes;
    }

    function addProductCard(code, hidden) {
      var nodes = [];
      nodes.push(el('h2', {}, 'Product not found'));
      nodes.push(el('p', { 'data-lld-not-found': code }, words.not_found));
      var open = el('button', { type: 'button', class: 'lld-button lld-button-primary', 'data-lld-add-product': '' }, words.add);
      nodes.push(open);
      var form = el('form', { class: 'lld-scan-add', 'data-lld-add-form': '', hidden: '' });
      form.appendChild(el('p', { class: 'lld-hint' }, hidden ? 'Barcode ' + code + '. Add its name and the portion size from the pack.' : 'Barcode ' + code + '. Copy the details from the pack’s nutrition label, for the portion you enter.'));
      var fields = [['name', 'Product name', 'text', true], ['portion_g', 'Portion size in grams', 'number', true]];
      if (!hidden) fields = fields.concat([['kcal', 'Calories (kcal) in that portion', 'number'], ['protein_g', 'Protein (g)', 'number'], ['carb_g', 'Carbohydrate (g)', 'number'], ['fat_g', 'Fat (g)', 'number'], ['fibre_g', 'Fibre (g)', 'number']]);
      var inputs = {};
      fields.forEach(function (f) {
        var p = el('p');
        p.appendChild(el('label', { for: 'lld-add-' + f[0] }, f[1]));
        var attrs = { id: 'lld-add-' + f[0], name: f[0], type: f[2] };
        if (f[2] === 'number') {
          attrs.inputmode = 'decimal';
          attrs.min = '0';
          attrs.step = 'any';
        } else {
          attrs.maxlength = '255';
        }
        if (f[3]) attrs.required = '';
        inputs[f[0]] = el('input', attrs);
        p.appendChild(inputs[f[0]]);
        form.appendChild(p);
      });
      var save = el('button', { type: 'submit', class: 'lld-button lld-button-primary' }, 'Save this product');
      form.appendChild(save);
      var msg = el('p', { class: 'lld-scan-status', role: 'status', 'aria-live': 'polite' });
      form.appendChild(msg);
      open.addEventListener('click', function () {
        form.hidden = false;
        open.hidden = true;
        inputs.name.focus();
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = { barcode: code, name: inputs.name.value.trim(), portion_g: Number(inputs.portion_g.value) };
        if (!body.name || !(body.portion_g > 0)) {
          say(msg, 'Add the product name and its portion size in grams.');
          (body.name ? inputs.portion_g : inputs.name).focus();
          return;
        }
        ['kcal', 'protein_g', 'carb_g', 'fat_g', 'fibre_g'].forEach(function (k) {
          if (inputs[k] && inputs[k].value !== '') body[k] = Number(inputs[k].value);
        });
        save.disabled = true;
        call('POST', 'custom-foods', body).then(function (res) {
          save.disabled = false;
          if (!res.ok) {
            say(msg, (res.data && res.data.message) || words.error);
            return;
          }
          lookup(code); // the saved product is now found by its barcode
        }).catch(function () {
          save.disabled = false;
          say(msg, words.error);
        });
      });
      nodes.push(form);
      return nodes;
    }

    function lookup(code) {
      if (busy) {
        say(status, 'Still looking up the last barcode. Try again in a moment.');
        return;
      }
      busy = true;
      say(status, 'Looking up ' + code + '…');
      call('GET', 'food-barcode?code=' + encodeURIComponent(code)).then(function (res) {
        busy = false;
        var d = res.data || {};
        if (!res.ok) {
          say(status, d.message || words.error);
          if (d.upgrade && d.upgrade_url) show([el('p', {}, d.message), el('a', { class: 'lld-button lld-button-primary', href: d.upgrade_url }, 'See Premium')]);
          return;
        }
        say(status, '');
        page.setAttribute('data-lld-barcode', d.barcode || code);
        if (d.found && d.food) {
          d.food.protein_hidden = !!d.protein_hidden;
          show(productCard(d.food, d.note || '', hideAll || !!d.nutrition_hidden));
        }
        else show(addProductCard(d.barcode || code, hideAll || !!d.nutrition_hidden));
      }).catch(function () {
        busy = false;
        say(status, words.error);
      });
    }

    // ---- Typed barcode and typed search ---------------------------------------------------------------------------

    if (typedForm) {
      typedForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var code = typedInput.value.replace(/\D/g, '');
        if (!window.LLDBarcodeDecoder.checkOk(code) && !window.LLDBarcodeDecoder.upceToUpca(code)) {
          say(status, words.invalid);
          typedInput.focus();
          return;
        }
        stopCamera();
        lookup(code);
      });
    }

    // A search hit in the product shape: nutrition per its portion (shared foods) or per the member's own portion.
    function fromHit(hit) {
      var grams = hit.portion && hit.portion.grams ? Number(hit.portion.grams) : Number(hit.portion_g) || 100;
      var name = hit.portion && hit.portion.name ? hit.portion.name : round(grams, 1) + ' g';
      var portions = [{ name: name, grams: grams }];
      if (grams !== 100 && (hit.food_kind || 'food') === 'food') portions.push({ name: '100 g', grams: 100 });
      return {
        food_id: hit.id, food_kind: hit.food_kind || 'food', name: hit.name, brand: hit.brand || '', basis_grams: grams, default_grams: grams, portions: portions,
        per: { kcal: hit.kcal, protein_g: hit.protein_g, carb_g: hit.carb_g, fat_g: hit.fat_g, fibre_g: hit.fibre_g },
        nutrition_hidden: !!hit.nutrition_hidden, // the server's flag (safety matrix); a food with no kcal still shows the rest
        source_name: hit.source_name || '', attribution: hit.attribution || '', from: 'search'
      };
    }

    if (searchForm) {
      searchForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var q = searchForm.querySelector('input').value.trim();
        hitsList.textContent = '';
        if (q.length < 2) {
          say(searchStatus, 'Type at least two letters.');
          return;
        }
        say(searchStatus, 'Searching…');
        call('GET', 'food-search?q=' + encodeURIComponent(q)).then(function (res) {
          if (!res.ok) {
            say(searchStatus, (res.data && res.data.message) || words.error);
            return;
          }
          var foods = res.data && res.data.foods ? res.data.foods : [];
          say(searchStatus, foods.length ? foods.length + (foods.length === 1 ? ' food found.' : ' foods found.') : 'No foods found. Try a shorter or different name.');
          foods.forEach(function (hit) {
            var li = el('li');
            var b = el('button', { type: 'button', class: 'lld-text-button', 'data-lld-food-hit': (hit.food_kind || 'food') + ':' + hit.id }, hit.name + (hit.brand ? ', ' + hit.brand : ''));
            b.addEventListener('click', function () {
              show(productCard(fromHit(hit), '', false));
            });
            li.appendChild(b);
            hitsList.appendChild(li);
          });
        }).catch(function () {
          say(searchStatus, words.error);
        });
      });
    }

    if (startBtn) startBtn.addEventListener('click', startCamera);
    if (stopBtn) stopBtn.addEventListener('click', function () {
      stopCamera();
      say(status, '');
    });
    // The camera stops when the member leaves the page or switches app.
    window.addEventListener('pagehide', stopCamera);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden && stream) {
        stopCamera();
        say(status, '');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/**
 * Admin in My account (P3-87b · FR3-74, FR3-88): a switch saves as soon as it is flipped. The page comes back with
 * "Saved" and Undo. Without JavaScript each switch has its own Save button.
 */
(function () {
  'use strict';

  function initAdminToggles() {
    var toggles = document.querySelectorAll('[data-lld-admin] input[data-lld-adm-autosubmit]');
    Array.prototype.forEach.call(toggles, function (input) {
      input.addEventListener('change', function () {
        var form = input.form;
        if (!form) return;
        var state = input.parentNode.querySelector('.lld-adm-state');
        if (state) state.textContent = input.checked ? 'On' : 'Off';
        input.setAttribute('aria-busy', 'true');
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      });
    });
    var notice = document.querySelector('[data-lld-adm-notice]');
    if (notice) {
      notice.setAttribute('tabindex', '-1');
      notice.focus();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminToggles);
  } else {
    initAdminToggles();
  }
})();
