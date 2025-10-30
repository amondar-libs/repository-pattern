<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Contracts;

use Amondar\RepositoryPattern\Proxies\HigherOrderQuietlyProxy;
use Amondar\RepositoryPattern\Proxies\HigherOrderRepositoryTransactionProxy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * Interface RepositoryContract
 *
 * @template TModel of Model
 * @template TData
 *
 * @see    Builder
 *
 * @mixin Builder<TModel>
 *
 * @property-read HigherOrderQuietlyProxy<TModel, TData>|static               $quietly
 * @property-read HigherOrderRepositoryTransactionProxy<TModel, TData>|static $transaction
 *
 * @author Amondar-SO
 */
interface RepositoryContract
{
    /**
     * Retrieves the class name of the model associated with the repository.
     *
     * @return class-string<TModel> The fully qualified class name of the model.
     */
    public function model(): string;

    /**
     * Creates and returns a new instance of the model class.
     *
     * @return TModel An instance of the specified model class.
     */
    public function makeModel(): Model;

    /**
     * Prepares and returns a new query builder instance from the model.
     *
     * @return Builder<TModel>|TModel Return a query builder instance or model instance (to solve IDE understanding of
     *                                scopes).
     */
    public function query(): Builder;

    /**
     * Creates and saves a new model instance with the provided data.
     *
     * @param  array<string, mixed>|TData  $data  Input data to populate the model. Can be an array or an instance of
     *                                            the Data class.
     * @return TModel|Model Return a created model instance.
     */
    public function create(array|Data $data): Model;

    /**
     * Updates the given model with the provided data.
     *
     * @param  Model  $model  The model instance to update.
     * @param  array<string, mixed>|TData  $data  The data to update the model with. Can be an associative array
     *                                            or an instance of the Data class which will be converted to an array.
     * @return TModel|Model The updated model instance.
     */
    public function update(Model $model, array|Data $data): Model;

    /**
     * Performs an upsert operation on the database, inserting or updating records based on unique constraints.
     *
     * @param  array<string, mixed>|TData  $data  The data to be inserted or updated, either as an array or a Data
     *                                            object.
     * @param  array|string  $uniqueBy  The column(s) used to determine uniqueness for the upsert operation.
     * @param  array|null  $update  The columns to be updated if a duplicate is found; use null for
     *                              default behavior.
     * @return int The number of affected rows.
     */
    public function upsert(array|Data $data, array|string $uniqueBy, ?array $update = null): int;

    /**
     * Persists the supplied model and all of its relationships to the database.
     *
     * @param  Model|TModel  $model  The model instance to be saved along with its relationships.
     * @return bool True if the operation was successful, false otherwise.
     */
    public function push(Model $model): bool;

    /**
     * Processes and normalizes the given data to a consistent format.
     *
     * @param  TData|array<string, mixed>|null  $data  The input data to be normalized.
     * @return array|null The normalized data.
     */
    public function normalizeData(array|Data|null $data): ?array;
}
