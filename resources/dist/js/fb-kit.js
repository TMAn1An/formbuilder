/* =========================================================================
   Builder kit -- shared by the Form Builder and the Page Builder.
   - DOM helper h() (text only via textContent; innerHTML only for
     server-sanitized HTML via sanitizedHtml())
   - settings-panel controls (text, textarea, select, checkbox, color, px,
     length, and a generic styleCtl driven by a server style definition)
   - style math that MIRRORS TMAn1An\FormBuilder\Styling\StyleProperties:
     color | px | enum (with "@other_key" length references) | length
   - JSON API helper with CSRF
   Plain JS, no build step. Exposed as window.FBKit.
   ========================================================================= */
(function (global) {
  'use strict';

  /* ------------------------------------------------------------------ DOM */

  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'style') el.style.cssText = v;
        else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
        else if (v === true) el.setAttribute(k, '');
        else el.setAttribute(k, v);
      });
    }
    for (var i = 2; i < arguments.length; i++) append(el, arguments[i]);
    return el;
  }

  function append(el, child) {
    if (child === null || child === undefined || child === false) return;
    if (Array.isArray(child)) { child.forEach(function (c) { append(el, c); }); return; }
    el.append(child instanceof Node ? child : String(child));
  }

  /** ONLY for HTML the server already sanitized (HtmlSanitizer) -- never raw admin input. */
  function sanitizedHtml(className, html) {
    var div = h('div', { class: className });
    div.innerHTML = html;
    return div;
  }

  /* ---------------------------------------------------------- style math */

  var COLOR = /^#[0-9a-fA-F]{6}$/;
  var LENGTH = /^(\d{1,4}(?:\.\d{1,2})?)(px|%|rem)$/;

  function cleanLength(def, value) {
    if (typeof value !== 'string') return null;
    var m = value.trim().match(LENGTH);
    if (!m) return null;
    var max = (def.max || {})[m[2]];
    if (max === undefined || parseFloat(m[1]) > max) return null;
    return String(parseFloat(m[1])) + m[2];
  }

  function isValidStyle(def, v) {
    if (v === null || v === undefined || v === '') return false;
    if (def.type === 'color') return typeof v === 'string' && COLOR.test(v);
    if (def.type === 'px') { var n = Number(v); return Number.isInteger(n) && n >= def.min && n <= def.max; }
    if (def.type === 'length') return cleanLength(def, v) !== null;
    return Object.prototype.hasOwnProperty.call(def.options, String(v));
  }

  /** CSS value for a validated property; `siblings` = other values in the same group (for "@ref" enums). */
  function cssValue(def, value, siblings) {
    if (def.type === 'px') return value + 'px';
    if (def.type === 'length') return cleanLength(def, value);
    if (def.type === 'enum') {
      var css = (def.options[value] || def.options[def.default]).css;
      if (css.charAt(0) !== '@') return css;
      var ref = (siblings || {})[css.slice(1)];
      return ref ? 'min(' + ref + ', 100%)' : 'auto';
    }
    return value;
  }

  /** Values of a property map with invalid/missing ones replaced by defaults. */
  function normalizeGroup(props, values) {
    var out = {};
    Object.keys(props).forEach(function (key) {
      var def = props[key];
      var v = (values || {})[key];
      out[key] = isValidStyle(def, v) ? (def.type === 'length' ? cleanLength(def, v) : v) : def.default;
    });
    return out;
  }

  /** `--x:..;--y:..` for one property map (e.g. ButtonStyleSchema::properties()), defaults applied. */
  function groupDeclarations(props, values) {
    var normalized = normalizeGroup(props, values);
    return Object.keys(props).map(function (key) {
      return props[key].var + ':' + cssValue(props[key], normalized[key], normalized);
    }).join(';');
  }

  /** Grouped schema ({group: {label, properties}}), e.g. FormStyleSchema / PageStyleSchema. */
  function schemaDeclarations(schema, values) {
    return Object.keys(schema).map(function (group) {
      return groupDeclarations(schema[group].properties, (values || {})[group]);
    }).join(';');
  }

  /** Flat optional overrides (FieldStyleSchema): only valid values, no defaults. */
  function overrideDeclarations(props, values) {
    var out = [];
    Object.keys(props).forEach(function (key) {
      var v = (values || {})[key];
      if (isValidStyle(props[key], v)) out.push(props[key].var + ':' + cssValue(props[key], v, values));
    });
    return out.join(';');
  }

  /* ------------------------------------------------------------- controls */

  var ctlSeq = 0;
  function ctlId() { return 'fbc-' + (++ctlSeq); }

  function control(label, inputEl, opts) {
    opts = opts || {};
    return h('div', { class: 'fb-control' },
      label ? h('label', { for: inputEl.id || null, text: label }) : null,
      inputEl,
      opts.help ? h('p', { class: 'help', text: opts.help }) : null,
      opts.error ? h('p', { class: 'error', text: opts.error }) : null);
  }

  function textCtl(label, value, onInput, opts) {
    opts = opts || {};
    var el = h('input', { type: opts.type || 'text', id: ctlId(), value: value === undefined || value === null ? '' : String(value),
      placeholder: opts.placeholder || null, maxlength: opts.maxlength || null, disabled: !!opts.disabled,
      min: opts.min !== undefined ? String(opts.min) : null, max: opts.max !== undefined ? String(opts.max) : null, step: opts.step || null,
      oninput: function () { onInput(el.value); }, onchange: opts.onchange ? function () { opts.onchange(el); } : null });
    return control(label, el, opts);
  }

  function textareaCtl(label, value, onInput, opts) {
    opts = opts || {};
    var el = h('textarea', { id: ctlId(), rows: String(opts.rows || 3), class: opts.code ? 'is-code' : null, spellcheck: opts.code ? 'false' : null,
      maxlength: opts.maxlength || null, placeholder: opts.placeholder || null, text: value || '', oninput: function () { onInput(el.value); } });
    return control(label, el, opts);
  }

  function selectCtl(label, value, options, onChange, opts) {
    opts = opts || {};
    var el = h('select', { id: ctlId(), disabled: !!opts.disabled, onchange: function () { onChange(el.value); } },
      options.map(function (o) { return h('option', { value: o[0], selected: String(value) === String(o[0]), text: o[1] }); }));
    return control(label, el, opts);
  }

  function checkCtl(label, checked, onChange, opts) {
    opts = opts || {};
    var el = h('input', { type: 'checkbox', checked: !!checked, disabled: !!opts.disabled, onchange: function () { onChange(el.checked); } });
    return h('div', { class: 'fb-control fb-control--check' },
      h('label', null, el, label),
      opts.help ? h('p', { class: 'help', text: opts.help }) : null,
      opts.error ? h('p', { class: 'error', text: opts.error }) : null);
  }

  function colorCtl(label, value, onChange, opts) {
    opts = opts || {};
    var picker = h('input', { type: 'color', value: COLOR.test(value || '') ? value : (opts.fallback || '#000000'), 'aria-label': label });
    var text = h('input', { type: 'text', id: ctlId(), value: value || '', maxlength: '7', placeholder: opts.allowEmpty ? 'inherit' : '#rrggbb' });
    picker.addEventListener('input', function () { text.value = picker.value; onChange(picker.value); });
    text.addEventListener('input', function () {
      var v = text.value.trim();
      if (COLOR.test(v)) { picker.value = v; onChange(v.toLowerCase()); } else if (v === '' && opts.allowEmpty) { onChange(null); }
    });
    var row = h('div', { class: 'fb-color' }, picker, text,
      opts.allowEmpty ? h('button', { type: 'button', class: 'fb-link-btn', text: 'inherit', title: 'Use the inherited value',
        onclick: function () { text.value = ''; onChange(null); } }) : null);
    return h('div', { class: 'fb-control' }, h('label', { for: text.id, text: label }), row,
      opts.error ? h('p', { class: 'error', text: opts.error }) : null);
  }

  /** Integer px input: live-updates while in range, clamps on change. */
  function pxCtl(label, value, def, onChange, opts) {
    opts = opts || {};
    return textCtl(label + ' (' + def.min + '–' + def.max + 'px)', value, function (v) {
      if (v === '') { if (opts.allowEmpty) onChange(null); return; }
      var n = Number(v);
      if (Number.isInteger(n) && n >= def.min && n <= def.max) onChange(n);
    }, {
      type: 'number', min: def.min, max: def.max, step: '1', placeholder: opts.allowEmpty ? 'inherit' : null, error: opts.error, help: opts.help,
      onchange: function (el) {
        if (el.value === '') return;
        var n = Math.round(Number(el.value));
        if (!Number.isFinite(n)) return;
        n = Math.min(def.max, Math.max(def.min, n));
        el.value = String(n);
        onChange(n);
      },
    });
  }

  /** CSS length (px / % / rem) input; only valid values are applied, invalid ones are flagged. */
  function lengthCtl(label, value, def, onChange, opts) {
    opts = opts || {};
    var units = Object.keys(def.max || {}).join(', ');
    var wrap = textCtl(label, value, function (v) {
      var clean = cleanLength(def, v);
      input.classList.toggle('is-invalid', v !== '' && clean === null);
      if (clean !== null) onChange(clean); else if (v === '' && opts.allowEmpty) onChange(null);
    }, { placeholder: opts.placeholder || 'e.g. 220px, 50%, 12rem', maxlength: 12, error: opts.error,
      help: opts.help || ('Units: ' + units + '. Max ' + Object.keys(def.max || {}).map(function (u) { return def.max[u] + u; }).join(' / ') + '.') });
    var input = wrap.querySelector('input');
    return wrap;
  }

  /** Control for any server style definition (color | px | enum | length). */
  function styleCtl(def, value, onChange, opts) {
    opts = opts || {};
    if (def.type === 'color') return colorCtl(def.label, value, onChange, opts);
    if (def.type === 'px') return pxCtl(def.label, value, def, onChange, opts);
    if (def.type === 'length') return lengthCtl(def.label, value, def, onChange, opts);
    return selectCtl(def.label, value, Object.keys(def.options).map(function (k) { return [k, def.options[k].label]; }), onChange, opts);
  }

  /* ------------------------------------------------------------------ API */

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function api(method, url, body) {
    var isForm = typeof FormData !== 'undefined' && body instanceof FormData;
    var headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' };
    if (!isForm) headers['Content-Type'] = 'application/json';
    return fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: headers,
      body: isForm ? body : JSON.stringify(body),
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
    });
  }

  function now() { return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }

  global.FBKit = {
    h: h, append: append, sanitizedHtml: sanitizedHtml,
    cleanLength: cleanLength, isValidStyle: isValidStyle, cssValue: cssValue, normalizeGroup: normalizeGroup,
    groupDeclarations: groupDeclarations, schemaDeclarations: schemaDeclarations, overrideDeclarations: overrideDeclarations,
    control: control, textCtl: textCtl, textareaCtl: textareaCtl, selectCtl: selectCtl, checkCtl: checkCtl,
    colorCtl: colorCtl, pxCtl: pxCtl, lengthCtl: lengthCtl, styleCtl: styleCtl,
    api: api, now: now,
  };
})(window);
