<?php

namespace TMAn1An\FormBuilder;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use TMAn1An\FormBuilder\Contracts\AuditLogger;
use TMAn1An\FormBuilder\Contracts\Authorizer;
use TMAn1An\FormBuilder\Models\Form;
use TMAn1An\FormBuilder\Models\Page;
use TMAn1An\FormBuilder\Policies\FormPolicy;
use TMAn1An\FormBuilder\Policies\PagePolicy;
use TMAn1An\FormBuilder\Support\CssScoper;
use TMAn1An\FormBuilder\Support\HtmlSanitizer;

/**
 * Wires the package into a host app: config, migrations, views (namespace
 * `formbuilder::`), routes, policies and the two host-provided contracts.
 * Auto-discovered via composer.json "extra.laravel.providers".
 */
class FormBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/formbuilder.php', 'formbuilder');

        $this->app->singleton(FormBuilder::class);
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(CssScoper::class);
        $this->app->bind(Authorizer::class, fn ($app) => $app->make(config('formbuilder.authorizer')));
        $this->app->bind(AuditLogger::class, fn ($app) => $app->make(config('formbuilder.audit_logger')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'formbuilder');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'formbuilder');

        // Every package view gets `$fb` (route/asset URL helper).
        View::composer('formbuilder::*', fn ($view) => $view->with('fb', $this->app->make(FormBuilder::class)));

        Gate::policy(Form::class, FormPolicy::class);
        Gate::policy(Page::class, PagePolicy::class);

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/formbuilder.php' => config_path('formbuilder.php')], 'formbuilder-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/formbuilder')], 'formbuilder-views');
        }
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(config('formbuilder.public.middleware', ['web']))
            ->group(__DIR__.'/../routes/internal.php');

        if (config('formbuilder.public.enabled', true)) {
            Route::middleware(config('formbuilder.public.middleware', ['web']))
                ->name(config('formbuilder.public.name', 'formbuilder.'))
                ->group(__DIR__.'/../routes/public.php');
        }

        if (config('formbuilder.admin.enabled', true)) {
            Route::middleware(config('formbuilder.admin.middleware', ['web', 'auth']))
                ->prefix(config('formbuilder.admin.prefix', 'formbuilder'))
                ->name(config('formbuilder.admin.name', 'formbuilder.admin.'))
                ->group(__DIR__.'/../routes/admin.php');
        }
    }
}
