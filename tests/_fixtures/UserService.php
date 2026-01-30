<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Concerns\HasCreateCommand;
use Amondar\RepositoryPattern\Concerns\HasUpdateCommand;
use Amondar\RepositoryPattern\Contracts\CreationCommandContract;
use Amondar\RepositoryPattern\Contracts\UpdateCommandContract;
use Amondar\RepositoryPattern\Service;
use Spatie\LaravelData\Data;

/**
 * Class UserService
 *
 * @extends Service<User, UserData, UserRepository>
 *
 * @author Amondar-SO
 */
class UserService extends Service implements CreationCommandContract, UpdateCommandContract
{
    /**
     * @use HasCreateCommand<User, UserData, UserRepository>
     * @use HasUpdateCommand<User, UserData, UserRepository>
     */
    use HasCreateCommand, HasUpdateCommand;

    /**
     * UserService constructor.
     */
    public function __construct(private readonly UserRepository $userRepository)
    {
        //
    }

    /**
     * @param  User  $model
     * @return array[]
     */
    public function storeModelRelations($model, array|Data &$data): void
    {
        //
    }

    protected function repository(): UserRepository
    {
        return $this->userRepository;
    }

    protected function creatingHook(array|Data &$data): void
    {
        $data->email = 'my+1@email.com';

        CreatingEvent::dispatch($data->email);
    }

    protected function createdHook(User $model, array|Data $data): void
    {
        CreatedEvent::dispatch($model, $data->toArray());
    }

    protected function updatingHook(User $model, array|Data &$data): void
    {
        UpdatingEvent::dispatch($model, $data->toArray());
    }

    protected function updatedHook(User $model, array|Data $data): void
    {
        UpdatedEvent::dispatch($model, $data->toArray());
    }
}
