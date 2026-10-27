<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\ServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Orchestra\Testbench\TestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * Deliberately has no RefreshDatabase transaction: tests exercise actual cache
 * hits and Laravel's production commit/rollback callback behavior.
 */
abstract class RepositoryCacheTestCase extends TestCase
{
    protected CacheRepository $cache;

    protected CachedUserRepository $repository;

    private int $emailSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new CacheRepository(new ArrayStore(true));
        $this->repository = new CachedUserRepository($this->cache);
    }

    protected function getPackageProviders($app): array
    {
        return [LaravelDataServiceProvider::class, ServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
    }

    protected function createUser(string $name = 'Oleg Sereda', array $attributes = []): User
    {
        return $this->repository->create(array_replace([
            'name'      => $name,
            'email'     => 'my+' . ++$this->emailSequence . '@email.com',
            'password'  => '123456',
            'is_active' => true,
            'is_admin'  => false,
        ], $attributes));
    }

    protected function entry(string $method, array $arguments, ?CachedUserRepository $repository = null): ?array
    {
        $proxy = ($repository ?? $this->repository)->cache;

        return $proxy->getStore()->get($proxy->generateKey($method, $arguments));
    }

    protected function scopeKey(string $scope): string
    {
        return 'repository-dependency:' . hash('sha256', $scope);
    }
}
