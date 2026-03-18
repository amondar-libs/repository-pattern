<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Attributes\UseModel;

/**
 * Class ChildUserRepository
 *
 * @author Amondar-SO
 */
#[UseModel(User::class)]
readonly class ChildUserRepository extends UserRepository {}
