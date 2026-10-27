<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Contracts\RepositoryContract;
use ArrayObject;
use BackedEnum;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Spatie\LaravelData\Data;
use Traversable;
use UnitEnum;

/**
 * Caches repository calls explicitly routed through this proxy and collects their dependencies.
 *
 * @template TModel of Model
 * @template TData of Data = Data
 * @template TRepository of RepositoryContract<TModel, TData> = RepositoryContract<TModel, TData>
 *
 * @mixin TRepository
 */
final class HighOrderRepositoryCacheProxy
{
    private int $seconds = 300;

    /**
     * Shared by TTL clones so nested calls contribute to their parent reads.
     *
     * @var ArrayObject<int, ArrayObject<string, string>>
     */
    private readonly ArrayObject $frames;

    /** @param TRepository $repository */
    public function __construct(
        private readonly RepositoryContract $repository,
        private readonly ?CacheRepository $cacheRepository = null,
    ) {
        $this->frames = new ArrayObject;
    }

    /**
     * Caches public method calls routed through this proxy. Mutation methods should execute
     * directly and invalidate their dependencies instead.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        if ( ! $this->repository->usesCache()) {
            return $this->repository->{$method}(...$parameters);
        }

        // Never read shared cached data or publish uncommitted query results.
        if ($this->repository->makeModel()->getConnection()->transactionLevel() > 0) {
            return $this->repository->{$method}(...$parameters);
        }

        $arguments = $this->bindArguments($method, $parameters);
        $key = $this->generateKey($method, $arguments);

        $store = $this->getStore();
        $entry = $store->get($key);

        if (
            is_array($entry)
            && array_key_exists('value', $entry)
            && isset($entry['dependencies'])
            && is_array($entry['dependencies'])
            && $this->dependenciesAreCurrent($entry['dependencies'])
        ) {
            $this->inheritDependencies($entry['dependencies']);

            return $entry['value'];
        }

        $frame = new ArrayObject;
        $this->frames->append($frame);

        try {
            // A repository-wide flush must also invalidate reads already running.
            $this->dependsOn('repository:' . $this->repository::class);

            // Persisted model arguments can identify dependencies before the read.
            foreach ($arguments as $argument) {
                $this->collectModelDependencies($argument);
            }

            $value = $this->repository->{$method}(...$parameters);

            if ($value instanceof Builder || $value instanceof QueryBuilder || ($value instanceof Traversable && ! $value instanceof Collection)) {
                throw new LogicException('Cache methods must return materialized results, not query builders or streams.');
            }

            // Returned models supplement explicit scopes. Lists and empty results
            // still require dependsOn() before querying.
            $this->collectModelDependencies($value);
            $dependencies = $frame->getArrayCopy();

            if ($this->dependenciesAreCurrent($dependencies)) {
                $store->put($key, [
                    'value'        => $value,
                    'dependencies' => $dependencies,
                ], $this->seconds);
            }

            return $value;
        } finally {
            $this->frames->offsetUnset($this->frames->count() - 1);
        }
    }

    /**
     * Binds a fresh proxy to another repository without inheriting active frames.
     * The cache backend and TTL are preserved; TTL clones still share frames.
     *
     * @template TNewRepository of RepositoryContract<TModel, TData>
     *
     * @param  TNewRepository  $repository
     * @return self<TModel, TData, TNewRepository>
     */
    public function forRepository(RepositoryContract $repository): self
    {
        $proxy = new self($repository, $this->cacheRepository);
        $proxy->seconds = $this->seconds;

        return $proxy;
    }

    /**
     * Sets the TTL in seconds for a new proxy, preserving the original proxy.
     *
     * @return self<TModel, TData, TRepository>
     */
    public function ttl(int $seconds): self
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Cache TTL must be a positive number of seconds.');
        }

        $proxy = clone $this;
        $proxy->seconds = $seconds;

        return $proxy;
    }

    /**
     * Registers scopes before a query executes. Direct uncached calls are a no-op.
     */
    public function dependsOn(string ...$scopes): void
    {
        if ($this->frames->count() === 0) {
            return;
        }

        $cache = $this->resolveCacheRepository();

        foreach ($scopes as $scope) {
            $key = $this->dependencyKey($scope);

            // A finite TTL selects the store's atomic add implementation.
            // Expired dependency tokens cause cache misses, never token reuse.
            $cache->add($key, bin2hex(random_bytes(16)), max($this->seconds, 300));
            $version = $cache->get($key);

            if ( ! is_string($version)) {
                throw new RuntimeException("Unable to initialize cache dependency [$scope].");
            }

            $this->inheritDependencies([$key => $version]);
        }
    }

    /**
     * Invalidates scopes across repositories using the same cache store.
     * Call after the successful outermost database commit.
     */
    public function invalidate(string ...$scopes): void
    {
        $cache = $this->resolveCacheRepository();

        foreach ($scopes as $scope) {
            // Missing or recreated versions invalidate older cached results too.
            if ( ! $cache->put(
                $this->dependencyKey($scope),
                bin2hex(random_bytes(16)),
                max($this->seconds, 300)
            )) {
                throw new RuntimeException("Unable to invalidate cache dependency [$scope].");
            }
        }
    }

    /**
     * Flushes only entries tagged with the concrete repository class.
     */
    public function forget(): bool
    {
        $this->invalidate('repository:' . $this->repository::class);

        return $this->getStore()->flush();
    }

    public function getStore(): TaggedCache
    {
        return $this->resolveCacheRepository()->tags([$this->repository::class]);
    }

    /**
     * All arguments participate in the key. The declared first argument must be
     * an integer, a string, or a persisted, unchanged model.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public function generateKey(string $method, array $parameters): string
    {
        $arguments = $this->bindArguments($method, $parameters);
        $first = reset($arguments);

        if ($arguments === [] || ! (is_int($first) || is_string($first) || $first instanceof Model)) {
            throw new InvalidArgumentException('A cached method must have an int, string, or persisted Model as its first argument.');
        }

        return hash('sha256', serialize([
            $this->repository::class,
            $method,
            $this->normalizeArgument($arguments),
        ]));
    }

    private function resolveCacheRepository(): LaravelCacheRepository
    {
        $cache = $this->cacheRepository ?? app(CacheRepository::class);

        if ( ! $cache instanceof LaravelCacheRepository || ! $cache->supportsTags()) {
            throw new RuntimeException('Repository caching requires a Laravel cache repository with tag support.');
        }

        return $cache;
    }

    /**
     * Binds positional/named arguments and default values to one stable order.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function bindArguments(string $method, array $parameters): array
    {
        if ( ! method_exists($this->repository, $method)) {
            throw new LogicException("Cached method [$method] must be declared on the repository.");
        }

        $reflection = new ReflectionMethod($this->repository, $method);

        if ( ! $reflection->isPublic() || $reflection->isStatic()) {
            throw new LogicException("Cached method [$method] must be a public instance method.");
        }

        $arguments = [];
        $position = 0;
        $named = false;

        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isPassedByReference() || $parameter->isVariadic()) {
                throw new LogicException('Cached methods cannot have reference or variadic parameters.');
            }

            $name = $parameter->getName();

            if (array_key_exists($position, $parameters)) {
                if ($named || array_key_exists($name, $parameters)) {
                    throw new InvalidArgumentException("Invalid arguments for cached method [$method].");
                }

                $arguments[$name] = $parameters[$position];
                unset($parameters[$position]);
            } elseif (array_key_exists($name, $parameters)) {
                $named = true;
                $arguments[$name] = $parameters[$name];
                unset($parameters[$name]);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
            } else {
                throw new InvalidArgumentException("Missing cached method argument [$name].");
            }

            $position++;
        }

        if ($parameters !== []) {
            throw new InvalidArgumentException("Unknown arguments for cached method [$method].");
        }

        return $arguments;
    }

    private function normalizeArgument(mixed $value): mixed
    {
        if ($value instanceof Model) {
            if ( ! $value->exists || $value->getKey() === null || $value->isDirty()) {
                throw new InvalidArgumentException('Cached model arguments must be persisted and unchanged.');
            }

            // Methods must depend on persisted identity, not loaded relations.
            return ['model', $value::class, $value->getConnection()->getName(), $value->getKey()];
        }

        if ($value instanceof BackedEnum) {
            return ['backed-enum', $value::class, $value->value];
        }

        if ($value instanceof UnitEnum) {
            return ['enum', $value::class, $value->name];
        }

        if (is_array($value)) {
            return ['array', array_map($this->normalizeArgument(...), $value)];
        }

        if ($value === null || is_scalar($value)) {
            return ['scalar', $value];
        }

        throw new InvalidArgumentException('Cache arguments must contain only primitives, arrays, enums, or persisted Models.');
    }

    private function collectModelDependencies(mixed $value): void
    {
        if ($value instanceof Model && is_a($value, $this->repository->model())) {
            $this->dependsOn(...$this->repository->getCacheScopesForModel($value));
        } elseif (is_array($value) || $value instanceof Collection) {
            foreach ($value as $item) {
                $this->collectModelDependencies($item);
            }
        }
    }

    private function dependencyKey(string $scope): string
    {
        if ($scope === '') {
            throw new InvalidArgumentException('Cache dependency scopes must not be empty.');
        }

        return 'repository-dependency:' . hash('sha256', $scope);
    }

    /** @param array<string, string> $dependencies */
    private function dependenciesAreCurrent(array $dependencies): bool
    {
        $cache = $this->resolveCacheRepository();

        foreach ($dependencies as $key => $version) {
            if ($cache->get($key) !== $version) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $dependencies */
    private function inheritDependencies(array $dependencies): void
    {
        foreach ($this->frames as $frame) {
            foreach ($dependencies as $key => $version) {
                if ( ! $frame->offsetExists($key)) {
                    $frame[$key] = $version;
                }
            }
        }
    }
}
