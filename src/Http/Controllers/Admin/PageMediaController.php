<?php

namespace TMAn1An\FormBuilder\Http\Controllers\Admin;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TMAn1An\FormBuilder\Http\Controllers\Controller;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Models\PageMedia;
use TMAn1An\FormBuilder\Services\Forms\FieldSettingsSchema;
use TMAn1An\FormBuilder\Services\Forms\FormSubmissionService;
use TMAn1An\FormBuilder\Services\Pages\PageBuilderState;

/**
 * Image uploads for page Image blocks (public content). Same validation as
 * form image uploads: detected MIME (jpg/png/webp only -- no SVG), size,
 * and a real image decode; stored under a random name with an extension
 * derived from the detected type, on config('formbuilder.storage.media_disk').
 */
class PageMediaController extends Controller
{
    public function store(Request $request, PageBuilderState $state): JsonResponse
    {
        $this->authorize('create', Page::class);

        $request->validate([
            'image' => ['required', 'file',
                'mimetypes:'.implode(',', FieldSettingsSchema::IMAGE_TYPES),
                'max:'.(int) config('formbuilder.uploads.max_kb', 10240),
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value instanceof UploadedFile && @getimagesize($value->getRealPath()) === false) {
                        $fail('The image must be a valid JPG, PNG or WebP file.');
                    }
                }],
        ], ['image.mimetypes' => 'The image must be a JPG, PNG or WebP file.']);

        /** @var UploadedFile $upload */
        $upload = $request->file('image');
        $mime = (string) $upload->getMimeType();
        $extension = (string) array_search($mime, FieldSettingsSchema::IMAGE_TYPES, true);
        $dimensions = @getimagesize($upload->getRealPath()) ?: [null, null];
        $disk = (string) config('formbuilder.storage.media_disk', 'public');
        $directory = trim((string) config('formbuilder.storage.media_path', 'form-builder/pages'), '/').'/'.now()->format('Y/m');
        $uuid = Str::uuid()->toString();

        $path = Storage::disk($disk)->putFileAs($directory, $upload, $uuid.'.'.$extension);
        abort_if($path === false, 500, 'Could not store the image.');

        $media = PageMedia::create([
            'uuid' => $uuid,
            'disk' => $disk,
            'path' => $path,
            'original_name' => FormSubmissionService::safeOriginalName($upload->getClientOriginalName(), $extension),
            'mime_type' => $mime,
            'size' => (int) $upload->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'uploaded_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['media' => $state->media($media)], 201);
    }
}
