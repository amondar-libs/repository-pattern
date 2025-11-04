<?php

declare( strict_types = 1 );

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Enums\LockType;
use Amondar\RepositoryPattern\Repository;
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
     * @param Repository<TModel, TData>                 $repository
     * @param bool                                      $useTrashed
     * @param \Amondar\RepositoryPattern\Enums\LockType $lockType
     * @param int                                       $transactionLevel
     */
    public function __construct(
        private Repository $repository,
        private bool $useTrashed = false,
        private LockType $lockType = LockType::FOR_UPDATE,
        private int $transactionLevel = 0
    )
    {
        //
    }

    /**
     * Proxy a method call onto the collection items.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     *
     * @throws Throwable
     */
    public function __call(string $method, array $parameters)
    {
        return DB::transaction(fn() => $this->repository->{$method}(...$parameters));
    }

    /**
     * Magic __get method.
     *
     * @param string $name The name of the property being accessed.
     *
     * @return mixed Returns the value of the property, or executes a transaction for specific properties.
     *
     * @throws Throwable
     */
    public function __get(string $name)
    {
        if ( $name === 'quietly' ) {
            return DB::transaction(fn() => $this->repository->$name);
        }

        if ( $name === 'withTrashed' ) {
            return new static($this->repository, true);
        }

        throw new RuntimeException("Undefined property: $name");
    }

    /**
     * Sets the transaction level and returns a new instance of the class.
     *
     * @param int $level The transaction level to set.
     *
     * @return static A new instance of the class with the specified transaction level.
     */
    public function onLevel(int $level) : static
    {
        return new static($this->repository, $this->useTrashed, $this->lockType, $level);
    }

    /**
     * Creates a new instance of the class with a shared lock type.
     *
     * @return static A new instance configured with LockType::SHARED.
     */
    public function asShared() : static
    {
        return new static($this->repository, $this->useTrashed, LockType::SHARED, $this->transactionLevel);
    }

    /**
     * Executes a transactional operation on a locked database record identified by the given key.
     *
     * @template TResult
     *
     * @param string|int                                           $key          The primary key of the record to lock
     *                                                                           for update.
     * @param callable(TModel, Repository<TModel, TData>): TResult $callback     A callback function that processes the
     *                                                                           locked record and repository.
     *
     * @return TResult
     * @throws \Throwable
     */
    public function forUpdate(string|int $key, callable $callback)
    {
        if ( DB::transactionLevel() === $this->transactionLevel ) {
            return DB::transaction(function () use ($key, $callback) {
                return $callback($this->getLock($key), $this->repository);
            });
        }

        return $callback($this->getLock($key), $this->repository);
    }

    /**
     * Executes the provided callback within a "for update" lock on the specified model.
     *
     * @param string|int $key The key identifying the model to lock.
     *
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
    public function shouldUseTrashed() : bool
    {
        return $this->useTrashed;
    }

}
