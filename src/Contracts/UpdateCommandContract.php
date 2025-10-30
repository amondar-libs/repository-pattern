<?php

namespace Amondar\RepositoryPattern\Contracts;

use Spatie\LaravelData\Data;

/**
 * Interface UpdateCommandContract
 *
 * @template TModel
 * @template TData
 *
 * @author Amondar-SO
 */
interface UpdateCommandContract
{

    /**
     * Updates an existing model with the provided data, performing normalization,
     * triggers for hooks, and handling model relationships.
     *
     * @param  TModel  $model  The model instance to be updated.
     * @param  array<string, mixed>|TData  $data  The data used to update the model. This can be an array or a Data
     *                                            object.
     * @return TModel Returns the updated model instance, with related data loaded if applicable.
     */
    public function update($model, array|Data $data);

}