<?php

declare(strict_types = 1);

namespace Tests\resources;

use Amondar\RepositoryPattern\Extensions\HasCreateCommand;
use Amondar\RepositoryPattern\Extensions\HasUpdateCommand;
use Amondar\RepositoryPattern\Service;
use Illuminate\Database\Eloquent\Model;

/**
 * Class UserService
 *
 * @extends Service<User, UserData, UserRepository>
 *
 * @author Amondar-SO
 */
readonly class UserService extends Service
{
    /**
     * @use HasCreateCommand<User, UserData, UserRepository>
     * @use HasUpdateCommand<User, UserData, UserRepository>
     */
    use HasCreateCommand, HasUpdateCommand;

    /**
     * UserService constructor.
     */
    public function __construct(private UserRepository $userRepository)
    {
        //
    }

    /**
     * @param  User  $model
     * @return array[]
     */
    public function storeModelRelations($model, array &$data): array
    {
        return [
            'addresses' => [ 'attached' => [ 1, 2, 3 ], 'detached' => [ 4, 5, 6 ] ],
        ];
    }

    protected function repository(): UserRepository
    {
        return $this->userRepository;
    }

    protected function creatingHook(array &$data): void
    {
        $data[ 'email' ] = 'my+1@email.com';

        CreatingEvent::dispatch($data[ 'email' ]);
    }

    protected function createdHook(User $model, array $data, array $relations): void
    {
        CreatedEvent::dispatch($model, $data, $relations);
    }

    protected function updatingHook(User $model, array &$data): void
    {
        if (empty($data[ 'password' ])) {
            unset($data[ 'password' ]);
        }

        UpdatingEvent::dispatch($model, $data);
    }

    protected function updatedHook(Model $model, array $data, array $relations): void
    {
        UpdatedEvent::dispatch($model, $data, $relations);
    }
}
