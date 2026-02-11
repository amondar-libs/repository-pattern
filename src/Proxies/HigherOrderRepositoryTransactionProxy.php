<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Enums\LockType;
use Amondar\RepositoryPattern\Repository;
use Closure;
use Illuminate\Events\NullDispatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Class HigherOrderDBTransactionProxy
 *
 * @template TModel
 * @template TData
 *
 * @mixin Repository<TModel, TData>
 *
 * @see Repository
 *
 * @property-read static $withTrashed
 */
readonly class HigherOrderRepositoryTransactionProxy
{
    /**
     * HigherOrderDBTransactionProxy constructor.
     *
     * @param  Repository<TModel, TData>  $repository
     */
    public function __construct(
        private Repository $repository,
        private bool $beQuiet = false,
        private bool $useTrashed = false,
        private LockType $lockType = LockType::forUpdate,
        private int $transactionLevel = 0
    ) {
        //
    }

    /**
     * Proxy a method call onto the collection items.
     *
     *
     * @return mixed
     *
     * @throws Throwable
     */
    public function __call(string $method, array $parameters)
    {
        if ($this->useTrashed) {
            throw new RuntimeException("`->withTrashed` modifier can't be used with direct repository methods");
        }

        return DB::transaction(
            fn() => $this->beQuiet ?
            $this->repository->quietly->{$method}(...$parameters)
        : $this->repository->{$method}(...$parameters)
        );
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
            return new static($this->repository, true, $this->useTrashed, $this->lockType, $this->transactionLevel);
        }

        if ($name === 'withTrashed') {
            return new static($this->repository, $this->beQuiet, true, $this->lockType, $this->transactionLevel);
        }

        throw new RuntimeException("Undefined property: $name");
    }

    /**
     * Sets the transaction level and returns a new instance of the class.
     *
     * @param  int  $level  The transaction level to set.
     * @return static A new instance of the class with the specified transaction level.
     */
    public function onLevel(int $level): static
    {
        return new static($this->repository, $this->beQuiet, $this->useTrashed, $this->lockType, $level);
    }

    /**
     * Creates a new instance of the class with a shared lock type.
     *
     * @return static A new instance configured with LockType::SHARED.
     */
    public function asShared(): static
    {
        return new static($this->repository, $this->beQuiet, $this->useTrashed, LockType::shared, $this->transactionLevel);
    }

    /**
     * Executes a transactional operation on a locked database record identified by the given key.
     *
     * @template TResult
     *
     * @param  string|int  $key  The primary key of the record to lock
     *                           for update.
     * @param  Closure(TModel, Repository<TModel, TData>): TResult  $callback  A callback function that processes the
     *                                                                         locked record and repository.
     * @return TResult
     *
     * @throws Throwable
     */
    public function forUpdate(string|int $key, Closure $callback)
    {
        $modelClass = $this->repository->model();
        $dispatcher = $modelClass::getEventDispatcher();

        if ($this->beQuiet && $dispatcher) {
            $modelClass::setEventDispatcher(new NullDispatcher($dispatcher));
        }

        try {
            if (DB::transactionLevel() === $this->transactionLevel) {
                return DB::transaction(function () use ($key, $callback) {
                    return $callback($this->getLock($key), $this->repository);
                });
            }

            return $callback($this->getLock($key), $this->repository);
        } finally {
            if ($dispatcher) {
                $modelClass::setEventDispatcher($dispatcher);
            }
        }
    }

    /**
     * Executes the provided callback within a "for update" lock on the specified model.
     *
     * @param  string|int  $key  The key identifying the model to lock.
     * @return TModel
     */
    public function getLock(string|int $key)
    {
        return $this->repository
            ->whereKey($key)
            ->{$this->lockType->value}()
            ->when(
                $this->useTrashed,
                fn($query) => $query->hasMacro('withTrashed') ? $query->withTrashed() : $query
            )
            ->firstOrFail();
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
