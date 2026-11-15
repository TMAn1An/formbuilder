{{--
  Who manages a form (FormOwnerController): the creator, co-owners, and a
  note that Super Admins manage every form. People who manage the form can
  add another existing admin as a co-owner or remove one. Every name is
  escaped.
--}}
<x-formbuilder::admin :title="'Owners — '.$form->name">
  <p><a href="{{ $fb->admin('forms.index') }}">&larr; All forms</a> &middot; <a href="{{ $fb->admin('forms.edit', $form) }}">Open builder</a></p>

  @if ($errors->any())
    <div class="fba-alert fba-alert--error" role="alert">{{ $errors->first() }}</div>
  @endif

  <div class="fba-card" style="max-width:760px">
    <h2 style="margin-top:0;font-size:1.1rem">Who can manage &ldquo;{{ $form->name }}&rdquo;</h2>
    <p class="fba-help" style="margin-top:0">
      The creator and co-owners can edit and publish the form, edit and delete its submissions, delete the form,
      and add or remove co-owners. Super Admins can manage every form. Everyone else with access to Forms can only
      view this form and its submissions.
    </p>

    <table class="fba-table">
      <thead><tr><th>Admin</th><th>Role on this form</th><th></th></tr></thead>
      <tbody>
        <tr>
          <td>{{ $label($creator) }}</td>
          <td><span class="fba-badge">Creator</span></td>
          <td></td>
        </tr>
        @foreach ($owners as $owner)
          <tr>
            <td>{{ $label($owner) }}</td>
            <td><span class="fba-badge">Co-owner</span></td>
            <td style="text-align:right">
              @if ($canShare)
                <form method="POST" action="{{ $fb->admin('forms.owners.destroy', [$form, $owner->getKey()]) }}" class="fb-inline-form"
                  onsubmit="return window.confirm({{ \Illuminate\Support\Js::from('Remove '.$label($owner).' as a co-owner? They will only be able to view this form.') }})">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="fba-btn fba-btn--danger fba-btn--sm">Remove</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  @if ($canShare)
    <div class="fba-card" style="max-width:760px">
      <h3 style="margin-top:0;font-size:1rem">Add a co-owner</h3>
      @if ($candidates->isEmpty())
        <p class="fba-help">Every other admin already manages this form (Super Admins always do).</p>
      @else
        <form method="POST" action="{{ $fb->admin('forms.owners.store', $form) }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
          @csrf
          <div class="fba-field" style="margin:0;flex:1;min-width:240px">
            <label for="owner-user">Admin</label>
            <select id="owner-user" name="user_id" required>
              <option value="">Choose an admin…</option>
              @foreach ($candidates as $candidate)
                <option value="{{ $candidate->getKey() }}">{{ $label($candidate) }}</option>
              @endforeach
            </select>
          </div>
          <button type="submit" class="fba-btn fba-btn--primary">Add co-owner</button>
        </form>
        <p class="fba-help">Only admins who already have an account here are listed.</p>
      @endif
    </div>
  @endif
</x-formbuilder::admin>
