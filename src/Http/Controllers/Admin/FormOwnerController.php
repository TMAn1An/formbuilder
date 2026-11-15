<?php

namespace TMAn1An\FormBuilder\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TMAn1An\FormBuilder\Http\Controllers\Controller;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Services\Forms\FormBuilderService;

/**
 * "Owners" page of a form: who manages it (creator + co-owners), and adding /
 * removing co-owners. Anyone with builder access can see the page; only
 * someone who manages the form (FormPolicy::share) can change it. A new
 * co-owner must be an existing admin offered by
 * FormBuilderService::possibleOwners() -- never an arbitrary user id.
 */
class FormOwnerController extends Controller
{
    public function __construct(private readonly FormBuilderService $builder) {}

    public function index(Request $request, Form $form): View
    {
        $this->authorize('view', $form);
        $canShare = $request->user()->can('share', $form);

        return view('formbuilder::admin.forms.owners', [
            'form' => $form,
            'creator' => $form->creator,
            'owners' => $form->owners()->get(),
            'candidates' => $canShare ? $this->builder->possibleOwners($form) : collect(),
            'canShare' => $canShare,
            'label' => fn ($user) => $this->builder->userLabel($user),
        ]);
    }

    public function store(Request $request, Form $form): RedirectResponse
    {
        $this->authorize('share', $form);
        $request->validate(['user_id' => ['required', 'integer']]);

        $user = $this->builder->possibleOwners($form)->first(fn ($u) => (string) $u->getKey() === (string) $request->input('user_id'));
        if ($user === null) {
            throw ValidationException::withMessages(['user_id' => 'Choose an admin from the list.']);
        }
        $this->builder->addOwner($form, $user, $request->user());

        return redirect()->to($this->fb()->admin('forms.owners.index', $form))
            ->with('status', $this->builder->userLabel($user).' is now a co-owner and can manage this form.');
    }

    public function destroy(Request $request, Form $form, string $user): RedirectResponse
    {
        $this->authorize('share', $form);

        $owner = $form->owners()->whereKey($user)->first();
        abort_if($owner === null, 404);
        $this->builder->removeOwner($form, $owner, $request->user());

        return redirect()->to($this->fb()->admin('forms.owners.index', $form))
            ->with('status', $this->builder->userLabel($owner).' is no longer a co-owner.');
    }
}
