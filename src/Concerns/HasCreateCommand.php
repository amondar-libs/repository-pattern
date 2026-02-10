<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Concerns;

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
     * Creates a new entity or record based on the provided data.
     *
     * @param  array<string, mixed>|TData  $data  The data used to create the entity or record.
     * @return TModel created entity, record, or result of the creation process.
     */
    public function create(array|Data $data)
    {
        // 1) Run any necessary operations before saving.
        $this->creatingHook($data);

        // 2) Create the model as a record in DB.
        $model = $this->repository()->create($data);

        if ($model->exists) {
            $this->storeModelRelations($model, $data);

            $this->createdHook($model, $data);

            return $model->load($this->shouldLoadRelationsAfterChangesApplied());
        }

        throw ModelNotSaved::notCreated($this->repository()->model());
    }

    /**
     * Executes operations on the provided data before creating a new model.
     *
     * @param  array<string, mixed>|TData  &$data  The data array to be modified before saving.
     * @return void This method does not return a value.
     */
    protected function creatingHook(array|Data &$data): void
    {
        // Apply some changes to a model before creating.
        // For example, you can add a default value to a field.
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
     * @param  Model|TModel  $model
     * @param  array<string, mixed>|TData  $data  The data array to be updated with model relation information, passed by reference.
     */
    abstract protected function storeModelRelations(Model $model, array|Data &$data): void;

    /**
     * Executes actions immediately after the model has been fully created with all relations.
     *
     * @param  TModel|Model  $model
     * @param  array<string, mixed>|TData  $data  The primary data of the model that was saved.
     */
    protected function createdHook(Model $model, array|Data $data): void
    {
        // Apply some actions right after model FULLY created with all relationships.
    }

    /**
     * Determines which relations should be loaded after changes have been applied.
     *
     * @return array An array of relation names that need to be loaded.
     */
    abstract protected function shouldLoadRelationsAfterChangesApplied(): array;
}
