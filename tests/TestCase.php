<?php

declare(strict_types = 1);

namespace Tests;

use Amondar\RepositoryPattern\ServiceProvider;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use JetBrains\PhpStorm\NoReturn;
use Orchestra\Testbench\TestCase as CoreTestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends CoreTestCase
{
    use RefreshDatabase, WithFaker;

    protected function getPackageProviders($app)
    {
        return [
            LaravelDataServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineRoutes($router)
    {
        //
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/_fixtures/migrations');
    }

    #[NoReturn]
    protected function runInBenchmarking(Closure $closure): void
    {
        $start = microtime(true);
        $closure();
        $end = microtime(true);

        dd(($end - $start) * 1000);
    }
}
