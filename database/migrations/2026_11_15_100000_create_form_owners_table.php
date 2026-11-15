<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co-owners of a form: admins its creator (or another co-owner, or a Super
 * Admin) has given full control of the form -- edit, publish, edit/delete
 * submissions, delete the form, and manage co-owners. Everyone else with
 * builder access can only view it. See docs/FORMS.md §Ownership and sharing.
 * Additive only (one new table).
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = config('formbuilder.users_table', 'users');

        Schema::create('form_owners', function (Blueprint $table) use ($users) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($users)->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();
            $table->unique(['form_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_owners');
    }
};
