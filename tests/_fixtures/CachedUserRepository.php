<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryProxy;
use ArrayObject;
use Closure;
use Fiber;
use Generator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use RuntimeException;

/**
 * Uses the same User model and payloads as the existing repository tests.
 *
 * Concrete types avoid PhpStorm losing inherited static generic arguments.
 *
 * @property-read HighOrderRepositoryCacheProxy<User, UserData, CachedUserRepository> $cache
 * @property-read HighOrderRepositoryProxy<User, UserData, CachedUserRepository> $quietly
 * @property-read HighOrderRepositoryProxy<User, UserData, CachedUserRepository> $transaction
 * @property-read HighOrderRepositoryProxy<User, UserData, CachedUserRepository> $unlocked
 * @property-read HighOrderRepositoryProxy<User, UserData, CachedUserRepository> $unlock
 */
readonly class CachedUserRepository extends UserRepository
{
    public ArrayObject $state;

    public function __construct(?CacheRepository $cacheRepository = null)
    {
        $this->state = new ArrayObject(['calls' => []]);
        parent::__construct($cacheRepository);
    }

    public static function staticRead(string $id): string
    {
        return $id;
    }

    public function usesCache(): bool
    {
        return true;
    }

    public function calls(string $method): int
    {
        return $this->state['calls'][$method] ?? 0;
    }

    public function usersNamed(string $name, bool $activeOnly = false): Collection
    {
        $this->record(__FUNCTION__);
        $this->cache->dependsOn("users:name:$name");

        return $this->query()->where('name', $name)
            ->when($activeOnly, fn($query) => $query->where('is_active', true))
            ->get();
    }

    public function userCount(string $name): int
    {
        $this->record(__FUNCTION__);
        $this->cache->dependsOn("users:name:$name");

        return $this->query()->where('name', $name)->count();
    }

    public function dashboard(string $name): array
    {
        $this->record(__FUNCTION__);

        return ['count' => $this->cache->ttl(7)->userCount($name)];
    }

    public function directDashboard(string $name): array
    {
        $this->record(__FUNCTION__);

        return ['count' => $this->userCount($name)];
    }

    public function administrators(string $scope = 'admins'): Collection
    {
        $this->record(__FUNCTION__);
        $this->cache->dependsOn('users:admins');

        return $this->query()->where('is_admin', true)->get();
    }

    public function modelById(string $id): ?User
    {
        $this->record(__FUNCTION__);

        return $this->findById($id);
    }

    public function modelName(User $user): string
    {
        $this->record(__FUNCTION__);

        return $user->name;
    }

    public function value(int|string $id, mixed $value = null): mixed
    {
        $this->record(__FUNCTION__);

        return $value;
    }

    public function scopedValue(string $name): int
    {
        $this->record(__FUNCTION__);
        $this->cache->dependsOn("users:name:$name");

        if (($this->state['onRead'] ?? null) instanceof Closure) {
            ($this->state['onRead'])($this, $name);
        }

        return $this->calls(__FUNCTION__);
    }

    public function throwing(string $name): never
    {
        $this->cache->dependsOn("users:name:$name");
        throw new RuntimeException('Expected repository read failure.');
    }

    public function fiberRead(string $name): string
    {
        $this->record(__FUNCTION__);
        $this->cache->dependsOn("users:name:$name");
        Fiber::suspend($this);

        return $name;
    }

    public function identity(string $id): int
    {
        return spl_object_id($this);
    }

    public function resultBuilder(string $id): mixed
    {
        return $this->query();
    }

    public function resultQueryBuilder(string $id): mixed
    {
        return $this->query()->toBase();
    }

    public function resultStream(string $id): Generator
    {
        yield $id;
    }

    public function resultLazyCollection(string $id): LazyCollection
    {
        return LazyCollection::make(fn() => yield $id);
    }

    public function noArguments(): int
    {
        return 1;
    }

    public function booleanFirst(bool $flag): bool
    {
        return $flag;
    }

    public function variadic(string $id, mixed ...$values): array
    {
        return $values;
    }

    public function byReference(string &$id): string
    {
        return $id;
    }

    protected function protectedRead(string $id): string
    {
        return $id;
    }

    protected function getModelBaseScope(Model $model): string
    {
        return "users:name:$model->name";
    }

    protected function getModelAdditionalScopes(Model $model): array
    {
        return $model->is_admin ? ['users:admins'] : [];
    }

    private function record(string $method): void
    {
        $calls = $this->state['calls'];
        $calls[$method] = ($calls[$method] ?? 0) + 1;
        $this->state->offsetSet('calls', $calls);
    }
}
