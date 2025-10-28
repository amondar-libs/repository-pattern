<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Extensions;

use Amondar\RepositoryPattern\Exceptions\ModelNotSaved;
use Amondar\RepositoryPattern\Repository;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * Trait HasCreateService
 *
 * @template TModel
 * @template TData
 * @template TRepository
 *
 * @author Amondar-SO
 */
trait HasCreateCommand
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
     * Creates a new entity or record based on the provided data.
     *
     * @param  array<string, mixed>|TData  $data  The data used to create the entity or record.
     * @return TModel created entity, record, or result of the creation process.
     */
    public function create(array|Data $data)
    {
        // Normalize the data before saving.
        $data = $this->repository()->normalizeData($data);

        // Run any necessary operations before saving.
        $this->creatingHook($data);

        // Create the model as a record in DB.
        $model = $this->repository()->create($data);

        if ($model->exists) {
            $relations = $this->storeModelRelations($model, $data);

            $this->createdHook($model, $data, $relations);

            return $model->load($this->shouldLoadRelationsAfterChangesApplied());
        }
        throw ModelNotSaved::notCreated($this->repository()->model());
    }

    /**
     * Executes operations on the provided data before creating a new model.
     *
     * @param  array  &$data  The data array to be modified before saving.
     * @return void This method does not return a value.
     */
    protected function creatingHook(array &$data): void
    {
        // Apply some changes to a model before creating.
        // For example, you can add a default value to a field.
    }

    /**
     * Executes actions immediately after the model has been fully created with all relations.
     *
     * @param  TModel|Model  $model
     * @param  array  $data  The primary data of the model that was saved.
     * @param  array  $relations  The data of relationships that was changed in the model. Can be useful for some
     *                            events.
     */
    protected function createdHook(Model $model, array $data, array $relations): void
    {
        // Apply some actions right after model FULLY created with all relationships.
    }
}
