<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple Page Builder. A page is a list of structured blocks (never one
 * big HTML blob); `section` and `columns` blocks hold one level of child
 * blocks (`parent_id` + `slot` = column index). A Form block stores a
 * REFERENCE (`form_id`) to a form -- the form definition is never copied
 * into the page. Image blocks reference `page_media`. No route deletes a
 * page: pages are archived. See docs/PAGES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = config('formbuilder.users_table', 'users');

        Schema::create('page_media', function (Blueprint $table) use ($users) {
            $table->id();
            $table->uuid('uuid')->unique(); // public URL key -- ids aren't enumerable from the outside
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) use ($users) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('status')->default('draft'); // TMAn1An\FormBuilder\Enums\PageStatus
            $table->json('settings')->nullable();       // meta description, ...
            $table->json('style_settings')->nullable(); // PageStyleSchema
            $table->text('custom_css')->nullable();     // scoped at render time
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained($users)->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('page_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('page_blocks')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot')->default(0);
            $table->string('type', 32); // TMAn1An\FormBuilder\Enums\PageBlockType
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->foreignId('form_id')->nullable()->constrained('forms')->nullOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('page_media')->nullOnDelete();
            $table->timestamps();

            $table->index(['page_id', 'parent_id', 'slot', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_blocks');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('page_media');
    }
};
