<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern;

use Amondar\ClassAttributes\Support\Attribute;
use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Contracts\Lockable;
use Amondar\RepositoryPattern\Enums\Lock;
use Amondar\RepositoryPattern\Exceptions\RepositoryModelNotFound;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryProxy;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Class Repository
 *
 * @template TModel of Model
 * @template TData of Data = Data
 *
 * @property-read HighOrderRepositoryCacheProxy<TModel, TData, static> $cache
 * @property-read HighOrderRepositoryProxy<TModel, TData, static> $quietly
 * @property-read HighOrderRepositoryProxy<TModel, TData, static> $transaction
 * @property-read HighOrderRepositoryProxy<TModel, TData, static> $unlocked
 * @property-read HighOrderRepositoryProxy<TModel, TData, static> $unlock
 *
 * @mixin Builder<TModel>
 *
 * @implements Contracts\RepositoryContract<TModel, TData>
 */
abstract readonly class Repository implements Contracts\RepositoryContract
{
    /** @var HighOrderRepositoryCacheProxy<TModel, TData, static> */
    private HighOrderRepositoryCacheProxy $cacheProxy;

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
    public function __construct(?CacheRepository $cacheRepository = null)
    {
        $result = Attribute::for(UseModel::class)
            ->ascend()
            ->on(static::class);

        if ($result === []) {
            throw RepositoryModelNotFound::make(static::class);
        }

        $this->modelClass = $result[0]->attribute->modelClass;

        $this->useLockable = is_subclass_of($this->modelClass, Lockable::class);
        $this->cacheProxy = new HighOrderRepositoryCacheProxy($this, $cacheRepository);

        // Detect missing scope configuration before any write can persist data.
        if ($this->usesCache()) {
            if ((new ReflectionMethod($this, 'getModelBaseScope'))->getDeclaringClass()->getName() === self::class) {
                throw new RuntimeException(static::class . ' must implement getModelBaseScope() when repository caching is enabled.');
            }

            $this->cacheProxy->getStore();
        }
    }

    /**
     * Gives each repository clone an independent cache dependency stack.
     * Subclasses overriding this method must call parent::__clone().
     */
    public function __clone()
    {
        $this->cacheProxy = $this->cacheProxy->forRepository($this);
    }

    /**
     * Dynamically retrieves the value of a property.
     *
     *
     * @return HighOrderRepositoryProxy<TModel, TData, static>|HighOrderRepositoryCacheProxy<TModel, TData, static>
     */
    public function __get(mixed $key): HighOrderRepositoryProxy|HighOrderRepositoryCacheProxy
    {
        return match ($key) {
            'cache'       => $this->cacheProxy,
            'quietly'     => new HighOrderRepositoryProxy($this, beQuiet: true),
            'transaction' => new HighOrderRepositoryProxy($this, useTransaction: true),
            'unlocked', 'unlock' => $this->useLockable
                ? new HighOrderRepositoryProxy($this, beUnlocked: true)
                : throw new RuntimeException("Undefined property: $key"),
            default => throw new RuntimeException("Undefined property: $key"),
        };
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

    public function usesCache(): bool
    {
        return false;
    }

    /**
     * Resolves the base and additional dependency scopes for a model.
     *
     * @param  TModel  $model
     * @return list<string>
     */
    public function getCacheScopesForModel(Model $model): array
    {
        $scopes = [
            $this->getModelBaseScope($model),
            ...$this->getModelAdditionalScopes($model),
        ];

        foreach ($scopes as $scope) {
            if ( ! is_string($scope) || $scope === '') {
                throw new RuntimeException('Model cache scopes must be non-empty strings.');
            }
        }

        return array_values(array_unique($scopes));
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
        $model = $this->fillTheModel($this->makeModel(), $data);

        if ($model->save() && $this->usesCache()) {
            $this->invalidateCacheAfterCommit(
                $model->getConnection(),
                $this->getCacheScopesForModel($model)
            );
        }

        return $model;
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
        $previousScopes = [];

        if ($this->usesCache()) {
            // Capture both the caller's original scope and the current persisted
            // scope: another write may have moved a stale model to a new owner.
            $previous = clone $model;
            $previous->setRawAttributes($model->getRawOriginal(), true);
            $previous->setRelations([]);
            $previousScopes = $this->getCacheScopesForModel($previous);

            if ($persisted = $model->fresh()) {
                $previousScopes = [...$previousScopes, ...$this->getCacheScopesForModel($persisted)];
            }
        }

        $this->fillTheModel($model, $data);

        if ($model->save() && $this->usesCache()) {
            $this->invalidateCacheAfterCommit(
                $model->getConnection(),
                [...$previousScopes, ...$this->getCacheScopesForModel($model)]
            );
        }

        return $model;
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
        $values = $this->normalizeData($data);
        $query = $this->query();
        $affected = $query->upsert($values, $uniqueBy, $update);

        if ($values !== []) {
            // Upsert does not return models or their previous scope fields.
            // Invalidate the entire repository instead of guessing dependencies.
            $this->invalidateCacheAfterCommit($query->getConnection());
        }

        return $affected;
    }

    /**
     * Persists the supplied model and all of its relationships to the database.
     *
     * @param  TModel  $model  The model instance to be saved along with its relationships.
     * @return bool True if the operation was successful, false otherwise.
     */
    public function push($model): bool
    {
        try {
            return $model->push();
        } finally {
            // Push can persist the root before a related save fails or throws.
            // Flush conservatively; a surrounding rollback discards the callback.
            $this->invalidateCacheAfterCommit($model->getConnection());
        }
    }

    /**
     * Deletes a record or multiple records from the database based on the given model and optional key.
     *
     * @param  TModel|string|int  $model  The model instance, primary key, or array of primary keys to be deleted.
     * @param  string|null  $key  An optional column name to be used for the deletion condition. Defaults to the
     *                            primary key.
     */
    public function deleteBy(mixed $model, ?string $key = null): int
    {
        $query = $this
            ->query()
            ->when($key === null, fn($q) => $q->whereKey($model))
            ->when($key !== null, fn($q) => $q->where($key, $model instanceof Model ? $model->getAttribute($key) : $model));

        $scopes = [];

        if ($this->usesCache()) {
            // Query deletes bypass individual model events. Capture scope data
            // before the affected records disappear.
            foreach ((clone $query)->cursor() as $record) {
                $scopes = array_values(array_unique([
                    ...$scopes,
                    ...$this->getCacheScopesForModel($record),
                ]));
            }
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            $this->invalidateCacheAfterCommit(
                $query->getConnection(),
                $scopes === [] ? null : $scopes
            );
        }

        return $deleted;
    }

    /**
     * Find record by primary key.
     *
     * @return TModel|null
     */
    public function findById(string $modelId, array $select = ['*'], ?Lock $lock = null)
    {
        return $this
            ->query()
            ->select($select)
            ->whereKey($modelId)
            ->when($lock === Lock::forUpdate, fn(Builder $query) => $query->lockForUpdate())
            ->when($lock === Lock::shared, fn(Builder $query) => $query->sharedLock())
            ->first();
    }

    /**
     * Find record by the given field.
     *
     * @return TModel|null
     */
    public function findBy(string $field, string|int|float|null|bool $value, array $select = ['*'], ?Lock $lock = null)
    {
        return $this
            ->query()
            ->select($select)
            ->where($field, $value)
            ->when($lock === Lock::forUpdate, fn(Builder $query) => $query->lockForUpdate())
            ->when($lock === Lock::shared, fn(Builder $query) => $query->sharedLock())
            ->first();
    }

    /**
     * Determines if a record exists in the database by a specified field and value.
     */
    public function existsBy(string $field, string|int|bool|null $value, string|int|null $primaryKey = null): bool
    {
        return $this
            ->query()
            ->where($field, $value)
            ->when($primaryKey !== null, fn(Builder $query) => $query->whereKey($primaryKey))
            ->exists();
    }

    /**
     * Processes and normalizes the given data to a consistent format.
     *
     * @param  TData|array<string, mixed>|null  $data  The input data to be normalized.
     */
    public function normalizeData(array|Data|null $data): ?array
    {
        return $this->isDataClass($data) ? $data->toArray() : $data;
    }

    /**
     * Invalidates dependency scopes after the outermost successful commit.
     * A null scope list flushes all entries tagged with this repository class.
     *
     * @param  list<string>|null  $scopes
     */
    protected function invalidateCacheAfterCommit(Connection $connection, ?array $scopes = null): void
    {
        if ( ! $this->usesCache() || $scopes === []) {
            return;
        }

        $scopes = $scopes === null ? null : array_values(array_unique($scopes));
        $invalidate = $scopes === null
            ? fn() => $this->cacheProxy->forget()
            : fn() => $this->cacheProxy->invalidate(...$scopes);

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($invalidate);
        } else {
            $invalidate();
        }
    }

    /**
     * @param  TModel  $model
     */
    protected function getModelBaseScope(Model $model): string
    {
        throw new RuntimeException(static::class . ' must implement getModelBaseScope() to cache model results.');
    }

    /**
     * @param  TModel  $model
     * @return list<string>
     */
    protected function getModelAdditionalScopes(Model $model): array
    {
        return [];
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
