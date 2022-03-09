<?php

namespace ExclusiveDev\LaravelRequestDocs;

// use Spatie\LaravelPackageTools\Package;
// use Spatie\LaravelPackageTools\PackageServiceProvider;
use Illuminate\Support\ServiceProvider as PackageServiceProvider;
use ExclusiveDev\LaravelRequestDocs\Commands\LaravelRequestDocsCommand;
use Route;

class LaravelRequestDocsServiceProvider extends PackageServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'request-docs');
        Route::get(config('request-docs.url'), [\Osem\LaravelRequestDocs\Controllers\LaravelRequestDocsController::class, 'index'])
            ->name('request-docs.index')
            ->middleware(config('request-docs.middlewares'));
        if ($this->app->runningInConsole()) {
            $this->commands([
                LaravelRequestDocsCommand::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/request-docs.php', 'request-docs');
    }
}
