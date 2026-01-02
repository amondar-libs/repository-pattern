<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Repository;

/**
 * Class UserRepository
 *
 * @extends Repository<User, UserData>
 *
 * @author Amondar-SO
 */
#[UseModel(User::class)]
readonly class UserRepository extends Repository
{
    public function myFunction() {}
}
