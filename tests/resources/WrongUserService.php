<?php

declare(strict_types = 1);

namespace Tests\resources;

use Amondar\RepositoryPattern\Contracts\CreationCommandContract;
use Amondar\RepositoryPattern\Extensions\HasCreateCommand;
use Amondar\RepositoryPattern\Service;
use Illuminate\Support\Facades\DB;

/**
 * Class WrongUserService
 *
 * @extends Service<User, UserData, UserRepository>
 *
 * @author Amondar-SO
 */
class WrongUserService extends Service implements CreationCommandContract
{
    /**
     * @use HasCreateCommand<User, UserData, UserRepository>
     */
    use HasCreateCommand;

    /**
     * UserService constructor.
     */
    public function __construct(private readonly UserRepository $userRepository)
    {
        //
    }

    protected function repository(): UserRepository
    {
        return $this->userRepository;
    }

    protected function creatingHook(array &$data): void
    {
        CreatingEvent::dispatch($data[ 'email' ]);
    }

    protected function createdHook(User $model, array $data, array $relations): void
    {
        DB::table('outbox_not_exists')->insert([
            $data,
        ]);
    }
}
