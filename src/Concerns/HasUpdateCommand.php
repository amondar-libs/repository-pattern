<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Concerns;

use Amondar\RepositoryPattern\Repository;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * Trait HasUpdateService
 *
 * @template TModel
 * @template TData
 * @template TRepository
 *
 * @author Amondar-SO
 */
trait HasUpdateCommand
{
    /**
     * Updates an existing model with the provided data, performing normalization,
     * triggers for hooks, and handling model relationships.
     *
     * @param  TModel|string|int  $model  The model instance to be updated.
     * @param  array<string, mixed>|TData  $data  The data used to update the model. This can be an array or a Data
     *                                            object.
     * @return TModel Returns the updated model instance, with related data loaded if applicable.
     */
    public function update($model, array|Data $data)
    {
        // 0) Validate for required locking.
        if (is_int($model) || is_string($model)) {
            return $this->transaction->pessimisticUpdate($model, $data);
        }

        // 1) Run any necessary operations before saving.
        /** @noinspection PhpParamsInspection */
        $this->updatingHook($model, $data);

        // 2) Create the model as a record in DB.
        /** @noinspection PhpParamsInspection */
        $model = $this->repository()->update($model, $data);

        // 3) Store related models based on the provided data.
        $this->storeModelRelations($model, $data);

        $this->updatedHook($model, $data);

        return $model->load($this->shouldLoadRelationsAfterChangesApplied());
    }

    /**
     * @return TModel
     */
    protected function pessimisticUpdate(string|int $modelId, array|Data $data)
    {
        $lockedModel = $this->repository()->transaction->getLock($modelId);

        return $this->update($lockedModel, $data);
    }

    /**
     * Defines a method that must be implemented to return a repository instance.
     *
     * @return Repository<TModel, TData>|TRepository
     */
    abstract protected function repository();

    /**
     * Allows modifications or actions to be performed on data before updating a model.
     *
     * @param  TModel|Model  $model
     * @param  array<string, mixed>|TData  &$data  The data array that will be updated. Passed by reference to allow modifications.
     */
    protected function updatingHook(Model $model, array|Data &$data): void
    {
        // Apply some changes to a model before updating.
        // For example, you can fire some events or add some data to a model or outbox table (remember to run in transaction).
    }

    /**
     * Stores model relations.
     *
     * @param  Model|TModel  $model
     * @param  array<string, mixed>|TData  $data  The data array to be updated with model relation information, passed by reference.
     */
    abstract protected function storeModelRelations(Model $model, array|Data &$data): void;

    /**
     * Performs actions or modifications immediately after a model has been fully updated, including all its
     * relationships.
     *
     * @param  Model  $model  The model instance that has been updated.
     * @param  array<string, mixed>|TData  $data  The updated data associated with the model.
     */
    protected function updatedHook(Model $model, array|Data $data): void
    {
        // Apply some actions right after model FULLY updated with all relationships.
    }

    /**
     * Determines which relations should be loaded after changes have been applied.
     *
     * @return array An array of relation names that need to be loaded.
     */
    abstract protected function shouldLoadRelationsAfterChangesApplied(): array;
}
