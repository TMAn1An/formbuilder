{{--
  "Delete form" button (forms list and builder toolbar). Deleting removes the
  form, its fields and every submission for good (FormBuilderService::delete),
  so a form with submissions asks the admin to type DELETE; an empty form
  only asks for a confirmation. Expects $form, $submissions (count), $pages
  (number of page blocks embedding it).
--}}
@php
  $warning = 'Delete the form "'.$form->name.'" for good?'
      .($submissions > 0 ? ' Its '.$submissions.' '.($submissions === 1 ? 'submission' : 'submissions').' (answers and uploaded images) will be deleted too.' : '')
      .($pages > 0 ? ' It is embedded in '.$pages.' page '.($pages === 1 ? 'block' : 'blocks').', which will show nothing until you choose another form.' : '')
      .' This cannot be undone.';
@endphp
<form method="POST" action="{{ $fb->admin('forms.destroy', $form) }}" class="fb-inline-form"
  @if ($submissions > 0)
    onsubmit="return (window.prompt({{ \Illuminate\Support\Js::from($warning."\n\nType DELETE to confirm.") }}) || '').trim().toUpperCase() === 'DELETE'"
  @else
    onsubmit="return window.confirm({{ \Illuminate\Support\Js::from($warning) }})"
  @endif>
  @csrf
  @method('DELETE')
  <button type="submit" class="fba-btn fba-btn--danger fba-btn--sm" data-fb-guard>Delete</button>
</form>
