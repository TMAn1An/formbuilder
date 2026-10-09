<x-formbuilder::admin title="Create Page">
  <div class="fba-card" style="max-width:640px">
    <form method="POST" action="{{ $fb->admin('pages.store') }}">
      @csrf
      <div class="fba-field">
        <label for="title">Page title</label>
        <input type="text" id="title" name="title" value="{{ old('title') }}" required maxlength="200" autofocus placeholder="e.g. IEEE Volunteer Registration">
        <p class="fba-help">Also used to generate the page URL; you can change the URL in the builder.</p>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="fba-btn fba-btn--primary">Create and open builder</button>
        <a href="{{ $fb->admin('pages.index') }}" class="fba-btn fba-btn--ghost">Cancel</a>
      </div>
    </form>
  </div>
</x-formbuilder::admin>
