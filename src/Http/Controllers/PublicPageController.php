<?php

namespace TMAn1An\FormBuilder\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use TMAn1An\FormBuilder\Enums\PageStatus;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Services\Pages\PagePresenter;

/** Public /pages/{slug}: only Published pages resolve; drafts, deactivated and archived pages 404. */
class PublicPageController extends Controller
{
    public function show(Request $request, Page $page, PagePresenter $presenter): View
    {
        abort_unless($page->status === PageStatus::Published, 404);

        $justSubmitted = $request->session()->get('form_submitted');

        return view('formbuilder::pages.show', [
            'presented' => $presenter->present(
                $page,
                $request->user(),
                PublicFormController::submittedIds($request),
                is_int($justSubmitted) ? $justSubmitted : null,
            ),
        ]);
    }
}
