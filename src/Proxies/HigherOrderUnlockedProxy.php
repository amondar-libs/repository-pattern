<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Proxies;

use Amondar\RepositoryPattern\Repository;

/**
 * This class provides a proxy to handle higher-order method calls
 * unlocked on the given repository.
 *
 * @note Require model to implement Lockable interface.
 *
 * @template TModel
 * @template TData
 *
 * @mixin Repository<TModel, TData>
 *
 * @see  Repository
 */
readonly class HigherOrderUnlockedProxy
{
    /**
     * HighOrderQuietlyProxy constructor.
     *
     * @param  Repository<TModel, TData>  $repository
     */
    public function __construct(private Repository $repository)
    {
        //
    }

    /**
     * Proxy a method call onto the collection items.
     *
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        /** @var \Amondar\RepositoryPattern\Contracts\Lockable $modelClass */
        $modelClass = $this->repository->makeModel();

        return $modelClass::unlocked(fn() => $this->repository->{$method}(...$parameters));
    }
}
