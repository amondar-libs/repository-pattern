<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Repository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Class HigherOrderDBTransactionProxy
 *
 * @template TModel
 * @template TData
 *
 * @mixin \Amondar\RepositoryPattern\Repository<TModel, TData>
 *
 * @see Repository
 */
readonly class HigherOrderRepositoryTransactionProxy
{
    /**
     * HigherOrderDBTransactionProxy constructor.
     *
     * @param  Repository<TModel, TData>  $object
     */
    public function __construct(private Repository $object)
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
            return DB::transaction(fn() => $this->object->$name);
        }

        throw new \RuntimeException("Undefined property: $name");
    }
}
