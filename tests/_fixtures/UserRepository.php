<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryProxy;
use Amondar\RepositoryPattern\Repository;

/**
 * Class UserRepository
 *
 * @extends Repository<User, UserData>
 *
 * @property-read HighOrderRepositoryCacheProxy<User, UserData, UserRepository> $cache
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $quietly
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $transaction
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $unlocked
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $unlock
 *
 * @author Amondar-SO
 */
#[UseModel(User::class)]
readonly class UserRepository extends Repository
{
    public function myFunction() {}
}
