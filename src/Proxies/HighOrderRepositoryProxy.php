<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Contracts\Lockable;
use Amondar\RepositoryPattern\Contracts\RepositoryContract;
use Amondar\RepositoryPattern\Enums\Lock;
use Amondar\RepositoryPattern\Exceptions\OptimisticLockException;
use Amondar\RepositoryPattern\Helpers\Current;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Collects immutable modifiers and applies them to a repository operation.
 *
 * @template TModel of Model
 * @template TData of Data = Data
 * @template TRepository of RepositoryContract<TModel, TData> = RepositoryContract<TModel, TData>
 *
 * @mixin TRepository
 *
 * @property-read HighOrderRepositoryProxy<TModel, TData, TRepository> $quietly
 * @property-read HighOrderRepositoryProxy<TModel, TData, TRepository> $transaction
 * @property-read HighOrderRepositoryProxy<TModel, TData, TRepository> $unlocked
 * @property-read HighOrderRepositoryProxy<TModel, TData, TRepository> $unlock
 * @property-read HighOrderRepositoryProxy<TModel, TData, TRepository> $withTrashed
 *
 * @immutable
 */
readonly class HighOrderRepositoryProxy
{
    /**
     * @param  TRepository  $repository
     * @param  (Closure(Builder<TModel>): Builder<TModel>)|null  $lockQueryCallback
     */
    public function __construct(
        private RepositoryContract $repository,
        private bool $beQuiet = false,
        private bool $useTrashed = false,
        private bool $useTransaction = false,
        private bool $beUnlocked = false,
        private Lock $lockType = Lock::forUpdate,
        private int $transactionLevel = 0,
        private ?Closure $lockQueryCallback = null,
    ) {
        if ($this->beUnlocked && ! is_subclass_of($this->repository->model(), Lockable::class)) {
            throw OptimisticLockException::interfaceRequired($this->repository->model());
        }
    }

    /**
     * Applies the selected modifiers to a single repository method call.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return mixed
     *
     * @throws Throwable
     */
    public function __call(string $method, array $parameters)
    {
        if ($this->useTrashed) {
            throw new RuntimeException("`->withTrashed` modifier can't be used with direct repository methods");
        }

        return $this->run(
            fn($repository) => $repository->{$method}(...$parameters)
        );
    }

    /**
     * Adds a modifier without changing the current proxy.
     *
     * @return static<TModel, TData, TRepository>
     */
    public function __get(string $name): static
    {
        return match ($name) {
            'quietly'     => $this->makeWithOptions(beQuiet: true),
            'transaction' => $this->makeWithOptions(useTransaction: true),
            'unlocked', 'unlock' => $this->makeWithOptions(beUnlocked: true),
            'withTrashed' => $this->makeWithOptions(useTrashed: true),
            default       => throw new RuntimeException("Undefined property: $name"),
        };
    }

    /**
     * Runs a callback with the real repository under the selected modifiers.
     * A transaction is opened only when the transaction modifier is enabled.
     *
     * @template TReturn
     *
     * @param  Closure(TRepository): TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    public function run(Closure $callback, int $attempts = 1)
    {
        return $this->execute(
            fn() => $callback($this->repository),
            $this->useTransaction,
            $attempts
        );
    }

    /**
     * Creates a new proxy, preserving every option not explicitly supplied.
     *
     * @param  (Closure(Builder<TModel>): Builder<TModel>)|null|Current  $lockQueryCallback
     * @return static<TModel, TData, TRepository>
     */
    public function makeWithOptions(
        bool|Current $beQuiet = new Current,
        bool|Current $useTrashed = new Current,
        bool|Current $useTransaction = new Current,
        bool|Current $beUnlocked = new Current,
        Lock|Current $lockType = new Current,
        int|Current $transactionLevel = new Current,
        Closure|null|Current $lockQueryCallback = new Current,
    ): static {
        return new static(
            repository: $this->repository,
            beQuiet: $beQuiet instanceof Current ? $this->beQuiet : $beQuiet,
            useTrashed: $useTrashed instanceof Current ? $this->useTrashed : $useTrashed,
            useTransaction: $useTransaction instanceof Current ? $this->useTransaction : $useTransaction,
            beUnlocked: $beUnlocked instanceof Current ? $this->beUnlocked : $beUnlocked,
            lockType: $lockType instanceof Current ? $this->lockType : $lockType,
            transactionLevel: $transactionLevel instanceof Current ? $this->transactionLevel : $transactionLevel,
            lockQueryCallback: $lockQueryCallback instanceof Current ? $this->lockQueryCallback : $lockQueryCallback,
        );
    }

    /**
     * Sets the existing transaction depth at which forUpdate opens a transaction.
     *
     * @return static<TModel, TData, TRepository>
     */
    public function onLevel(int $level): static
    {
        return $this->makeWithOptions(transactionLevel: $level);
    }

    /**
     * @return static<TModel, TData, TRepository>
     */
    public function asShared(): static
    {
        return $this->makeWithOptions(lockType: Lock::shared);
    }

    /**
     * @param  Closure(Builder<TModel>): Builder<TModel>  $callback
     * @return static<TModel, TData, TRepository>
     */
    public function withQuery(Closure $callback): static
    {
        return $this->makeWithOptions(lockQueryCallback: $callback);
    }

    /**
     * Locks a record and runs a callback under the selected modifiers.
     * Preserves the transaction proxy's depth rule independently of run().
     *
     * @template TReturn
     *
     * @param  Closure(TModel, TRepository): TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    public function forUpdate(string|int $key, Closure $callback)
    {
        return $this->execute(
            fn() => $callback($this->getLock($key), $this->repository),
            DB::transactionLevel() === $this->transactionLevel
        );
    }

    /**
     * Fetches a locked record using the configured query options.
     * The caller is responsible for the surrounding transaction when called directly.
     *
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

    /**
     * Keeps event and optimistic-lock scopes inside each transaction attempt.
     * Both scopes restore their previous state when the operation throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    private function execute(Closure $callback, bool $useTransaction, int $attempts = 1)
    {
        $operation = function () use ($callback) {
            $modelClass = $this->repository->model();
            $operation = $callback;

            if ($this->beUnlocked) {
                // Boot the model before entering the temporary unlocked scope.
                $this->repository->makeModel();
                $operation = fn() => $modelClass::unlocked($callback);
            }

            return $this->beQuiet
                ? $modelClass::withoutEvents($operation)
                : $operation();
        };

        return $useTransaction
            ? DB::transaction($operation, $attempts)
            : $operation();
    }
}
