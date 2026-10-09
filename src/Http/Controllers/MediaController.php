<?php

namespace TMAn1An\FormBuilder\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TMAn1An\FormBuilder\Models\PageMedia;

/**
 * Public delivery of page images (route mode -- no storage:link needed).
 * Looked up by random uuid, served with the stored server-detected image
 * MIME type and nosniff. Never serves form-submission uploads: those live
 * in a different table, on a private disk, behind an admin-only route.
 */
class MediaController extends Controller
{
    public function show(PageMedia $media): StreamedResponse
    {
        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, basename($media->path), [
            'Content-Type' => $media->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
