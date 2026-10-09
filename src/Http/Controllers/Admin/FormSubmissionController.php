<?php

namespace TMAn1An\FormBuilder\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TMAn1An\FormBuilder\Http\Controllers\Controller;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormField;
use TMAn1An\FormBuilder\Models\FormSubmission;
use TMAn1An\FormBuilder\Models\FormSubmissionFile;
use TMAn1An\FormBuilder\Services\Forms\FormSubmissionExportService;

/**
 * Read-only submission views, uploaded-image delivery and Excel export.
 * No update/destroy action exists for submissions. The per-form list shows
 * a few summary columns (fields flagged "show in submissions list", else
 * the first three); the full record is on the detail page.
 */
class FormSubmissionController extends Controller
{
    private const SUMMARY_COLUMNS = 3;

    public function overview(): View
    {
        $this->authorize('viewAny', Form::class);

        return view('formbuilder::admin.forms.submissions.overview', [
            'forms' => Form::query()
                ->withCount('submissions')
                ->withMax('submissions', 'submitted_at')
                ->orderByDesc('submissions_max_submitted_at')
                ->orderBy('name')
                ->paginate(30),
        ]);
    }

    public function index(Form $form): View
    {
        $this->authorize('viewSubmissions', $form);

        $summaryFields = $this->summaryFields($form);
        $submissions = $form->submissions()
            ->with(['submitter', 'values' => fn ($q) => $q->whereIn('form_field_id', $summaryFields->pluck('id'))])
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(25);

        return view('formbuilder::admin.forms.submissions.index', [
            'form' => $form,
            'summaryFields' => $summaryFields,
            'submissions' => $submissions,
            'rows' => $submissions->getCollection()->map(fn (FormSubmission $submission) => [
                'submission' => $submission,
                'cells' => $summaryFields->map(
                    fn (FormField $field) => $submission->values->firstWhere('form_field_id', $field->id)?->display_value
                )->all(),
            ]),
        ]);
    }

    public function show(Form $form, FormSubmission $submission): View
    {
        $this->authorize('viewSubmissions', $form);

        return view('formbuilder::admin.forms.submissions.show', [
            'form' => $form,
            'submission' => $submission->load(['values', 'files', 'submitter']),
            // Shown alongside the snapshot label when the field was renamed/archived since.
            'currentFields' => $form->fields()->get()->keyBy('id'),
        ]);
    }

    /**
     * An uploaded image, only for signed-in admins (route is behind the admin
     * middleware + this policy check; scopeBindings ties file -> submission
     * -> form). Served with the stored, server-detected image MIME type and
     * nosniff, so it can never be interpreted as HTML/script.
     */
    public function file(Request $request, Form $form, FormSubmission $submission, FormSubmissionFile $file): StreamedResponse
    {
        $this->authorize('viewSubmissions', $form);

        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->original_name) ?: 'image';

        return $disk->response($file->path, $filename, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, max-age=600',
        ], $disposition);
    }

    public function export(Form $form, FormSubmissionExportService $exporter): BinaryFileResponse
    {
        $this->authorize('export', $form);

        return response()
            ->download($exporter->export($form), $exporter->filename($form), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /** @return Collection<int, FormField> */
    private function summaryFields(Form $form): Collection
    {
        $collecting = $form->activeFields()->get()->filter(fn (FormField $f) => $f->type->collectsValue())->values();
        $flagged = $collecting->filter(fn (FormField $f) => $f->setting('show_in_list'))->values();

        return ($flagged->isNotEmpty() ? $flagged : $collecting)->take(self::SUMMARY_COLUMNS)->values();
    }
}
