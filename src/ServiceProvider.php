<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern;

use Illuminate\Database\Schema\Blueprint;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Class ServiceProvider
 *
 * @author Amondar-SO
 */
final class ServiceProvider extends PackageServiceProvider
{
    /**
     * Configure package
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('repository-pattern');
    }

    public function packageBooted(): void
    {
        Blueprint::macro('versionable', function (string $column = 'version') {
            return $this->unsignedBigInteger($column);
        });
    }

    /**
     * Register any application services.
     */
    public function packageRegistered(): void
    {
        //
    }
}
