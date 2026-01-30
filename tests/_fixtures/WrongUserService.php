<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Concerns\HasCreateCommand;
use Amondar\RepositoryPattern\Contracts\CreationCommandContract;
use Amondar\RepositoryPattern\Service;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Data;

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

    protected function creatingHook(array|Data &$data): void
    {
        CreatingEvent::dispatch($data->email);
    }

    protected function createdHook(User $model, array|Data $data, array $relations): void
    {
        DB::table('outbox_not_exists')->insert([
            $data,
        ]);
    }
}
