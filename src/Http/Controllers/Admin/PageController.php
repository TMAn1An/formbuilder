<?php

namespace TMAn1An\FormBuilder\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use TMAn1An\FormBuilder\Http\Controllers\Controller;
use TMAn1An\FormBuilder\Http\Requests\SavePageDefinitionRequest;
use TMAn1An\FormBuilder\Http\Requests\StorePageRequest;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Services\Forms\FormPresenter;
use TMAn1An\FormBuilder\Services\Forms\FormVersionConflictException;
use TMAn1An\FormBuilder\Services\Pages\PageBuilderService;
use TMAn1An\FormBuilder\Services\Pages\PageBuilderState;
use TMAn1An\FormBuilder\Services\Pages\PageDefinitionValidator;
use TMAn1An\FormBuilder\Services\Pages\PagePresenter;
use TMAn1An\FormBuilder\Support\CssScoper;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/** Page Builder admin. Same shape as the form admin; no destroy action exists. See docs/PAGES.md. */
class PageController extends Controller
{
    public function __construct(private readonly PageBuilderService $builder) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Page::class);

        $showArchived = $request->boolean('archived');

        return view('formbuilder::admin.pages.index', [
            'pages' => Page::query()
                ->withCount('blocks')
                ->when(! $showArchived, fn ($q) => $q->where('status', '!=', 'archived'))
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString(),
            'showArchived' => $showArchived,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Page::class);

        return view('formbuilder::admin.pages.create');
    }

    public function store(StorePageRequest $request): RedirectResponse
    {
        $page = $this->builder->create($request->validated('title'), $request->user());

        return redirect()->to($this->fb()->admin('pages.edit', $page))->with('status', 'Page created. Add blocks from the left-hand panel.');
    }

    public function edit(Request $request, Page $page, PageBuilderState $state): View
    {
        $this->authorize('view', $page);

        return view('formbuilder::admin.pages.builder', [
            'page' => $page,
            'builder' => $state->forPage($page, $request->user()) + [
                'meta' => $state->meta(),
                'urls' => [
                    'save' => $this->fb()->admin('pages.update', $page),
                    'sanitize' => $this->fb()->admin('pages.sanitize', $page),
                    'media' => $this->fb()->admin('pages.media.store'),
                    'formPreview' => $this->fb()->admin('pages.form-preview', ['form' => '__FORM__']),
                    'preview' => $this->fb()->admin('pages.preview', $page),
                    'publicBase' => preg_replace('#[^/]+$#', '', $this->fb()->public('pages.show', ['page' => 'x'])),
                ],
            ],
        ]);
    }

    /** JSON: 200 with the fresh builder state, 409 on a stale version, 422 on validation errors. */
    public function update(SavePageDefinitionRequest $request, Page $page, PageDefinitionValidator $definitions, PageBuilderState $state): JsonResponse
    {
        $definition = $definitions->normalize($request->validated(), $request->canManageCustomCode());
        $publish = $request->validated('intent') === 'publish';

        try {
            $saved = $this->builder->saveDefinition($page, $definition, (int) $request->validated('version'), $request->user(), $publish, $request->boolean('autosave'));
        } catch (FormVersionConflictException $e) {
            return response()->json(['message' => $e->getMessage().' Reload the builder to get the latest version.', 'current_version' => $e->currentVersion], 409);
        }

        return response()->json([
            'message' => $publish ? 'Saved and published.' : 'Saved.',
            // Lets the builder attach ids to the new blocks it just sent without reloading.
            'refs' => (object) $this->builder->lastIdsByRef(),
            'state' => $state->forPage($saved, $request->user()),
        ]);
    }

    /** Live-preview helper for Paragraph/Custom HTML blocks and custom CSS. */
    public function sanitize(Request $request, Page $page, HtmlSanitizer $html, CssScoper $css): JsonResponse
    {
        $this->authorize('update', $page);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['css', 'html'])],
            'content' => ['nullable', 'string', 'max:'.(CssScoper::MAX_LENGTH + 5000)],
        ]);

        return response()->json(['result' => $data['kind'] === 'css'
            ? $css->scope($data['content'] ?? '', '#'.$page->wrapperId())
            : $html->sanitize($data['content'] ?? '')]);
    }

    /** Server-rendered (non-submittable) form markup for the builder canvas -- the real renderer, not a JS imitation. */
    public function formPreview(Form $form, FormPresenter $presenter): Response
    {
        $this->authorize('viewAny', Page::class);
        $this->authorize('view', $form);

        return response()->view('formbuilder::forms._renderer', [
            'presented' => $presenter->present($form),
            'action' => null,
            'notice' => null,
            'success' => null,
            'titleTag' => 'h2',
        ]);
    }

    public function preview(Request $request, Page $page, PagePresenter $presenter): View
    {
        $this->authorize('view', $page);

        return view('formbuilder::pages.show', [
            'presented' => $presenter->present($page, $request->user(), [], null, preview: true),
            'previewBackUrl' => $this->fb()->admin('pages.edit', $page),
        ]);
    }

    public function publish(Request $request, Page $page): RedirectResponse
    {
        $this->authorize('publish', $page);
        $this->builder->publish($page, $request->user());

        return back()->with('status', "\"{$page->title}\" is now published at ".$this->fb()->public('pages.show', ['page' => $page->slug]));
    }

    public function deactivate(Request $request, Page $page): RedirectResponse
    {
        $this->authorize('deactivate', $page);
        $this->builder->deactivate($page, $request->user());

        return back()->with('status', "\"{$page->title}\" was deactivated and is no longer public.");
    }

    public function archive(Request $request, Page $page): RedirectResponse
    {
        $this->authorize('archive', $page);
        $this->builder->archive($page, $request->user());

        return redirect()->to($this->fb()->admin('pages.index'))->with('status', "\"{$page->title}\" was archived.");
    }

    public function restore(Request $request, Page $page): RedirectResponse
    {
        $this->authorize('restore', $page);
        $this->builder->restore($page, $request->user());

        return back()->with('status', "\"{$page->title}\" was restored as a deactivated page.");
    }

    public function duplicate(Request $request, Page $page): RedirectResponse
    {
        $this->authorize('duplicate', $page);
        $copy = $this->builder->duplicate($page, $request->user());

        return redirect()->to($this->fb()->admin('pages.edit', $copy))->with('status', "Duplicated as \"{$copy->title}\" (draft).");
    }
}
