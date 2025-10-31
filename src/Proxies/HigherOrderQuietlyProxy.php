<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Repository;

/**
 * This class provides a proxy to handle higher-order method calls
 * quietly on the given repository.
 *
 * @template TModel
 * @template TData
 *
 * @mixin Repository<TModel, TData>
 *
 * @see Repository
 */
readonly class HigherOrderQuietlyProxy
{
    /**
     * HighOrderQuietlyProxy constructor.
     */
    public function __construct(private Repository $repository)
    {
        //
    }

    /**
     * Proxy a method call onto the collection items.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        $modelClass = $this->repository->model();

        return $modelClass::withoutEvents(fn() => $this->repository->{$method}(...$parameters));
    }
}
