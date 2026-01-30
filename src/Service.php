<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern;

use Amondar\RepositoryPattern\Proxies\HigherOrderServiceTransactionProxy;
use RuntimeException;

/**
 * Class Service
 *
 * @template TModel
 * @template TData
 * @template TRepository
 *
 * @property-read HigherOrderServiceTransactionProxy<TModel, TData>|static $transaction
 *
 * @author Amondar-SO
 */
abstract class Service
{
    /**
     * Magic method to handle dynamic property access.
     *
     * @param  string  $name  The name of the property being accessed.
     * @return HigherOrderServiceTransactionProxy|null
     */
    public function __get(string $name)
    {
        if ($name === 'transaction') {
            return new HigherOrderServiceTransactionProxy($this);
        }

        throw new RuntimeException("Undefined property: $name");
    }

    /**
     * Defines a method that must be implemented to return a repository instance.
     *
     * @return Repository<TModel, TData>|TRepository
     */
    abstract protected function repository();

    /**
     * Stores model relations.
     *
     * @param  TModel  $model
     * @param  array  $data  The data array to be updated with model relation information, passed by reference.
     * @return array Returns the array with changes applied to model relations.
     */
    protected function storeModelRelations($model, array &$data): array
    {
        return [];
    }

    /**
     * Determines which relations should be loaded after changes have been applied.
     *
     * @return array An array of relation names that need to be loaded.
     */
    protected function shouldLoadRelationsAfterChangesApplied(): array
    {
        return [];
    }
}
