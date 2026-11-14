/* =========================================================================
   Dynamic forms — public page runtime.
   Applies conditional visibility as the visitor types: hidden fields are
   hidden AND their inputs disabled (so they're neither validated by the
   browser nor submitted). Purely a convenience — the server re-applies the
   same rules (FormVisibilityResolver) and ignores hidden fields regardless.
   Requires form-logic.js. No custom admin JavaScript is ever executed here
   (see docs/FORMS.md §Custom code security).

   Step-by-step forms (<form data-ff-steps>, the form's "Show one section at
   a time" setting): every Section field starts a step; only the current
   step is shown, with Back / Next, and the submit button on the last step.
   Steps whose fields are all hidden by conditions are skipped. Next checks
   the current step's answers with the browser's own validation; on submit
   every step is checked and the visitor is taken to the first problem.
   Steps are only hidden with a CSS class (never disabled), so every answer
   is still submitted together and validated by the server as usual.
   ========================================================================= */
(function () {
  'use strict';

  function readValue(formEl, field) {
    var inputs = formEl.querySelectorAll('[name="' + CSS.escape(field.key) + '"], [name="' + CSS.escape(field.key + '[]') + '"]');
    if (inputs.length === 0) return null;

    if (field.type === 'checkbox_group') {
      return Array.prototype.filter.call(inputs, function (i) { return i.checked; }).map(function (i) { return i.value; });
    }
    if (field.type === 'radio') {
      var checked = Array.prototype.find.call(inputs, function (i) { return i.checked; });
      return checked ? checked.value : null;
    }
    if (field.type === 'checkbox') {
      return inputs[0].checked ? '1' : null;
    }
    return inputs[0].value;
  }

  /** Splits the form into steps at each Section field. Returns null if there's only one step. */
  function setupSteps(root, formEl) {
    var grid = formEl.querySelector('.ff-grid');
    var actions = formEl.querySelector('.ff-actions');
    var submit = actions && actions.querySelector('button[type="submit"]');
    if (!grid || !submit) return null;

    var groups = [];
    Array.prototype.forEach.call(grid.children, function (el) {
      if (!el.hasAttribute('data-ff-key')) return; // e.g. hidden-field inputs
      var isSection = el.classList.contains('ff-field--section');
      if (!groups.length || (isSection && groups[groups.length - 1].items.length)) groups.push({ items: [], title: '' });
      var group = groups[groups.length - 1];
      if (isSection && !group.items.length) {
        var t = el.querySelector('.ff-section__title');
        group.title = t ? t.textContent.trim() : '';
      }
      group.items.push(el);
    });
    if (groups.length < 2) return null;

    var preview = formEl.hasAttribute('data-ff-preview');
    var progress = document.createElement('div');
    progress.className = 'ff-steps';
    var label = document.createElement('p');
    label.className = 'ff-steps__label';
    label.tabIndex = -1;
    label.setAttribute('aria-live', 'polite');
    var bar = document.createElement('div');
    bar.className = 'ff-steps__bar';
    bar.setAttribute('aria-hidden', 'true');
    var fill = document.createElement('span');
    bar.appendChild(fill);
    progress.appendChild(label);
    progress.appendChild(bar);
    grid.parentNode.insertBefore(progress, grid);

    function button(text, secondary) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'ff-button' + (secondary ? ' ff-button--secondary' : '');
      b.textContent = text;
      return b;
    }
    var back = button('Back', true);
    var next = button('Next', false);
    actions.classList.add('ff-actions--steps');
    actions.insertBefore(back, submit);
    actions.insertBefore(next, submit);

    // The browser can't show its message on a field in a hidden step, so
    // validation is done here, one step at a time (see submit below).
    formEl.noValidate = true;

    var current = null;

    function visibleGroups() {
      return groups.filter(function (g) { return g.items.some(function (el) { return !el.hidden; }); });
    }

    function firstInvalid(group) {
      if (preview) return null;
      for (var i = 0; i < group.items.length; i++) {
        if (group.items[i].hidden) continue;
        var inputs = group.items[i].querySelectorAll('input, select, textarea');
        for (var j = 0; j < inputs.length; j++) {
          if (!inputs[j].disabled && inputs[j].willValidate && !inputs[j].checkValidity()) return inputs[j];
        }
      }
      return null;
    }

    function render() {
      var visible = visibleGroups();
      if (!visible.length) return;
      if (visible.indexOf(current) === -1) {
        // The current step was hidden by a condition: move to the next one still shown.
        var at = groups.indexOf(current);
        current = visible.filter(function (g) { return groups.indexOf(g) > at; })[0] || visible[visible.length - 1];
      }
      groups.forEach(function (g) {
        g.items.forEach(function (el) { el.classList.toggle('ff-step-hidden', g !== current); });
      });
      var pos = visible.indexOf(current);
      var last = pos === visible.length - 1;
      label.textContent = 'Step ' + (pos + 1) + ' of ' + visible.length + (current.title ? ' \u00B7 ' + current.title : '');
      fill.style.width = Math.round(((pos + 1) / visible.length) * 100) + '%';
      back.hidden = pos === 0;
      next.hidden = last;
      submit.hidden = !last;
    }

    function goTo(group) {
      current = group;
      render();
      var top = root.getBoundingClientRect().top;
      if (top < 0) root.scrollIntoView({ block: 'start' });
      label.focus({ preventScroll: true });
    }

    function move(delta) {
      var visible = visibleGroups();
      var target = visible[visible.indexOf(current) + delta];
      if (target) goTo(target);
    }

    next.addEventListener('click', function () {
      var invalid = firstInvalid(current);
      if (invalid) { invalid.reportValidity(); return; }
      move(1);
    });
    back.addEventListener('click', function () { move(-1); });

    // Registered before the runtime's own submit handler, so a blocked submit stops here.
    formEl.addEventListener('submit', function (e) {
      var visible = visibleGroups();
      if (visible.indexOf(current) !== visible.length - 1) {
        // Enter pressed in a field before the last step: behave like Next.
        e.preventDefault();
        e.stopImmediatePropagation();
        next.click();
        return;
      }
      for (var i = 0; i < visible.length; i++) {
        var invalid = firstInvalid(visible[i]);
        if (invalid) {
          e.preventDefault();
          e.stopImmediatePropagation();
          if (visible[i] !== current) goTo(visible[i]);
          invalid.reportValidity();
          return;
        }
      }
    });

    // After a server-side validation error, open the step with the first error.
    var errored = groups.filter(function (g) { return g.items.some(function (el) { return el.classList.contains('has-error'); }); })[0];
    current = errored || groups[0];

    return { refresh: render };
  }

  function init(root) {
    var logicEl = root.querySelector('script[data-ff-logic]');
    var formEl = root.querySelector('form');
    if (!logicEl || !formEl) return;

    var fields;
    try { fields = JSON.parse(logicEl.textContent); } catch (e) { return; }
    var steps = formEl.hasAttribute('data-ff-steps') ? setupSteps(root, formEl) : null;

    function apply() {
      var visible = window.FormLogic.resolve(fields, function (f) { return readValue(formEl, f); });
      Object.keys(visible).forEach(function (key) {
        var wrapper = root.querySelector('[data-ff-key="' + CSS.escape(key) + '"]');
        if (!wrapper) return;
        wrapper.hidden = !visible[key];
        wrapper.querySelectorAll('input, select, textarea').forEach(function (input) {
          // Fields the admin marked "disabled" stay disabled whatever happens.
          input.disabled = !visible[key] || input.hasAttribute('data-ff-disabled');
        });
      });
      if (steps) steps.refresh();
    }

    formEl.addEventListener('input', apply);
    formEl.addEventListener('change', apply);
    formEl.addEventListener('submit', function () {
      var button = formEl.querySelector('.ff-button');
      if (button) { window.setTimeout(function () { button.disabled = true; }, 0); }
    });
    apply();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ff-form').forEach(init);
  });
})();
