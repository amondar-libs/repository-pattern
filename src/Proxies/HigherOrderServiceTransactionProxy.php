<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Service;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Class HigherOrderDBTransactionProxy
 *
 * @template TModel
 * @template TData
 *
 * @mixin Service<TModel, TData>
 *
 * @see Service
 */
readonly class HigherOrderServiceTransactionProxy
{
    /**
     * HigherOrderDBTransactionProxy constructor.
     *
     * @param  Service<TModel, TData>  $service
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
