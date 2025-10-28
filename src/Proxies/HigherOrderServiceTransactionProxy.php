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
 * @mixin \Amondar\RepositoryPattern\Service<TModel, TData>
 *
 * @see Service
 */
readonly class HigherOrderServiceTransactionProxy
{
    /**
     * HigherOrderDBTransactionProxy constructor.
     *
     * @param  Service<TModel, TData>  $object
     */
    public function __construct(private Service $object)
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
        return DB::transaction(fn() => $this->object->{$method}(...$parameters));
    }
}
