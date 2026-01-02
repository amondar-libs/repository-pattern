<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Repository;

/**
 * Class VersionedPostRepository
 *
 * @extends Repository<VersionedPost>
 *
 * @author Amondar-SO
 */
#[UseModel(VersionedPost::class)]
readonly class VersionedPostRepository extends Repository
{
    //
}
