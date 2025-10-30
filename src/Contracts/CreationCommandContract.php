<?php

namespace Amondar\RepositoryPattern\Contracts;

use Spatie\LaravelData\Data;

/**
 * Interface CreationCommandContract
 *
 * @template TModel
 * @template TData
 *
 * @author Amondar-SO
 */
interface CreationCommandContract
{

    /**
     * Creates a new entity or record based on the provided data.
     *
     * @param array<string, mixed>|TData $data The data used to create the entity or record.
     *
     * @return TModel created entity, record, or result of the creation process.
     */
    public function create(array|Data $data);

}