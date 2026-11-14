<?php

namespace TMAn1An\FormBuilder\Services\Media;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use TMAn1An\FormBuilder\Enums\FormFieldType;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\FormField;
use TMAn1An\FormBuilder\Models\PageBlock;
use TMAn1An\FormBuilder\Models\PageMedia;

/**
 * The shared image library (page_media): where an image is used, and
 * deleting one that is used nowhere. An image in use (a form banner, a
 * form Image block -- archived ones included -- or a page Image block) is
 * never deleted, so no published form or page can lose a picture.
 */
class MediaLibrary
{
    /**
     * Human-readable places the image is used, e.g. 'Form "Volunteer signup" (banner)'.
     *
     * @return list<string>
     */
    public function usages(PageMedia $media): array
    {
        $usages = [];

        foreach (Form::query()->where('settings->banner_media_id', $media->id)->orderBy('name')->get(['id', 'name']) as $form) {
            $usages[] = 'Form "'.$form->name.'" (banner)';
        }

        $fields = FormField::query()
            ->where('type', FormFieldType::ImageBlock->value)
            ->where('settings->media_id', $media->id)
            ->with('form:id,name')
            ->get();
        foreach ($fields->groupBy('form_id') as $group) {
            $usages[] = 'Form "'.($group->first()->form?->name ?? '#'.$group->first()->form_id).'" (Image block)';
        }

        $blocks = PageBlock::query()->where('media_id', $media->id)->with('page:id,title')->get();
        foreach ($blocks->groupBy('page_id') as $group) {
            $usages[] = 'Page "'.($group->first()->page?->title ?? '#'.$group->first()->page_id).'" (Image block)';
        }

        return $usages;
    }

    /** Deletes the row, then the file. Callers check usages() first. */
    public function delete(PageMedia $media): void
    {
        DB::transaction(fn () => $media->delete());
        Storage::disk($media->disk)->delete($media->path);
    }
}
