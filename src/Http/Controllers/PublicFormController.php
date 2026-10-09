<?php

namespace TMAn1An\FormBuilder\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TMAn1An\FormBuilder\Enums\FormAvailabilityState;
use TMAn1An\FormBuilder\Enums\PageBlockType;
use TMAn1An\FormBuilder\Enums\PageStatus;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Services\Forms\FormAvailability;
use TMAn1An\FormBuilder\Services\Forms\FormPresenter;
use TMAn1An\FormBuilder\Services\Forms\FormSubmissionService;
use TMAn1An\FormBuilder\Services\Forms\FormUnavailableException;

/**
 * Public /forms/{slug}. Only Active forms exist publicly (anything else
 * 404s, as does a private form for a visitor without builder access).
 *
 * The same POST endpoint serves forms embedded in pages: the embed adds a
 * hidden `_embed_page` id. It is only honoured when that page is published
 * and really embeds this form, so it can never become an open redirect.
 * Validation errors use a per-form error bag ("form_{id}") so two forms on
 * one page never show each other's errors. See docs/PAGES.md §Form embedding.
 */
class PublicFormController extends Controller
{
    public const SESSION_SUBMITTED = 'forms.submitted';

    public function __construct(
        private readonly FormAvailability $availability,
        private readonly FormPresenter $presenter,
        private readonly FormSubmissionService $submissions,
    ) {}

    public function show(Request $request, Form $form): View
    {
        $state = $this->availability->check($form, $request->user(), self::submittedIds($request));
        abort_if($state === FormAvailabilityState::Unavailable, 404);

        $justSubmitted = $request->session()->get('form_submitted') === $form->id;

        return view('formbuilder::forms.show', [
            'presented' => $this->presenter->present($form),
            'action' => $state === FormAvailabilityState::Open ? $this->fb()->public('forms.submit', $form) : null,
            'notice' => $state === FormAvailabilityState::Open ? null : $state->message(),
            'success' => $justSubmitted ? $form->setting('success_message') : null,
        ]);
    }

    public function submit(Request $request, Form $form): RedirectResponse
    {
        $state = $this->availability->check($form, $request->user(), self::submittedIds($request));
        abort_if($state === FormAvailabilityState::Unavailable, 404);

        $embedPage = $this->embeddingPage($request, $form);
        $returnUrl = $embedPage
            ? $this->fb()->public('pages.show', ['page' => $embedPage->slug]).'#'.$form->wrapperId()
            : $this->fb()->public('forms.show', $form);

        try {
            // post() -- body only (query-string parameters are never form data) + uploaded files.
            $this->submissions->submit($form, array_replace($request->post(), $request->allFiles()), $request->user(), self::submittedIds($request), [
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'embedded_in_page' => $embedPage?->id,
            ]);
        } catch (ValidationException $e) {
            throw $e->errorBag('form_'.$form->id)->redirectTo($returnUrl);
        } catch (FormUnavailableException) {
            return redirect()->to($returnUrl);
        }

        $request->session()->push(self::SESSION_SUBMITTED, $form->id);

        $redirect = $form->setting('redirect_url');
        if ($redirect) {
            return redirect()->away($redirect);
        }

        return redirect()->to($returnUrl)->with('form_submitted', $form->id);
    }

    /** The published page this submission came from, only if it really embeds this form. */
    private function embeddingPage(Request $request, Form $form): ?Page
    {
        $pageId = $request->input('_embed_page');
        if (! is_scalar($pageId) || ! ctype_digit((string) $pageId)) {
            return null;
        }

        return Page::query()
            ->whereKey((int) $pageId)
            ->where('status', PageStatus::Published)
            ->whereHas('blocks', fn ($q) => $q->where('type', PageBlockType::Form)->where('form_id', $form->id))
            ->first();
    }

    /** @return list<int> */
    public static function submittedIds(Request $request): array
    {
        return array_map('intval', (array) $request->session()->get(self::SESSION_SUBMITTED, []));
    }
}
