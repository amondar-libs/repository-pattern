<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern;

use Amondar\ClassAttributes\Support\Attribute;
use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Contracts\Lockable;
use Amondar\RepositoryPattern\Exceptions\RepositoryModelNotFound;
use Amondar\RepositoryPattern\Proxies\HigherOrderQuietlyProxy;
use Amondar\RepositoryPattern\Proxies\HigherOrderRepositoryTransactionProxy;
use Amondar\RepositoryPattern\Proxies\HigherOrderUnlockedProxy;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Class Repository
 *
 * @template TModel
 * @template TData
 *
 * @property-read HigherOrderQuietlyProxy<TModel, TData>|static               $quietly
 * @property-read HigherOrderRepositoryTransactionProxy<TModel, TData>|static $transaction
 * @property-read HigherOrderUnlockedProxy<TModel, TData>|static              $unlocked
 *
 * @implements Contracts\RepositoryContract<TModel, TData>
 */
abstract readonly class Repository implements Contracts\RepositoryContract
{
    /**
     * Model class to use in repository calls.
     *
     * @var class-string<TModel>
     */
    private string $modelClass;

    /**
     * Indicates whether the repository uses lockable models.
     */
    private bool $useLockable;

    /**
     * Initializes the class by loading attributes from the specified model class.
     *
     * @return void
     *
     * @throws RepositoryModelNotFound If no attribute is found for the given class.
     */
    public function __construct()
    {
        $result = Attribute::for(UseModel::class)
            ->ascend()
            ->on(static::class);

        if ($result === []) {
            throw RepositoryModelNotFound::make(static::class);
        }

        $this->modelClass = $result[0]->attribute->modelClass;

        $this->useLockable = is_subclass_of($this->modelClass, Lockable::class);
    }

    /**
     * Dynamically retrieves the value of a property.
     *
     *
     * @return HigherOrderQuietlyProxy|HigherOrderRepositoryTransactionProxy|HigherOrderUnlockedProxy|null
     */
    public function __get(mixed $key)
    {
        if ($key === 'quietly') {
            return $this->makeQuietlyProxy();
        }

        if ($key === 'transaction') {
            return $this->makeTransactionProxy();
        }

        if ($key === 'unlocked' && $this->useLockable) {
            return $this->makeUnlockedProxy();
        }

        throw new RuntimeException("Undefined property: $key");
    }

    /**
     * Dynamically handles method calls on the query builder instance.
     *
     * @param  string  $method  The name of the method being called.
     * @param  array  $parameters  The arguments passed to the method.
     * @return mixed The result of the method call on the query builder.
     */
    public function __call(mixed $method, mixed $parameters)
    {
        return $this->query()->$method(...$parameters);
    }

    /**
     * Prepares and returns a new query builder instance from the model.
     *
     * @return Builder<TModel>
     */
    public function query()
    {
        return $this->makeModel()->query();
    }

    /**
     * Creates and returns a new instance of the model class.
     *
     * @return TModel An instance of the specified model class.
     */
    public function makeModel()
    {
        return new ($this->model())();
    }

    /**
     * Retrieves the class name of the model associated with the repository.
     *
     * @return class-string<TModel> The fully qualified class name of the model.
     */
    public function model(): string
    {
        return $this->modelClass;
    }

    /**
     * Creates and saves a new model instance with the provided data.
     *
     * @param  array<string, mixed>|TData  $data  Input data to populate the model. Can be an array or an instance of
     *                                            the Data class.
     * @return TModel Return a created model instance.
     */
    public function create(array|Data $data)
    {
        return tap($this->fillTheModel($this->makeModel(), $data))->save();
    }

    /**
     * Updates the given model with the provided data.
     *
     * @param  TModel  $model  The model instance to update.
     * @param  array<string, mixed>|TData  $data  The data to update the model with. Can be an associative array
     *                                            or an instance of the Data class which will be converted to an array.
     * @return TModel The updated model instance.
     */
    public function update($model, array|Data $data)
    {
        return tap($this->fillTheModel($model, $data))->save();
    }

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
    public function upsert(array|Data $data, array|string $uniqueBy, ?array $update = null): int
    {
        return $this->query()->upsert(
            $this->normalizeData($data),
            $uniqueBy,
            $update
        );
    }

    /**
     * Persists the supplied model and all of its relationships to the database.
     *
     * @param  TModel  $model  The model instance to be saved along with its relationships.
     * @return bool True if the operation was successful, false otherwise.
     */
    public function push($model): bool
    {
        return $model->push();
    }

    /**
     * @param  TModel|string|int  $model
     */
    public function deleteBy(mixed $model, ?string $key = null): bool|null|int
    {
        return $this->query()
            ->when($key === null, fn($q) => $q->whereKey($model))
            ->when($key !== null, fn($q) => $q->where($key, is_object($model) ? $model->getKey() : $model))
            ->delete();
    }

    /**
     * Processes and normalizes the given data to a consistent format.
     *
     * @param  TData|array<string, mixed>|null  $data  The input data to be normalized.
     * @return array|null The normalized data.
     */
    public function normalizeData(array|Data|null $data): ?array
    {
        return $this->isDataClass($data) ? $data->toArray() : $data;
    }

    /**
     * Creates and returns a new instance of the HigherOrderQuietlyProxy.
     *
     * @return HigherOrderQuietlyProxy<TModel, TData>
     */
    protected function makeQuietlyProxy(): HigherOrderQuietlyProxy
    {
        return new HigherOrderQuietlyProxy($this);
    }

    /**
     * Creates and returns a new transaction proxy instance for managing repository transactions.
     *
     * @return HigherOrderRepositoryTransactionProxy<TModel, TData>
     */
    protected function makeTransactionProxy(): HigherOrderRepositoryTransactionProxy
    {
        return new HigherOrderRepositoryTransactionProxy($this);
    }

    /**
     * Creates and returns a new instance of HigherOrderUnlockedProxy for the current object.
     *
     * @return HigherOrderUnlockedProxy<TModel, TData>
     */
    protected function makeUnlockedProxy(): HigherOrderUnlockedProxy
    {
        return new HigherOrderUnlockedProxy($this);
    }

    /**
     * @param  TModel  $model
     * @param  array<string, mixed>|TData  $data
     * @return TModel
     */
    protected function fillTheModel($model, array|Data $data)
    {
        $normalized = $this->normalizeData($data);

        return $model->fill($normalized);
    }

    /**
     * Determines if the given data is a data class.
     *
     * A data class is identified as an instance of the Data class or a class that exists
     * and has a `toArray` method.
     *
     * @param  array<string, mixed>|null|TData  $data  The data to analyze.
     */
    protected function isDataClass($data): bool
    {
        return is_object($data) && (
            $data instanceof Data
            || method_exists($data, 'toArray')
        );
    }
}
