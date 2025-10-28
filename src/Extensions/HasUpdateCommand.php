<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Extensions;

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
     * Defines a method that must be implemented to return a repository instance.
     *
     * @return Repository<TModel, TData>|TRepository
     */
    abstract protected function repository();

    /**
     * Determines which relations should be loaded after changes have been applied.
     *
     * @return array An array of relation names that need to be loaded.
     */
    abstract protected function shouldLoadRelationsAfterChangesApplied(): array;

    /**
     * Stores model relations.
     *
     * @param  Model|TModel  $model
     * @param  array  $data  The data array to be updated with model relation information, passed by reference.
     * @return array Returns the array with changes applied to model relations.
     */
    abstract public function storeModelRelations(Model $model, array &$data): array;

    /**
     * Updates an existing model with the provided data, performing normalization,
     * triggers for hooks, and handling model relationships.
     *
     * @param  TModel  $model  The model instance to be updated.
     * @param  array<string, mixed>|TData  $data  The data used to update the model. This can be an array or a Data
     *                                            object.
     * @return TModel Returns the updated model instance, with related data loaded if applicable.
     */
    public function update($model, array|Data $data)
    {
        // Normalize the data before saving.
        $data = $this->repository()->normalizeData($data);

        // Run any necessary operations before saving.
        $this->updatingHook($model, $data);

        // Create the model as a record in DB.
        $model = $this->repository()->update($model, $data);

        $relations = $this->storeModelRelations($model, $data);

        $this->updatedHook($model, $data, $relations);

        return $model->load($this->shouldLoadRelationsAfterChangesApplied());
    }

    /**
     * Allows modifications or actions to be performed on data before updating a model.
     *
     * @param  TModel|Model  $model
     * @param  array  &$data  The data array that will be updated. Passed by reference to allow modifications.
     */
    protected function updatingHook(Model $model, array &$data): void
    {
        // Apply some changes to a model before updating.
        // For example, you can fire some events or add some data to a model or outbox table (remember to run in transaction).
    }

    /**
     * Performs actions or modifications immediately after a model has been fully updated, including all its
     * relationships.
     *
     * @param  Model  $model  The model instance that has been updated.
     * @param  array  $data  The updated data associated with the model.
     * @param  array  $relations  The relationships associated with the model that were updated.
     */
    protected function updatedHook(Model $model, array $data, array $relations): void
    {
        // Apply some actions right after model FULLY updated with all relationships.
    }
}
