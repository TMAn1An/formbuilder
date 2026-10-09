<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata for files uploaded through Image Upload fields. The bytes live
 * on config('formbuilder.storage.submissions_disk') (private by default)
 * under form-builder/submissions/{form}/{submission}/{uuid}.{ext}; only the
 * path and metadata are stored here -- never binary data. The matching
 * form_submission_values row carries {"file_id": id} as its value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            $table->string('field_key', 64);
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['form_submission_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submission_files');
    }
};
