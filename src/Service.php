<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern;

use Amondar\RepositoryPattern\Contracts\RepositoryContract;
use Amondar\RepositoryPattern\Proxies\HigherOrderServiceTransactionProxy;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Class Service
 *
 * @template TModel of Model
 * @template TData of Data = Data
 * @template TRepository of RepositoryContract<TModel, TData> = RepositoryContract<TModel, TData>
 *
 * @property-read HigherOrderServiceTransactionProxy<TModel, TData, TRepository, static> $transaction
 *
 * @author Amondar-SO
 */
abstract class Service
{
    /**
     * Magic method to handle dynamic property access.
     *
     * @param  string  $name  The name of the property being accessed.
     * @return HigherOrderServiceTransactionProxy<TModel, TData, TRepository, static>
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
     * @return TRepository
     */
    abstract protected function repository();

    /**
     * Stores model relations.
     *
     * @param  TModel  $model
     * @param  array<string, mixed>|TData  $data  The data array to be updated with model relation information, passed by reference.
     */
    protected function storeModelRelations($model, array|Data &$data): void
    {
        //
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
