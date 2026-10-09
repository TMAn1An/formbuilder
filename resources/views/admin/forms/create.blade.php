<x-formbuilder::admin title="Create Form">
  <div class="fba-card" style="max-width:640px">
    <form method="POST" action="{{ $fb->admin('forms.store') }}">
      @csrf
      <div class="fba-field">
        <label for="name">Form name</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="150" autofocus placeholder="e.g. Volunteer Registration 2026">
        <p class="fba-help">Used in the admin and to generate the public URL. You can set a different public title and the URL in the builder.</p>
      </div>
      <div class="fba-field">
        <label for="description">Description <span class="fba-muted" style="font-weight:400">(optional)</span></label>
        <textarea id="description" name="description" rows="3" maxlength="2000">{{ old('description') }}</textarea>
        <p class="fba-help">Shown under the title on the public form.</p>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="fba-btn fba-btn--primary">Create and open builder</button>
        <a href="{{ $fb->admin('forms.index') }}" class="fba-btn fba-btn--ghost">Cancel</a>
      </div>
    </form>
  </div>
</x-formbuilder::admin>
