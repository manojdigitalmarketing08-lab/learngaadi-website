/*
 * Lead form loader + attribution tracker.
 * Usage: put <div data-lead-form data-service="Car Driving Classes"></div> anywhere
 * (data-service is optional and preselects the dropdown), then include this script.
 */
(function () {
  var STORE_KEY = 'lg_attribution';
  var TTL_MS = 30 * 24 * 60 * 60 * 1000; // keep the traffic source for 30 days
  var ENDPOINT = '/api/lead.php';
  var COMPONENT = '/components/lead-form.html';
  var THANKYOU = '/thank-you';

  /* ---------- storage (safe if blocked) ---------- */
  function readStore() {
    try {
      var d = JSON.parse(localStorage.getItem(STORE_KEY));
      if (d && Date.now() - d.ts < TTL_MS) return d;
    } catch (e) {}
    return null;
  }
  function writeStore(d) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(d)); } catch (e) {}
  }

  /* ---------- source detection ---------- */
  function normalize(name) {
    name = (name || '').toLowerCase().trim();
    if (/^(ig|insta|instagram)/.test(name)) return 'instagram';
    if (/^(fb|facebook|meta)/.test(name)) return 'facebook';
    if (/^google/.test(name)) return 'google';
    return name;
  }

  function fromReferrer(ref) {
    if (!ref) return null;
    var host;
    try { host = new URL(ref).hostname.toLowerCase().replace(/^www\./, ''); } catch (e) { return null; }
    if (host === location.hostname.replace(/^www\./, '')) return null; // internal navigation
    if (/(^|\.)instagram\.com$/.test(host)) return { source: 'instagram', medium: 'social' };
    if (/(^|\.)(facebook\.com|fb\.com|fb\.me)$/.test(host)) return { source: 'facebook', medium: 'social' };
    if (/(^|\.)google\.[a-z.]+$/.test(host)) return { source: 'google', medium: 'organic' };
    return { source: host, medium: 'referral' };
  }

  // Works out where THIS visit came from; returns null if there is no signal (direct / internal).
  function detectCurrentVisit() {
    var q = new URLSearchParams(location.search);
    var utmSource = q.get('utm_source');
    var out = null;

    if (utmSource) {
      out = { source: normalize(utmSource), medium: q.get('utm_medium') || 'campaign' };
    } else if (q.get('gclid') || q.get('gbraid') || q.get('wbraid')) {
      out = { source: 'google', medium: 'cpc' };
    } else if (q.get('fbclid')) {
      out = { source: 'facebook', medium: 'paid_social' };
    } else if (q.get('igshid')) {
      out = { source: 'instagram', medium: 'social' };
    } else {
      out = fromReferrer(document.referrer);
    }

    // Instagram / Facebook in-app browsers often send no referrer at all.
    if (!out) {
      var ua = navigator.userAgent || '';
      if (/Instagram/i.test(ua)) out = { source: 'instagram', medium: 'social' };
      else if (/FBAN|FBAV/i.test(ua)) out = { source: 'facebook', medium: 'social' };
    }

    if (out) {
      out.campaign = q.get('utm_campaign') || '';
      out.click_id = q.get('gclid') || q.get('fbclid') || '';
      out.referrer = document.referrer || '';
    }
    return out;
  }

  // Latest real traffic source wins; plain direct visits never overwrite it.
  function getAttribution() {
    var stored = readStore();
    var current = detectCurrentVisit();

    if (current) {
      current.landing_page = location.pathname + location.search;
      current.ts = Date.now();
      writeStore(current);
      return current;
    }
    if (stored) return stored;

    var direct = {
      source: 'direct', medium: 'none', campaign: '', click_id: '', referrer: '',
      landing_page: location.pathname + location.search, ts: Date.now()
    };
    writeStore(direct);
    return direct;
  }

  // Record the visit even on pages with no form, so a later form on another page keeps the real source.
  var attribution = getAttribution();

  /* ---------- form wiring ---------- */
  function setField(form, name, value) {
    var el = form.elements[name];
    if (el) el.value = value || '';
  }

  function fillTracking(form) {
    setField(form, 'page_url', location.href);
    setField(form, 'page_path', location.pathname);
    setField(form, 'page_title', document.title);
    setField(form, 'source', attribution.source);
    setField(form, 'medium', attribution.medium);
    setField(form, 'campaign', attribution.campaign);
    setField(form, 'referrer', attribution.referrer);
    setField(form, 'landing_page', attribution.landing_page);
    setField(form, 'click_id', attribution.click_id);
  }

  function showMessage(form, text, isError) {
    var msg = form.querySelector('.lead-form__msg');
    msg.textContent = text;
    msg.className = 'lead-form__msg ' + (isError ? 'is-error' : 'is-ok');
  }

  /* ---------- custom dropdown ----------
   * Replaces the browser's own <select> list (which CSS cannot style) with a designed one.
   * The real <select> stays in the form (hidden), so submission and tracking are unchanged. */
  function enhanceSelect(select) {
    var field = select.closest('.lead-form__field');
    var label = field.querySelector('label');
    var wrap = document.createElement('div');
    wrap.className = 'lf-select';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lf-select__btn';
    btn.id = select.id + '-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (label) btn.setAttribute('aria-labelledby', label.id || (label.id = select.id + '-label'));

    var list = document.createElement('ul');
    list.className = 'lf-select__list';
    list.setAttribute('role', 'listbox');
    list.tabIndex = -1;

    var items = [];
    Array.prototype.forEach.call(select.options, function (opt, i) {
      if (opt.value === '') return;                     // the placeholder is not a choice
      var li = document.createElement('li');
      li.className = 'lf-select__opt';
      li.setAttribute('role', 'option');
      li.dataset.value = opt.value || opt.text;
      li.dataset.index = i;
      li.textContent = opt.text;
      list.appendChild(li);
      items.push(li);
    });

    wrap.appendChild(btn);
    wrap.appendChild(list);
    select.classList.add('lf-native');
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');
    select.parentNode.insertBefore(wrap, select.nextSibling);

    var active = -1;

    function refresh() {
      var chosen = select.value;
      var placeholder = select.options[0] ? select.options[0].text : 'Select';
      btn.textContent = chosen || placeholder;
      btn.classList.toggle('is-placeholder', !chosen);
      items.forEach(function (li) {
        var on = li.dataset.value === chosen;
        li.classList.toggle('is-selected', on);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }

    function highlight(i) {
      active = Math.max(0, Math.min(items.length - 1, i));
      items.forEach(function (li, k) { li.classList.toggle('is-active', k === active); });
      var el = items[active];
      if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
    }

    // open downwards, or upwards when the nearest clipping ancestor / the window would cut the list off
    function place() {
      wrap.classList.remove('is-up');
      var r = btn.getBoundingClientRect();
      var h = list.offsetHeight + 8;
      var limit = window.innerHeight;
      for (var p = wrap.parentElement; p && p !== document.body; p = p.parentElement) {
        var ov = getComputedStyle(p).overflow;
        if (ov !== 'visible') { limit = Math.min(limit, p.getBoundingClientRect().bottom); break; }
      }
      if (r.bottom + h > limit && r.top - h > 0) wrap.classList.add('is-up');
    }

    function open() {
      wrap.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      place();
      var sel = items.map(function (li) { return li.dataset.value; }).indexOf(select.value);
      highlight(sel < 0 ? 0 : sel);
    }
    function close() {
      wrap.classList.remove('is-open', 'is-up');
      btn.setAttribute('aria-expanded', 'false');
    }
    function choose(i) {
      select.value = items[i].dataset.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      refresh();
      close();
      btn.focus();
    }

    btn.addEventListener('click', function () { wrap.classList.contains('is-open') ? close() : open(); });
    btn.addEventListener('keydown', function (e) {
      var isOpen = wrap.classList.contains('is-open');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen) open(); else highlight(active + (e.key === 'ArrowDown' ? 1 : -1));
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (!isOpen) open(); else choose(active);
      } else if (e.key === 'Escape' || e.key === 'Tab') {
        close();
      }
    });
    list.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep focus on the button
    list.addEventListener('click', function (e) {
      var li = e.target.closest('.lf-select__opt');
      if (li) choose(items.indexOf(li));
    });
    list.addEventListener('mousemove', function (e) {
      var li = e.target.closest('.lf-select__opt');
      if (li) highlight(items.indexOf(li));
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    if (label) label.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); });
    select.form.addEventListener('reset', function () { setTimeout(refresh, 0); });

    refresh();
  }

  /* ---------- phone validation ---------- */
  // Reduce whatever was typed or pasted (+91 98765 43210, 098765-43210, ...) to the bare 10 digits.
  function normalizePhone(raw) {
    var text = String(raw || '');
    var d = text.replace(/\D/g, '');
    if (/^\s*\+/.test(text) && /^91/.test(d)) d = d.slice(2);   // "+91 ..." -> drop the country code at once
    d = d.replace(/^0+/, '');                                    // trunk prefix: 098765... -> 98765...
    // A bare "91" may be the start of a real number (91xxxxxxxx), so keep it until the 12th digit proves it is a country code.
    if (/^91/.test(d)) {
      d = d.slice(0, 12);
      if (d.length === 12) d = d.slice(2);
    } else {
      d = d.slice(0, 10);
    }
    return d;
  }

  // Returns an error message, or '' when the number is acceptable.
  function phoneError(d) {
    if (d.length === 0) return 'Please enter your mobile number.';
    if (!/^[6-9]/.test(d)) return 'Indian mobile numbers start with 6, 7, 8 or 9.';
    if (d.length < 10) return 'Enter all 10 digits (' + d.length + ' entered).';
    if (d.length > 10) return 'Enter only the 10-digit number, without 91 in front.';
    if (/^(\d)\1{9}$/.test(d) || d === '9876543210' || d === '1234567890') return 'Please enter your real mobile number.';
    return '';
  }

  function setFieldError(input, text) {
    var field = input.closest('.lead-form__field');
    var err = field.querySelector('.lead-form__err');
    if (!err) {
      err = document.createElement('p');
      err.className = 'lead-form__err';
      err.id = input.id + '-err';
      err.setAttribute('role', 'alert');
      field.appendChild(err);
    }
    err.textContent = text;
    input.classList.toggle('is-invalid', !!text);
    if (text) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', err.id); }
    else { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); }
  }

  function bind(form) {
    var btn = form.querySelector('.lead-form__btn');
    var btnText = btn.textContent;
    var phoneInput = form.elements.phone;
    var touched = false;

    // keep only digits while typing / pasting; show the problem once the visitor has left the field
    phoneInput.addEventListener('input', function () {
      var clean = normalizePhone(phoneInput.value);
      if (phoneInput.value !== clean) phoneInput.value = clean;
      if (touched || clean.length === 10) setFieldError(phoneInput, phoneError(clean));
      else setFieldError(phoneInput, '');
    });
    phoneInput.addEventListener('blur', function () {
      touched = true;
      if (phoneInput.value !== '') setFieldError(phoneInput, phoneError(phoneInput.value));
    });
    form.addEventListener('reset', function () { touched = false; setFieldError(phoneInput, ''); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var name = form.elements.name.value.trim();
      var phone = normalizePhone(phoneInput.value);
      if (name.length < 2) { form.elements.name.focus(); return showMessage(form, 'Please enter your name.', true); }
      var perr = phoneError(phone);
      if (perr) { touched = true; setFieldError(phoneInput, perr); phoneInput.focus(); return showMessage(form, '', false); }
      setFieldError(phoneInput, '');

      form.elements.phone.value = phone;
      fillTracking(form);
      btn.disabled = true;
      btn.textContent = 'Sending...';
      showMessage(form, '', false);

      fetch(ENDPOINT, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (!res.ok || !res.body.ok) throw new Error(res.body.error || 'Something went wrong.');
          // Saved in the database (the server then sends the lead to WhatsApp itself).
          // The thank-you page records the generate_lead event, so a refresh or direct visit is not counted.
          try {
            sessionStorage.setItem('lg_thanks', JSON.stringify({
              source: attribution.source,
              page: location.pathname
            }));
          } catch (e) {}
          form.reset();
          location.href = THANKYOU;
        })
        .catch(function (err) {
          showMessage(form, err.message || 'Could not send. Please call +91 8296161555.', true);
        })
        .then(function () {
          btn.disabled = false;
          btn.textContent = btnText;
        });
    });
  }

  function mount(host) {
    fetch(COMPONENT)
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        host.innerHTML = html;
        var form = host.querySelector('form');
        var service = host.getAttribute('data-service');
        if (service) setField(form, 'service', service);
        fillTracking(form);
        var sel = form.querySelector('select');
        if (sel) enhanceSelect(sel);
        bind(form);
      })
      .catch(function () { console.error('Failed to load ' + COMPONENT); });
  }

  function init() {
    var hosts = document.querySelectorAll('[data-lead-form]');
    for (var i = 0; i < hosts.length; i++) mount(hosts[i]);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
