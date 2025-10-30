<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Repository;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Class HigherOrderDBTransactionProxy
 *
 * @template TModel
 * @template TData
 *
 * @see Repository
 *
 * @property-read static $withTrashed
 *
 * @mixin \Amondar\RepositoryPattern\Repository<TModel, TData>
 */
readonly class HigherOrderRepositoryTransactionProxy
{
    /**
     * HigherOrderDBTransactionProxy constructor.
     *
     * @param  Repository<TModel, TData>  $repository
     */
    public function __construct(private Repository $repository, private bool $useTrashed = false)
    {
        //
    }

    /**
     * Proxy a method call onto the collection items.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws Throwable
     */
    public function __call($method, $parameters)
    {
        return DB::transaction(fn() => $this->repository->{$method}(...$parameters));
    }

    /**
     * Magic __get method.
     *
     * @param  string  $name  The name of the property being accessed.
     * @return mixed Returns the value of the property, or executes a transaction for specific properties.
     *
     * @throws Throwable
     */
    public function __get(string $name)
    {
        if ($name === 'quietly') {
            return DB::transaction(fn() => $this->repository->$name);
        }

        if ($name === 'withTrashed') {
            return new static($this->repository, true);
        }

        throw new RuntimeException("Undefined property: $name");
    }

    /**
     * @param  Closure(TModel, Repository<TModel, TData>): TModel  $callback
     * @return TModel
     *
     * @throws Throwable
     */
    public function forUpdate(string|int $key, Closure $callback)
    {
        return DB::transaction(function () use ($key, $callback) {
            $model = $this->repository
                ->whereKey($key)
                ->lockForUpdate()
                ->when(
                    $this->useTrashed,
                    fn($query) => $query->hasMacro('withTrashed') ? $query->withTrashed() : $query
                )
                ->firstOrFail();

            return $callback($model, $this->repository);
        });
    }

    /**
     * Determines whether trashed records should be used.
     *
     * @return bool True if trashed records should be used, otherwise false.
     */
    public function shouldUseTrashed(): bool
    {
        return $this->useTrashed;
    }
}
