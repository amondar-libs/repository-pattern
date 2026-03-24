<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Contracts;

use Amondar\RepositoryPattern\Proxies\HigherOrderQuietlyProxy;
use Amondar\RepositoryPattern\Proxies\HigherOrderRepositoryTransactionProxy;
use Amondar\RepositoryPattern\Proxies\HigherOrderUnlockedProxy;
use Illuminate\Database\Eloquent\Builder;
use Spatie\LaravelData\Data;

/**
 * Interface RepositoryContract
 *
 * @template TModel
 * @template TData
 *
 * @see    Builder
 *
 * @mixin Builder<TModel>
 *
 * @property-read HigherOrderQuietlyProxy<TModel, TData>|static               $quietly
 * @property-read HigherOrderRepositoryTransactionProxy<TModel, TData>|static $transaction
 * @property-read HigherOrderUnlockedProxy<TModel, TData>|static              $unlocked
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
    public function makeModel();

    /**
     * Prepares and returns a new query builder instance from the model.
     *
     * @return Builder<TModel>
     */
    public function query();

    /**
     * Creates and saves a new model instance with the provided data.
     *
     * @param  array<string, mixed>|TData  $data  Input data to populate the model. Can be an array or an instance of
     *                                            the Data class.
     * @return TModel Return a created model instance.
     */
    public function create(array|Data $data);

    /**
     * Updates the given model with the provided data.
     *
     * @param  TModel  $model  The model instance to update.
     * @param  array<string, mixed>|TData  $data  The data to update the model with. Can be an associative array
     *                                            or an instance of the Data class which will be converted to an array.
     * @return TModel The updated model instance.
     */
    public function update($model, array|Data $data);

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
     * @param  TModel  $model  The model instance to be saved along with its relationships.
     * @return bool True if the operation was successful, false otherwise.
     */
    public function push($model): bool;

    /**
     * Deletes a record or multiple records from the database based on the given model and optional key.
     *
     * @param  TModel|string|int  $model  The model instance, primary key, or array of primary keys to be deleted.
     * @param  string|null  $key  An optional column name to be used for the deletion condition. Defaults to the primary key.
     */
    public function deleteBy(mixed $model, ?string $key = null): int;

    /**
     * Processes and normalizes the given data to a consistent format.
     *
     * @param  TData|array<string, mixed>|null  $data  The input data to be normalized.
     * @return array|null The normalized data.
     */
    public function normalizeData(array|Data|null $data): ?array;
}
