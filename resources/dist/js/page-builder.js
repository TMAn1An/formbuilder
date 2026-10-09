/* =========================================================================
   Page Builder -- admin UI. Plain JS, no build step; shares fb-kit.js with
   the Form Builder.

   State is a FLAT, ordered list of blocks in exactly the shape the server
   validates (PageDefinitionValidator): {ref, id, parent, column, type,
   settings}. Array order = sibling order. Containers (section, columns)
   hold one level of children (parent ref + column). The canvas mirrors
   resources/views/pages/_block.blade.php (same classes, same inline-style
   rules as PagePresenter); embedded forms are previewed with server-rendered
   markup from the real form renderer. Nothing here is a security boundary:
   the server re-validates the whole definition on every save.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.getElementById('page-builder');
  var dataEl = document.getElementById('page-builder-data');
  if (!root || !dataEl || !window.FBKit) return;

  var K = window.FBKit;
  var h = K.h;
  var boot = JSON.parse(dataEl.textContent);
  var META = boot.meta;
  var TYPES = META.types;
  var URLS = boot.urls;
  var PERMS = boot.permissions;
  var canEdit = !!PERMS.canEdit;

  var els = {
    canvas: document.getElementById('pb-canvas'),
    palette: document.getElementById('pb-palette'),
    panel: document.getElementById('pb-panel'),
    tabs: document.getElementById('pb-tabs'),
    errors: document.getElementById('pb-errors'),
    saveState: document.getElementById('pb-save-state'),
    statusBadge: document.getElementById('pb-status-badge'),
    save: document.getElementById('pb-save'),
    publish: document.getElementById('pb-publish'),
  };

  var PALETTE = [
    ['Layout', ['section', 'columns', 'spacer', 'divider']],
    ['Content', ['heading', 'paragraph', 'image', 'button']],
    ['Embed', ['form', 'html']],
  ];
  var ICONS = { section: '▭', columns: 'Ⅱ', spacer: '↕', divider: '—', heading: 'H', paragraph: '¶', image: 'IMG', button: '▶', form: '☑', html: '</>' };
  var TABS = [['block', 'Block'], ['design', 'Page design'], ['settings', 'Page settings'], ['code', 'Custom CSS']];
  var ALIGNS = [['left', 'Left'], ['center', 'Center'], ['right', 'Right']];
  var WEIGHTS = [['', 'Default'], ['400', 'Regular'], ['500', 'Medium'], ['600', 'Semi-bold'], ['700', 'Bold'], ['800', 'Extra-bold']];
  var IMAGE_LENGTH = { type: 'length', max: META.imageLengthMax };

  var refSeq = 0;
  var sanitizeTimers = {};
  var formHtml = {};       // form id -> server-rendered preview markup
  var formLoading = {};

  var state = {
    page: boot.page,
    blocks: [],
    rendered: { css: boot.rendered.css || '', html: {} },
    media: boot.media || [],
    forms: boot.forms || [],
    selected: null,
    targetColumn: 0,
    tab: 'block',
    version: boot.page.version,
    changeSeq: 0,
    savedSeq: 0,
    saving: false,
    pendingIntent: null,
    errors: {},
    blockErrors: {},
    device: 'desktop',
    autosaveTimer: null,
  };

  function plain(o) { return (o && typeof o === 'object' && !Array.isArray(o)) ? JSON.parse(JSON.stringify(o)) : {}; }

  state.page.settings = plain(state.page.settings);
  state.page.style_settings = plain(state.page.style_settings);
  state.blocks = (boot.blocks || []).map(function (b) {
    return { ref: b.ref, id: b.id, parent: b.parent, column: b.column || 0, type: b.type, settings: plain(b.settings) };
  });
  Object.keys(boot.rendered.html || {}).forEach(function (ref) { state.rendered.html[ref] = boot.rendered.html[ref]; });

  /* ================================================================ model */

  function byRef(ref) { return state.blocks.find(function (b) { return b.ref === ref; }) || null; }
  function isContainer(b) { return !!(b && TYPES[b.type] && TYPES[b.type].isContainer); }
  function roots() { return state.blocks.filter(function (b) { return !b.parent; }); }
  function childrenOf(ref, column) {
    return state.blocks.filter(function (b) { return b.parent === ref && (column === undefined || (b.column || 0) === column); });
  }
  function siblingsOf(b) { return b.parent ? childrenOf(b.parent, b.column || 0) : roots(); }
  function slotCount(b) { return b.type === 'columns' ? (Number(b.settings.count) || 2) : (b.type === 'section' ? 1 : 0); }
  function isDirty() { return state.changeSeq !== state.savedSeq; }
  function newRef() { var r; do { r = 'n' + (++refSeq); } while (byRef(r)); return r; }
  function mediaById(id) { return state.media.find(function (m) { return m.id === Number(id); }) || null; }
  function formById(id) { return state.forms.find(function (f) { return f.id === Number(id); }) || null; }
  function err(path) { return state.errors[path] ? state.errors[path][0] : null; }

  function defaultSettings(type) {
    switch (type) {
      case 'columns': return { count: 2, gap: 24 };
      case 'section': return { padding_y: 32, padding_x: 24 };
      case 'heading': return { text: 'New heading', level: 'h2' };
      case 'paragraph': return { content: '<p>Write your text here.</p>' };
      case 'button': return { text: 'Learn more', url: '/', style: {} };
      case 'spacer': return { height: 32 };
      case 'divider': return { thickness: 1 };
      case 'html': return { content: '<p><strong>Custom HTML</strong> block.</p>' };
      default: return {};
    }
  }

  /** Index after which to insert a new top-level block (after the selection's top-level block and its children). */
  function rootInsertIndex(rootBlock) {
    if (!rootBlock) return state.blocks.length;
    var last = state.blocks.indexOf(rootBlock);
    state.blocks.forEach(function (b, i) { if (b.parent === rootBlock.ref && i > last) last = i; });
    return last + 1;
  }

  function addBlock(type) {
    if (!canEdit) return;
    var block = { ref: newRef(), id: null, parent: null, column: 0, type: type, settings: defaultSettings(type) };
    var sel = byRef(state.selected);
    var selRoot = sel ? (sel.parent ? byRef(sel.parent) : sel) : null;

    if (isContainer(block)) {
      state.blocks.splice(rootInsertIndex(selRoot), 0, block);              // containers are always top-level
    } else if (sel && isContainer(sel)) {
      block.parent = sel.ref;                                              // into the selected container
      block.column = Math.min(state.targetColumn || 0, slotCount(sel) - 1);
      var col = childrenOf(sel.ref, block.column);
      state.blocks.splice(col.length ? state.blocks.indexOf(col[col.length - 1]) + 1 : state.blocks.indexOf(sel) + 1, 0, block);
    } else if (sel && sel.parent) {
      block.parent = sel.parent;                                           // next to the selected child
      block.column = sel.column || 0;
      state.blocks.splice(state.blocks.indexOf(sel) + 1, 0, block);
    } else {
      state.blocks.splice(rootInsertIndex(selRoot), 0, block);
    }

    state.selected = block.ref;
    state.tab = 'block';
    if (type === 'paragraph' || type === 'html') scheduleSanitizeBlock(block);
    changed({ panel: true, tabs: true });
  }

  function moveBlock(ref, dir) {
    var b = byRef(ref);
    if (!b) return;
    var sibs = siblingsOf(b);
    var target = sibs[sibs.indexOf(b) + dir];
    if (!target) return;
    var i = state.blocks.indexOf(b);
    var j = state.blocks.indexOf(target);
    state.blocks[i] = target;
    state.blocks[j] = b;
    changed({ panel: true });
  }

  function moveColumn(ref, dir) {
    var b = byRef(ref);
    var parent = b && byRef(b.parent);
    if (!parent) return;
    var next = (b.column || 0) + dir;
    if (next < 0 || next >= slotCount(parent)) return;
    b.column = next;
    state.blocks.splice(state.blocks.indexOf(b), 1);
    var col = childrenOf(parent.ref, next);
    state.blocks.splice(col.length ? state.blocks.indexOf(col[col.length - 1]) + 1 : state.blocks.indexOf(parent) + 1, 0, b);
    changed({ panel: true });
  }

  function moveOut(ref) {
    var b = byRef(ref);
    var parent = b && byRef(b.parent);
    if (!parent) return;
    state.blocks.splice(state.blocks.indexOf(b), 1);
    b.parent = null;
    b.column = 0;
    state.blocks.splice(rootInsertIndex(parent), 0, b);
    changed({ panel: true });
  }

  function duplicateBlock(ref) {
    var b = byRef(ref);
    if (!b) return;
    var copy = { ref: newRef(), id: null, parent: b.parent, column: b.column, type: b.type, settings: JSON.parse(JSON.stringify(b.settings)) };
    var copies = [copy];
    if (isContainer(b)) {
      childrenOf(b.ref).forEach(function (c) {
        copies.push({ ref: newRef(), id: null, parent: copy.ref, column: c.column, type: c.type, settings: JSON.parse(JSON.stringify(c.settings)) });
        if (state.rendered.html[c.ref] !== undefined) state.rendered.html[copies[copies.length - 1].ref] = state.rendered.html[c.ref];
      });
    }
    if (state.rendered.html[b.ref] !== undefined) state.rendered.html[copy.ref] = state.rendered.html[b.ref];
    var at = b.parent ? state.blocks.indexOf(b) + 1 : rootInsertIndex(b);
    Array.prototype.splice.apply(state.blocks, [at, 0].concat(copies));
    state.selected = copy.ref;
    changed({ panel: true });
  }

  function removeBlock(ref) {
    var b = byRef(ref);
    if (!b) return;
    var kids = childrenOf(b.ref);
    var msg = 'Remove this ' + TYPES[b.type].label.toLowerCase() + ' block?' + (kids.length ? '\n\nThe ' + kids.length + ' block(s) inside it will be removed too.' : '')
      + (b.type === 'form' ? '\n\nThe form itself and its submissions are NOT affected.' : '');
    if (!window.confirm(msg)) return;
    state.blocks = state.blocks.filter(function (x) { return x !== b && x.parent !== b.ref; });
    if (state.selected === ref || (byRef(state.selected) || {}).parent === ref) state.selected = null;
    changed({ panel: true });
  }

  function setSetting(b, key, value, opts) {
    if (value === '' || value === null || value === undefined || value === false) delete b.settings[key];
    else b.settings[key] = value;
    if (key === 'count') {
      // Children of removed columns move into the last remaining column.
      var max = slotCount(b) - 1;
      childrenOf(b.ref).forEach(function (c) { if ((c.column || 0) > max) c.column = max; });
    }
    changed(opts || {});
  }

  function setButtonStyle(b, key, value) {
    b.settings.style = b.settings.style || {};
    if (value === null || value === '') delete b.settings.style[key]; else b.settings.style[key] = value;
    changed({ panel: key === 'width' });
  }

  function select(ref, column) {
    state.selected = ref;
    if (column !== undefined) state.targetColumn = column;
    state.tab = 'block';
    els.canvas.querySelectorAll('.pbb-item').forEach(function (el) { el.classList.toggle('is-selected', el.dataset.ref === ref); });
    renderTabs();
    renderPanel();
  }

  function changed(opts) {
    opts = opts || {};
    state.changeSeq++;
    setSaveState('Unsaved changes', 'dirty');
    if (opts.tabs) renderTabs();
    if (opts.panel) renderPanel();
    renderCanvas();
    scheduleAutosave();
  }

  /* ============================================= block styles (mirror PagePresenter) */

  var COLOR = /^#[0-9a-fA-F]{6}$/;
  function css(map) {
    return Object.keys(map).filter(function (k) { return map[k] !== null && map[k] !== undefined && map[k] !== ''; })
      .map(function (k) { return k + ':' + map[k]; }).join(';');
  }
  function color(v) { return typeof v === 'string' && COLOR.test(v) ? v.toLowerCase() : null; }
  function px(v, min, max) { return v === undefined || v === null || v === '' || isNaN(Number(v)) ? null : Math.max(min, Math.min(max, parseInt(v, 10))) + 'px'; }
  function align(v) { return ['left', 'center', 'right'].indexOf(v) !== -1 ? v : null; }
  function len(v) { return K.cleanLength(IMAGE_LENGTH, v); }

  function blockStyle(b) {
    var s = b.settings;
    switch (b.type) {
      case 'section':
        return css({ 'background-color': color(s.background_color), color: color(s.text_color),
          padding: (px(s.padding_y === undefined ? 32 : s.padding_y, 0, 160)) + ' ' + (px(s.padding_x === undefined ? 24 : s.padding_x, 0, 120)),
          'border-radius': px(s.radius, 0, 200) });
      case 'columns':
        return css({ '--pb-cols': String(slotCount(b)), '--pb-col-gap': px(s.gap === undefined ? 24 : s.gap, 0, 80),
          'align-items': ['start', 'center', 'end'].indexOf(s.vertical_align) !== -1 ? s.vertical_align : null });
      case 'heading':
        return css({ 'text-align': align(s.align), 'font-size': px(s.font_size, 10, 120),
          'font-weight': ['400', '500', '600', '700', '800'].indexOf(String(s.font_weight || '')) !== -1 ? String(s.font_weight) : null,
          color: color(s.color), 'margin-top': px(s.margin_top, 0, 160), 'margin-bottom': px(s.margin_bottom, 0, 160) });
      case 'paragraph':
      case 'html':
        return css({ 'text-align': align(s.align), 'font-size': px(s.font_size, 10, 120), color: color(s.color) });
      case 'image':
        return css({ 'justify-content': { left: 'flex-start', center: 'center', right: 'flex-end' }[s.align || 'center'] });
      case 'button':
        return K.groupDeclarations(META.buttonStyle, s.style);
      case 'divider':
        return css({ 'border-top': px(s.thickness === undefined ? 1 : s.thickness, 1, 20) + ' solid ' + (color(s.color) || '#dde3ea'),
          width: len(s.width), margin: px(s.margin_y === undefined ? 8 : s.margin_y, 0, 160) + ' auto' });
      case 'spacer':
        return css({ height: px(s.height === undefined ? 32 : s.height, 0, 400) });
      default:
        return '';
    }
  }

  /* =============================================================== canvas */

  function renderCanvas() {
    var wrapperId = 'pb-page-' + state.page.id;
    var style = h('style', { text: '#' + wrapperId + '{' + K.schemaDeclarations(META.pageStyle, state.page.style_settings) + '}\n' + (state.rendered.css || '') });
    var content = h('div', { class: 'pb-content' });
    var tops = roots();
    if (!tops.length) {
      content.append(h('div', { class: 'fb-empty', text: canEdit ? 'This page is empty. Add a block from the left (a Section or Columns block lets you group content).' : 'This page has no blocks.' }));
    }
    tops.forEach(function (b) { content.append(renderItem(b)); });
    els.canvas.replaceChildren(style, h('div', { class: 'pb-page' + (state.device === 'mobile' ? ' pb-page--narrow' : ''), id: wrapperId }, content));
    els.canvas.classList.toggle('is-mobile', state.device === 'mobile');
  }

  function renderItem(b) {
    var item = h('div', { class: 'pbb-item' + (b.ref === state.selected ? ' is-selected' : '') + (state.blockErrors[b.ref] ? ' has-fb-error' : ''),
      'data-ref': b.ref, tabindex: '0', 'aria-label': TYPES[b.type].label + ' block' });
    item.append(renderBlock(b));
    if (state.blockErrors[b.ref]) item.append(h('div', { class: 'fb-item__flags' }, h('span', { class: 'fb-flag fb-flag--error', text: 'Needs fixing' })));
    if (canEdit) item.append(itemBar(b));
    return item;
  }

  function itemBar(b) {
    var stop = function (fn) { return function (e) { e.stopPropagation(); fn(); }; };
    var bar = h('div', { class: 'fb-item__bar' }, h('span', { text: TYPES[b.type].label }),
      h('button', { type: 'button', title: 'Move up', 'aria-label': 'Move up', text: '↑', onclick: stop(function () { moveBlock(b.ref, -1); }) }),
      h('button', { type: 'button', title: 'Move down', 'aria-label': 'Move down', text: '↓', onclick: stop(function () { moveBlock(b.ref, 1); }) }));
    var parent = b.parent ? byRef(b.parent) : null;
    if (parent && parent.type === 'columns') {
      bar.append(h('button', { type: 'button', title: 'Move to the column on the left', 'aria-label': 'Previous column', text: '←', onclick: stop(function () { moveColumn(b.ref, -1); }) }),
        h('button', { type: 'button', title: 'Move to the column on the right', 'aria-label': 'Next column', text: '→', onclick: stop(function () { moveColumn(b.ref, 1); }) }));
    }
    if (parent) bar.append(h('button', { type: 'button', title: 'Move out of the container', 'aria-label': 'Move out of container', text: '⇱', onclick: stop(function () { moveOut(b.ref); }) }));
    bar.append(h('button', { type: 'button', title: 'Duplicate', 'aria-label': 'Duplicate', text: '⧉', onclick: stop(function () { duplicateBlock(b.ref); }) }),
      h('button', { type: 'button', title: 'Remove', 'aria-label': 'Remove', text: '✕', onclick: stop(function () { removeBlock(b.ref); }) }));
    return bar;
  }

  function slotArea(container, column, className) {
    var slot = h('div', { class: className + ' pbb-slot', 'data-column': String(column) });
    var kids = childrenOf(container.ref, column);
    kids.forEach(function (c) { slot.append(renderItem(c)); });
    if (!kids.length) {
      slot.append(h('button', { type: 'button', class: 'pbb-slot__empty', disabled: !canEdit, text: canEdit ? '+ Select, then add blocks here' : 'Empty',
        onclick: function (e) { e.stopPropagation(); select(container.ref, column); } }));
    }
    return slot;
  }

  function renderBlock(b) {
    var s = b.settings;
    var style = blockStyle(b) || null;
    switch (b.type) {
      case 'section':
        return h('section', { class: 'pb-block pb-section', style: style }, slotArea(b, 0, 'pbb-section-slot'));
      case 'columns':
        var cols = h('div', { class: 'pb-block pb-columns', style: style });
        for (var c = 0; c < slotCount(b); c++) cols.append(slotArea(b, c, 'pb-col'));
        return cols;
      case 'heading':
        var tag = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].indexOf(s.level) !== -1 ? s.level : 'h2';
        return h(tag, { class: 'pb-block pb-heading pb-heading--' + tag, style: style, text: s.text || '' });
      case 'paragraph':
      case 'html':
        return state.rendered.html[b.ref] !== undefined
          ? K.sanitizedHtml('pb-block pb-rich pb-' + b.type, state.rendered.html[b.ref])
          : h('div', { class: 'pb-block pb-rich', text: 'Rendering preview…' });
      case 'image':
        var media = mediaById(s.media_id);
        if (!media) return h('div', { class: 'pb-block pb-notice', text: 'No image selected yet — upload or choose one in the Block settings.' });
        return h('figure', { class: 'pb-block pb-image', style: style },
          h('img', { src: media.url, alt: s.alt || '', width: media.width || null, height: media.height || null,
            style: css({ width: len(s.width), 'max-width': len(s.max_width) || '100%', 'border-radius': px(s.radius, 0, 200) }) || null }));
      case 'button':
        return h('div', { class: 'pb-block pb-button', style: style }, h('a', { class: 'ff-button', href: '#', onclick: function (e) { e.preventDefault(); }, text: s.text || 'Button' }));
      case 'divider':
        return h('hr', { class: 'pb-block pb-divider', style: style });
      case 'spacer':
        return h('div', { class: 'pb-block pb-spacer pbb-spacer', style: style });
      case 'form':
        return renderFormBlock(b);
      default:
        return h('div', { class: 'pb-block' });
    }
  }

  function renderFormBlock(b) {
    var id = Number(b.settings.form_id);
    var info = formById(id);
    if (!id || !info) return h('div', { class: 'pb-block pb-notice', text: 'Choose a form in the Block settings.' });

    var wrap = h('div', { class: 'pb-block pb-form' });
    if (info.status !== 'active') {
      wrap.append(h('div', { class: 'pb-notice', text: '“' + info.name + '” is ' + info.status_label + ': it will not appear on the public page until it is published.' }));
    }
    if (formHtml[id] !== undefined) {
      var preview = K.sanitizedHtml('pbb-form-preview', formHtml[id]); // server-rendered by the real form renderer
      if (b.settings.hide_title) preview.querySelectorAll('.ff-title').forEach(function (n) { n.remove(); });
      if (b.settings.hide_description) preview.querySelectorAll('.ff-description').forEach(function (n) { n.remove(); });
      wrap.append(preview);
    } else {
      wrap.append(h('div', { class: 'pb-notice', text: 'Loading form preview…' }));
      loadFormPreview(id);
    }
    return wrap;
  }

  function loadFormPreview(id) {
    if (formLoading[id]) return;
    formLoading[id] = true;
    fetch(URLS.formPreview.replace('__FORM__', String(id)), { credentials: 'same-origin', headers: { Accept: 'text/html' } })
      .then(function (res) { return res.ok ? res.text() : ''; })
      .then(function (html) { formHtml[id] = html; formLoading[id] = false; renderCanvas(); })
      .catch(function () { formLoading[id] = false; });
  }

  /* ======================================================== canvas events */

  els.canvas.addEventListener('click', function (e) {
    if (e.target.closest('a, input, select, textarea, label') && e.target.closest('.pbb-form-preview')) return;
    var item = e.target.closest('.pbb-item');
    if (!item) return;
    e.stopPropagation();
    var slot = e.target.closest('.pbb-slot');
    var column = slot && slot.closest('.pbb-item') === item ? Number(slot.dataset.column) : undefined;
    if (item.dataset.ref !== state.selected || column !== undefined) select(item.dataset.ref, column);
  });
  els.canvas.addEventListener('keydown', function (e) {
    var item = e.target.classList && e.target.classList.contains('pbb-item') ? e.target : null;
    if (!item) return;
    var ref = item.dataset.ref;
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(ref); }
    else if (canEdit && e.altKey && e.key === 'ArrowUp') { e.preventDefault(); moveBlock(ref, -1); focusRef(ref); }
    else if (canEdit && e.altKey && e.key === 'ArrowDown') { e.preventDefault(); moveBlock(ref, 1); focusRef(ref); }
    else if (canEdit && e.key === 'Delete') { e.preventDefault(); removeBlock(ref); }
  });
  function focusRef(ref) { var el = els.canvas.querySelector('.pbb-item[data-ref="' + ref + '"]'); if (el) el.focus(); }

  document.querySelectorAll('[data-pb-device]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      state.device = btn.dataset.pbDevice;
      document.querySelectorAll('[data-pb-device]').forEach(function (x) { x.classList.toggle('is-active', x === btn); });
      renderCanvas();
    });
  });

  /* =============================================================== palette */

  function renderPalette() {
    if (!canEdit) {
      els.palette.replaceChildren(h('p', { class: 'fb-palette__hint', text: state.page.status === 'archived' ? 'This page is archived and read-only.' : 'You can view this page but not edit it.' }));
      return;
    }
    var nodes = [];
    PALETTE.forEach(function (group) {
      nodes.push(h('h3', { text: group[0] }));
      group[1].forEach(function (type) {
        nodes.push(h('button', { type: 'button', class: 'fb-palette__item', 'data-type': type, title: 'Add ' + TYPES[type].label,
          onclick: function () { addBlock(type); } }, h('span', { class: 'fb-palette__icon', 'aria-hidden': 'true', text: ICONS[type] }), TYPES[type].label));
      });
    });
    nodes.push(h('p', { class: 'fb-palette__hint', text: 'Blocks are added below the selection. Select a Section or a column to add blocks inside it. Reorder with the arrows or Alt+↑/↓.' }));
    els.palette.replaceChildren.apply(els.palette, nodes);
  }

  /* ================================================================ panels */

  function renderTabs() {
    els.tabs.replaceChildren.apply(els.tabs, TABS.map(function (t) {
      var hasError = Object.keys(state.errors).some(function (p) { return tabForPath(p) === t[0]; });
      return h('button', { type: 'button', role: 'tab', 'aria-selected': state.tab === t[0] ? 'true' : 'false', class: state.tab === t[0] ? 'is-active' : null,
        text: t[1] + (hasError ? ' ●' : ''), onclick: function () { state.tab = t[0]; renderTabs(); renderPanel(); } });
    }));
  }

  function tabForPath(path) {
    if (/^blocks/.test(path)) return 'block';
    if (/^style_settings/.test(path)) return 'design';
    if (path === 'custom_css') return 'code';
    return 'settings';
  }

  function renderPanel() {
    var scroller = els.panel.parentElement;
    var top = scroller.scrollTop;
    var nodes = { block: renderBlockPanel, design: renderDesignPanel, settings: renderSettingsPanel, code: renderCodePanel }[state.tab]();
    var fieldset = h('fieldset', { disabled: !canEdit, style: 'border:0;margin:0;padding:0;min-width:0' });
    K.append(fieldset, nodes);
    els.panel.replaceChildren(fieldset);
    scroller.scrollTop = top;
  }

  function group(title) { var g = h('div', { class: 'fb-group' }); if (title) g.append(h('h4', { text: title })); for (var i = 1; i < arguments.length; i++) K.append(g, arguments[i]); return g; }

  function renderBlockPanel() {
    var b = byRef(state.selected);
    if (!b) return [h('p', { class: 'fb-panel__empty', text: canEdit ? 'Select a block on the page to edit it, or add one from the left.' : 'Select a block to inspect it.' })];

    var s = b.settings;
    var p = 'blocks.' + state.blocks.indexOf(b) + '.settings.';
    var e = function (k) { return err(p + k); };
    var set = function (k, opts) { return function (v) { setSetting(b, k, v, opts); }; };
    var nodes = [];

    if (state.blockErrors[b.ref]) {
      nodes.push(h('div', { class: 'fb-callout fb-callout--danger' }, h('strong', { text: 'Fix before saving:' }),
        h('ul', { style: 'margin:4px 0 0;padding-left:16px' }, state.blockErrors[b.ref].map(function (m) { return h('li', { text: m }); }))));
    }

    switch (b.type) {
      case 'section':
        nodes.push(group('Section',
          h('p', { class: 'help', text: 'Groups blocks on a colored band. Select it, then add blocks to place them inside.' }),
          K.colorCtl('Background color', s.background_color, set('background_color'), { allowEmpty: true, error: e('background_color') }),
          K.colorCtl('Text color', s.text_color, set('text_color'), { allowEmpty: true, error: e('text_color') }),
          K.pxCtl('Vertical padding', s.padding_y, { min: 0, max: 160 }, set('padding_y'), { allowEmpty: true }),
          K.pxCtl('Horizontal padding', s.padding_x, { min: 0, max: 120 }, set('padding_x'), { allowEmpty: true }),
          K.pxCtl('Corner radius', s.radius, { min: 0, max: 200 }, set('radius'), { allowEmpty: true })));
        break;
      case 'columns':
        nodes.push(group('Columns',
          K.selectCtl('Number of columns', String(s.count || 2), [['2', '2 columns'], ['3', '3 columns'], ['4', '4 columns']], function (v) { setSetting(b, 'count', Number(v), { panel: true }); }, { error: e('count') }),
          h('div', { class: 'fb-control' }, h('span', { class: 'fb-control__label', text: 'Add new blocks into column' }),
            h('div', { class: 'fb-seg' }, Array.from({ length: slotCount(b) }, function (_, i) {
              return h('button', { type: 'button', class: state.targetColumn === i ? 'is-active' : null, text: String(i + 1), onclick: function () { state.targetColumn = i; renderPanel(); } });
            }))),
          K.pxCtl('Gap between columns', s.gap, { min: 0, max: 80 }, set('gap'), { allowEmpty: true }),
          K.selectCtl('Vertical alignment', s.vertical_align || '', [['', 'Stretch'], ['start', 'Top'], ['center', 'Middle'], ['end', 'Bottom']], set('vertical_align')),
          h('p', { class: 'help', text: 'Columns stack on top of each other on phones.' })));
        break;
      case 'heading':
        nodes.push(group('Heading',
          K.textCtl('Text', s.text, set('text'), { maxlength: 300, error: e('text') }),
          K.selectCtl('Level', s.level || 'h2', [['h1', 'H1 (page title)'], ['h2', 'H2'], ['h3', 'H3'], ['h4', 'H4'], ['h5', 'H5'], ['h6', 'H6']], set('level'), { error: e('level') }),
          K.selectCtl('Alignment', s.align || 'left', ALIGNS, function (v) { setSetting(b, 'align', v === 'left' ? null : v); }),
          K.pxCtl('Font size', s.font_size, { min: 10, max: 120 }, set('font_size'), { allowEmpty: true, error: e('font_size') }),
          K.selectCtl('Font weight', String(s.font_weight || ''), WEIGHTS, set('font_weight')),
          K.colorCtl('Color', s.color, set('color'), { allowEmpty: true, error: e('color') }),
          K.pxCtl('Space above', s.margin_top, { min: 0, max: 160 }, set('margin_top'), { allowEmpty: true }),
          K.pxCtl('Space below', s.margin_bottom, { min: 0, max: 160 }, set('margin_bottom'), { allowEmpty: true })));
        break;
      case 'paragraph':
        nodes.push(group('Text', richTextCtl(b, e('content')),
          K.selectCtl('Alignment', s.align || 'left', ALIGNS, function (v) { setSetting(b, 'align', v === 'left' ? null : v); }),
          K.pxCtl('Font size', s.font_size, { min: 10, max: 120 }, set('font_size'), { allowEmpty: true }),
          K.colorCtl('Color', s.color, set('color'), { allowEmpty: true, error: e('color') })));
        break;
      case 'html':
        nodes.push(group('Custom HTML',
          K.textareaCtl('HTML', s.content, function (v) { if (v === '') delete s.content; else s.content = v; changed(); scheduleSanitizeBlock(b); },
            { rows: 10, code: true, maxlength: 20000, error: e('content'),
              help: 'Sanitized on save and on display: scripts, iframes, forms, inline styles, event handlers and javascript: links are removed. The preview shows the sanitized result.' })));
        break;
      case 'image':
        nodes.push(group('Image', mediaPicker(b, e('media_id')),
          K.textCtl('Alt text (describe the image)', s.alt, set('alt'), { maxlength: 300, error: e('alt') }),
          K.lengthCtl('Width', s.width, IMAGE_LENGTH, set('width'), { allowEmpty: true, placeholder: 'auto (e.g. 600px or 100%)', error: e('width') }),
          K.lengthCtl('Max width', s.max_width, IMAGE_LENGTH, set('max_width'), { allowEmpty: true, placeholder: '100%', error: e('max_width') }),
          K.selectCtl('Alignment', s.align || 'center', ALIGNS, set('align')),
          K.pxCtl('Corner radius', s.radius, { min: 0, max: 200 }, set('radius'), { allowEmpty: true }),
          K.textCtl('Link (optional)', s.link_url, set('link_url'), { maxlength: 2000, placeholder: 'https://… or /page', error: e('link_url') }),
          K.checkCtl('Open link in a new tab', s.new_tab, set('new_tab'))));
        break;
      case 'button':
        var styleGroup = group('Button style');
        // Show the effective values (stored or default) -- exactly what renders.
        var effective = K.normalizeGroup(META.buttonStyle, s.style);
        Object.keys(META.buttonStyle).forEach(function (key) {
          if (key === 'custom_width' && effective.width !== 'custom') return;
          styleGroup.append(K.styleCtl(META.buttonStyle[key], effective[key], function (v) { setButtonStyle(b, key, v); }, { error: e('style.' + key) }));
        });
        nodes.push(group('Button',
          K.textCtl('Text', s.text, set('text'), { maxlength: 120, error: e('text') }),
          K.textCtl('Link URL', s.url, set('url'), { maxlength: 2000, placeholder: 'https://…, /page or #anchor', error: e('url') }),
          K.checkCtl('Open in a new tab', s.new_tab, set('new_tab'))), styleGroup);
        break;
      case 'divider':
        nodes.push(group('Divider',
          K.colorCtl('Color', s.color, set('color'), { allowEmpty: true }),
          K.pxCtl('Thickness', s.thickness, { min: 1, max: 20 }, set('thickness'), { allowEmpty: true }),
          K.lengthCtl('Width', s.width, IMAGE_LENGTH, set('width'), { allowEmpty: true, placeholder: '100%', error: e('width') }),
          K.pxCtl('Space above/below', s.margin_y, { min: 0, max: 160 }, set('margin_y'), { allowEmpty: true })));
        break;
      case 'spacer':
        nodes.push(group('Spacer', K.pxCtl('Height', s.height, { min: 0, max: 400 }, set('height'), { allowEmpty: true })));
        break;
      case 'form':
        var options = [['', '(choose a form)']].concat(state.forms.map(function (f) { return [String(f.id), f.name + ' — ' + f.status_label]; }));
        nodes.push(group('Embedded form',
          K.selectCtl('Form', String(s.form_id || ''), options, function (v) { setSetting(b, 'form_id', v ? Number(v) : null, { panel: true }); }, { error: e('form_id'),
            help: 'The page shows the form itself (same design, validation and submissions). Edit its fields in the Form Builder; nothing is copied into the page.' }),
          K.checkCtl('Hide the form title', s.hide_title, set('hide_title')),
          K.checkCtl('Hide the form description', s.hide_description, set('hide_description'))));
        break;
    }

    if (canEdit) {
      nodes.push(h('div', { class: 'fb-panel__actions' },
        h('button', { type: 'button', class: 'fba-btn fba-btn--ghost fba-btn--sm', text: 'Duplicate', onclick: function () { duplicateBlock(b.ref); } }),
        h('button', { type: 'button', class: 'fba-btn fba-btn--danger fba-btn--sm', text: 'Remove block', onclick: function () { removeBlock(b.ref); } })));
    }
    return nodes;
  }

  /** Textarea + tiny formatting toolbar that inserts safe tags around the selection. */
  function richTextCtl(b, error) {
    var ctl = K.textareaCtl('Content', b.settings.content, function (v) { setContent(v); }, { rows: 8, maxlength: 20000, error: error,
      help: 'Formatting: bold, italic, links, lists. Anything unsafe is removed on save.' });
    var area = ctl.querySelector('textarea');
    function setContent(v) { if (v === '') delete b.settings.content; else b.settings.content = v; changed(); scheduleSanitizeBlock(b); }
    function wrap(before, after) {
      var a = area.selectionStart, z = area.selectionEnd, v = area.value;
      area.value = v.slice(0, a) + before + v.slice(a, z) + after + v.slice(z);
      area.focus();
      area.setSelectionRange(a + before.length, z + before.length);
      setContent(area.value);
    }
    var bar = h('div', { class: 'pbb-rte' },
      h('button', { type: 'button', title: 'Bold', text: 'B', onclick: function () { wrap('<strong>', '</strong>'); } }),
      h('button', { type: 'button', title: 'Italic', text: 'I', onclick: function () { wrap('<em>', '</em>'); } }),
      h('button', { type: 'button', title: 'Paragraph', text: '¶', onclick: function () { wrap('<p>', '</p>'); } }),
      h('button', { type: 'button', title: 'Link', text: 'Link', onclick: function () {
        var url = window.prompt('Link URL (https://…, /page, mailto:…)');
        if (url) wrap('<a href="' + url.replace(/"/g, '&quot;') + '">', '</a>');
      } }),
      h('button', { type: 'button', title: 'Bulleted list', text: '• List', onclick: function () { wrap('<ul>\n<li>', '</li>\n</ul>'); } }));
    ctl.insertBefore(bar, area);
    return ctl;
  }

  function mediaPicker(b, error) {
    var current = mediaById(b.settings.media_id);
    var status = h('p', { class: 'help', text: current ? current.name + (current.width ? ' · ' + current.width + '×' + current.height : '') : 'No image selected.' });
    var file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', onchange: function () {
      if (!file.files.length) return;
      var data = new FormData();
      data.append('image', file.files[0]);
      status.textContent = 'Uploading…';
      K.api('POST', URLS.media, data).then(function (res) {
        if (res.ok && res.data && res.data.media) {
          state.media.unshift(res.data.media);
          setSetting(b, 'media_id', res.data.media.id, { panel: true });
        } else {
          status.textContent = (res.data && res.data.errors && res.data.errors.image && res.data.errors.image[0]) || 'Upload failed (HTTP ' + res.status + ').';
          status.className = 'error';
        }
      }).catch(function () { status.textContent = 'Upload failed. Check your connection.'; status.className = 'error'; });
    } });
    var choose = K.selectCtl('Or choose an uploaded image', String(b.settings.media_id || ''),
      [['', '(none)']].concat(state.media.map(function (m) { return [String(m.id), m.name]; })),
      function (v) { setSetting(b, 'media_id', v ? Number(v) : null, { panel: true }); });
    return h('div', { class: 'fb-control' },
      h('span', { class: 'fb-control__label', text: 'Upload an image (JPG, PNG or WebP, max ' + Math.round(META.uploads.maxKb / 1024) + ' MB)' }),
      current ? h('img', { src: current.url, alt: '', class: 'pbb-media-thumb' }) : null,
      file, status, error ? h('p', { class: 'error', text: error }) : null, choose);
  }

  function renderDesignPanel() {
    var nodes = [];
    Object.keys(META.pageStyle).forEach(function (g) {
      var box = group(META.pageStyle[g].label);
      Object.keys(META.pageStyle[g].properties).forEach(function (key) {
        var def = META.pageStyle[g].properties[key];
        box.append(K.styleCtl(def, (state.page.style_settings[g] || {})[key], function (v) {
          state.page.style_settings[g] = state.page.style_settings[g] || {};
          state.page.style_settings[g][key] = v;
          changed();
        }, { error: err('style_settings.' + g + '.' + key) }));
      });
      nodes.push(box);
    });
    return nodes;
  }

  function renderSettingsPanel() {
    var pg = state.page;
    return [group('Page',
      K.textCtl('Title', pg.title, function (v) { pg.title = v; changed(); }, { maxlength: 200, error: err('title'), help: 'Shown in the browser tab and search results.' }),
      K.textCtl('URL slug', pg.slug, function (v) { pg.slug = v.trim(); changed(); }, { maxlength: 100, error: err('slug'),
        help: URLS.publicBase + (pg.slug || '') + (pg.status === 'published' ? '  — this page is live: changing the slug breaks links already shared.' : '') }),
      K.textareaCtl('Meta description', pg.settings.meta_description, function (v) { pg.settings.meta_description = v || null; changed(); },
        { rows: 3, maxlength: 300, error: err('settings.meta_description'), help: 'Optional. Used by search engines and link previews.' }))];
  }

  function renderCodePanel() {
    if (!PERMS.canManageCustomCode) {
      return [h('div', { class: 'fb-callout', text: 'Only an administrator with custom-code access can edit custom CSS.' + (state.page.has_custom_code ? ' This page has custom CSS; it is applied in the preview.' : '') })];
    }
    return [h('div', { class: 'fb-callout fb-callout--warn', text: 'Every selector is automatically scoped to #pb-page-' + state.page.id + ', so it can only style this page. @import, @font-face and other at-rules except @media/@supports/@container/@keyframes are removed.' }),
      K.textareaCtl('Custom CSS', state.page.custom_css, function (v) {
        state.page.custom_css = v;
        changed();
        scheduleSanitize('css', 'css', v, function (r) { state.rendered.css = r; });
      }, { rows: 12, code: true, maxlength: 20000, error: err('custom_css'), placeholder: '.pb-heading { letter-spacing: .02em; }' })];
  }

  /* ================================================================ server */

  function scheduleSanitize(slot, kind, content, apply) {
    clearTimeout(sanitizeTimers[slot]);
    sanitizeTimers[slot] = setTimeout(function () {
      K.api('POST', URLS.sanitize, { kind: kind, content: content || '' }).then(function (res) {
        if (res.ok && res.data) { apply(res.data.result || ''); renderCanvas(); }
      }).catch(function () {});
    }, 350);
  }

  function scheduleSanitizeBlock(b) {
    scheduleSanitize('block-' + b.ref, 'html', b.settings.content || '', function (r) { state.rendered.html[b.ref] = r; });
  }

  function buildPayload(intent, autosave) {
    var p = {
      version: state.version,
      intent: intent,
      autosave: !!autosave,
      title: state.page.title,
      slug: state.page.slug,
      settings: { meta_description: state.page.settings.meta_description || null },
      style_settings: state.page.style_settings,
      blocks: state.blocks.map(function (b, i) {
        var out = { ref: b.ref, id: b.id || null, parent: b.parent || null, order: i, type: b.type, settings: b.settings };
        if (b.parent) out.column = b.column || 0;
        return out;
      }),
    };
    if (PERMS.canManageCustomCode) p.custom_css = state.page.custom_css || null;
    return p;
  }

  function setSaveState(text, kind) {
    els.saveState.textContent = text;
    els.saveState.className = 'fb-save-state' + (kind === 'dirty' ? ' is-dirty' : kind === 'error' ? ' is-error' : '');
  }

  function scheduleAutosave() {
    clearTimeout(state.autosaveTimer);
    if (!canEdit || state.page.status !== 'draft') return; // never autosave a live page
    state.autosaveTimer = setTimeout(function () { if (isDirty()) save('save', true); }, 5000);
  }

  function save(intent, autosave) {
    if (!canEdit) return;
    if (state.saving) { if (!autosave) state.pendingIntent = intent; return; }
    clearTimeout(state.autosaveTimer);
    state.saving = true;
    var seq = state.changeSeq;
    els.save.disabled = els.publish.disabled = true;
    setSaveState(autosave ? 'Autosaving…' : 'Saving…');

    K.api('PUT', URLS.save, buildPayload(intent, autosave)).then(function (res) {
      if (res.ok) {
        var refs = (res.data && res.data.refs) || {};
        state.blocks.forEach(function (b) { if (!b.id && refs[b.ref]) b.id = refs[b.ref]; });
        var s = res.data.state.page;
        state.version = s.version;
        ['status', 'status_label', 'version', 'has_custom_code'].forEach(function (k) { state.page[k] = s[k]; });
        state.forms = res.data.state.forms || state.forms;
        clearErrors();
        state.savedSeq = seq;
        var label = intent === 'publish' ? 'Published' : (autosave ? 'Autosaved' : 'Saved');
        setSaveState(label + ' at ' + K.now() + (isDirty() ? ' — newer changes not saved yet' : ''), isDirty() ? 'dirty' : null);
        renderToolbar();
        renderTabs();
        renderPanel();
        renderCanvas();
      } else if (res.status === 422) {
        showErrors((res.data && res.data.errors) || {});
        setSaveState(autosave ? 'Autosave paused — fix the problems listed above.' : 'Not saved — fix the problems listed above.', 'error');
      } else if (res.status === 409) {
        showBanner([(res.data && res.data.message) || 'This page changed somewhere else.'], true);
        setSaveState('Not saved — newer version exists.', 'error');
      } else if (res.status === 419) {
        showBanner(['Your session expired. Copy any text you need, then reload the page and sign in again.'], true);
        setSaveState('Not saved — session expired.', 'error');
      } else if (res.status === 403) {
        showBanner(['You don’t have permission to change this page (or it was archived).'], true);
        setSaveState('Not saved.', 'error');
      } else {
        setSaveState('Save failed (HTTP ' + res.status + '). Your changes are still here — try again.', 'error');
      }
    }).catch(function () {
      setSaveState('Could not reach the server. Your changes are still here — try again.', 'error');
    }).then(function () {
      state.saving = false;
      els.save.disabled = els.publish.disabled = false;
      if (state.pendingIntent) { var next = state.pendingIntent; state.pendingIntent = null; save(next, false); }
    });
  }

  function clearErrors() {
    state.errors = {};
    state.blockErrors = {};
    els.errors.hidden = true;
    els.errors.replaceChildren();
  }

  function showErrors(errors) {
    state.errors = errors;
    state.blockErrors = {};
    var items = [];
    Object.keys(errors).forEach(function (path) {
      var m = path.match(/^blocks\.(\d+)(\.|$)/);
      var b = m ? state.blocks[Number(m[1])] : null;
      if (b) {
        state.blockErrors[b.ref] = (state.blockErrors[b.ref] || []).concat(errors[path]);
        items.push({ text: TYPES[b.type].label + ': ' + errors[path][0], ref: b.ref });
      } else {
        items.push({ text: errors[path][0], tab: tabForPath(path) });
      }
    });
    showBanner(items, false);
    renderTabs();
    renderPanel();
    renderCanvas();
  }

  function showBanner(items, blocking) {
    var list = h('ul', null, items.map(function (item) {
      if (typeof item === 'string') return h('li', { text: item });
      return h('li', null, h('button', { type: 'button', text: item.text, onclick: function () {
        if (item.ref) select(item.ref); else { state.tab = item.tab; renderTabs(); renderPanel(); }
      } }));
    }));
    els.errors.replaceChildren(h('strong', { text: blocking ? 'Not saved.' : 'This page could not be saved:' }), list,
      blocking ? h('button', { type: 'button', text: 'Reload the builder', onclick: function () { window.location.reload(); } }) : null);
    els.errors.hidden = false;
  }

  function renderToolbar() {
    var st = state.page.status;
    els.statusBadge.className = 'fba-badge ' + ({ published: 'fba-badge--active', draft: 'fba-badge--draft', deactivated: 'fba-badge--inactive', archived: 'fba-badge--archived' }[st] || '');
    els.statusBadge.textContent = state.page.status_label;
    els.save.hidden = !canEdit;
    els.publish.hidden = !canEdit || st === 'published';
    els.save.textContent = st === 'published' ? 'Save changes' : (st === 'draft' ? 'Save draft' : 'Save');
    els.save.className = 'fba-btn fba-btn--sm ' + (st === 'published' ? 'fba-btn--primary' : 'fba-btn--ghost');
  }

  els.save.addEventListener('click', function () { save('save', false); });
  els.publish.addEventListener('click', function () {
    if (!window.confirm('Publish this page? It will be public at ' + URLS.publicBase + state.page.slug)) return;
    save('publish', false);
  });
  document.querySelectorAll('[data-pb-guard]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (isDirty() && !window.confirm('You have unsaved changes that will be lost. Continue?')) e.preventDefault();
    });
  });
  window.addEventListener('beforeunload', function (e) { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });

  /* ================================================================== boot */

  renderToolbar();
  renderPalette();
  renderTabs();
  renderPanel();
  renderCanvas();
  setSaveState(state.page.status === 'draft' && canEdit ? 'Drafts autosave as you work.' : '');
})();
