<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Contracts\RepositoryContract;
use Amondar\RepositoryPattern\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Class HigherOrderDBTransactionProxy
 *
 * @template TModel of Model
 * @template TData of Data = Data
 * @template TRepository of RepositoryContract<TModel, TData> = RepositoryContract<TModel, TData>
 * @template TService of Service<TModel, TData, TRepository> = Service<TModel, TData, TRepository>
 *
 * @mixin TService
 *
 * @see Service
 */
readonly class HigherOrderServiceTransactionProxy
{
    /**
     * HigherOrderDBTransactionProxy constructor.
     *
     * @param  TService  $service
     */
    public function __construct(private Service $service, private int $transactionLevel = 0)
    {
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
        if (DB::transactionLevel() === $this->transactionLevel) {
            return DB::transaction(fn() => $this->service->{$method}(...$parameters));
        }

        return $this->service->{$method}(...$parameters);
    }

    /**
     * Sets the transaction level and returns a new instance of the class.
     *
     * @param  int  $level  The transaction level to set.
     * @return static A new instance of the class with the specified transaction level.
     */
    public function onLevel(int $level): static
    {
        return new static($this->service, $level);
    }
}
