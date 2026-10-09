<?php

namespace TMAn1An\FormBuilder\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use TMAn1An\FormBuilder\Contracts\AuditLogger;
use TMAn1An\FormBuilder\FormBuilderServiceProvider;
use TMAn1An\FormBuilder\Tests\Fixtures\RecordingAuditLogger;
use TMAn1An\FormBuilder\Tests\Fixtures\User;

/**
 * Package test harness: a throwaway Laravel app (Orchestra Testbench) with
 * a minimal User model whose `is_admin` flag drives the default
 * GateAuthorizer's custom-code / archive gates -- the same extension points
 * a real host uses. Admin routes use prefix "admin" here so URLs read
 * like a typical host install.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [FormBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('formbuilder.user_model', User::class);
        $app['config']->set('formbuilder.audit_logger', RecordingAuditLogger::class);
        $app['config']->set('formbuilder.admin.prefix', 'admin');
        $app['config']->set('formbuilder.admin.name', 'formbuilder.admin.');
        $app['config']->set('formbuilder.storage.submissions_disk', 'local');
        $app['config']->set('formbuilder.storage.media_disk', 'public');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        // The auth middleware redirects guests to a route named "login".
        Route::get('/login', fn () => 'login')->name('login');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(RecordingAuditLogger::class);
        $this->app->bind(AuditLogger::class, RecordingAuditLogger::class);

        Gate::define('formbuilder.custom-code', fn (User $user) => $user->is_admin);
        Gate::define('formbuilder.archive', fn (User $user) => $user->is_admin);
    }

    protected function audit(): RecordingAuditLogger
    {
        return $this->app->make(RecordingAuditLogger::class);
    }
}
