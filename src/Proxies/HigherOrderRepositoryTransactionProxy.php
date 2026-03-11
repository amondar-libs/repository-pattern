<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Enums\LockType;
use Amondar\RepositoryPattern\Helpers\Current;
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
 *
 * @immutable
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
        private int $transactionLevel = 0,
        private ?Closure $lockQueryCallback = null,
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
            return $this->makeWithOptions(beQuiet: true);
        }

        if ($name === 'withTrashed') {
            return $this->makeWithOptions(useTrashed: true);
        }

        throw new RuntimeException("Undefined property: $name");
    }

    /**
     * Creates a new instance with the provided options.
     *
     * @param  bool|Current  $beQuiet  Indicates whether to suppress output or use the current value.
     * @param  bool|Current  $useTrashed  Specifies whether to include trashed items or use the current value.
     * @param  LockType|Current  $lockType  Defines the locking type or uses the current value.
     * @param  int|Current  $transactionLevel  Sets the transaction isolation level or uses the current value.
     * @param  Closure|null|Current  $lockQueryCallback  Callback to modify the lock query or uses the current value.
     * @return static A new instance of the class with the configured options.
     */
    public function makeWithOptions(
        bool|Current $beQuiet = new Current,
        bool|Current $useTrashed = new Current,
        LockType|Current $lockType = new Current,
        int|Current $transactionLevel = new Current,
        Closure|null|Current $lockQueryCallback = new Current
    ): static {
        return new static(
            $this->repository,
            $beQuiet instanceof Current ? $this->beQuiet : $beQuiet,
            $useTrashed instanceof Current ? $this->useTrashed : $useTrashed,
            $lockType instanceof Current ? $this->lockType : $lockType,
            $transactionLevel instanceof Current ? $this->transactionLevel : $transactionLevel,
            $lockQueryCallback instanceof Current ? $this->lockQueryCallback : $lockQueryCallback
        );
    }

    /**
     * Sets the transaction level and returns a new instance of the class.
     *
     * @param  int  $level  The transaction level to set.
     * @return static A new instance of the class with the specified transaction level.
     */
    public function onLevel(int $level): static
    {
        return $this->makeWithOptions(transactionLevel: $level);
    }

    /**
     * Creates a new instance of the class with a shared lock type.
     *
     * @return static A new instance configured with LockType::SHARED.
     */
    public function asShared(): static
    {
        return $this->makeWithOptions(lockType: LockType::shared);
    }

    /**
     * Sets a callback to modify the query before locking.
     *
     * @template Builder
     *
     * @param  Closure(Builder): Builder  $callback
     */
    public function withQuery(Closure $callback): static
    {
        return $this->makeWithOptions(lockQueryCallback: $callback);
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
        $query = $this->repository
            ->query()
            ->whereKey($key)
            ->{$this->lockType->value}()
            ->when(
                $this->useTrashed,
                fn($query) => $query->hasMacro('withTrashed') ? $query->withTrashed() : $query
            );

        if ($this->lockQueryCallback !== null) {
            $query = ($this->lockQueryCallback)($query);
        }

        return $query->firstOrFail();
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
