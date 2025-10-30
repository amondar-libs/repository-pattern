<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern;

use Amondar\ClassAttributes\Libraries\Attributes;
use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Exceptions\RepositoryModelNotFound;
use Amondar\RepositoryPattern\Proxies\HigherOrderQuietlyProxy;
use Amondar\RepositoryPattern\Proxies\HigherOrderRepositoryTransactionProxy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionException;
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
     * Initializes the class by loading attributes from the specified model class.
     *
     * @return void
     *
     * @throws RepositoryModelNotFound|ReflectionException If no attribute is found for the given class.
     */
    public function __construct()
    {
        $attribute = (new Attributes(static::class))->loadFromClass(UseModel::class, ascend: true);

        if (is_null($attribute)) {
            throw RepositoryModelNotFound::make(static::class);
        }

        $this->modelClass = $attribute->modelClass;
    }

    /**
     * Dynamically retrieves the value of a property.
     *
     * @param  string  $name  The name of the property to access.
     * @return HigherOrderQuietlyProxy|HigherOrderRepositoryTransactionProxy|null
     */
    public function __get(mixed $key)
    {
        if ($key === 'quietly') {
            return new HigherOrderQuietlyProxy($this);
        }

        if ($key === 'transaction') {
            return new HigherOrderRepositoryTransactionProxy($this);
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
     * Retrieves the class name of the model associated with the repository.
     *
     * @return class-string<TModel> The fully qualified class name of the model.
     */
    final public function model(): string
    {
        return $this->modelClass;
    }

    /**
     * Creates and returns a new instance of the model class.
     *
     * @return TModel|Model An instance of the specified model class.
     */
    final public function makeModel(): Model
    {
        return new ($this->model())();
    }

    /**
     * Prepares and returns a new query builder instance from the model.
     *
     * @return Builder<TModel>|TModel Return a query builder instance or model instance (to solve IDE understanding of
     *                                scopes).
     */
    final public function query(): Builder
    {
        return $this->makeModel()->query();
    }

    /**
     * Creates and saves a new model instance with the provided data.
     *
     * @param  array<string, mixed>|TData  $data  Input data to populate the model. Can be an array or an instance of
     *                                            the Data class.
     * @return TModel|Model Return a created model instance.
     */
    final public function create(array|Data $data): Model
    {
        return tap($this->makeModel()->fill(
            $this->normalizeData($data)
        ))->save();
    }

    /**
     * Updates the given model with the provided data.
     *
     * @param  Model  $model  The model instance to update.
     * @param  array<string, mixed>|TData  $data  The data to update the model with. Can be an associative array
     *                                            or an instance of the Data class which will be converted to an array.
     * @return TModel|Model The updated model instance.
     */
    final public function update(Model $model, array|Data $data): Model
    {
        return tap($model->forceFill($this->normalizeData($data)))->save();
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
    final public function upsert(array|Data $data, array|string $uniqueBy, ?array $update = null): int
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
     * @param  Model|TModel  $model  The model instance to be saved along with its relationships.
     * @return bool True if the operation was successful, false otherwise.
     */
    final public function push(Model $model): bool
    {
        return $model->push();
    }

    /**
     * Processes and normalizes the given data to a consistent format.
     *
     * @param  TData|array<string, mixed>|null  $data  The input data to be normalized.
     * @return array|null The normalized data.
     */
    final public function normalizeData(array|Data|null $data): ?array
    {
        if ($data instanceof Data) {
            return $data->toArray();
        }

        return $data;

    }
}
