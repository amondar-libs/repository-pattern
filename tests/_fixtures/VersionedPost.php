<?php

declare(strict_types = 1);

namespace Tests\_fixtures;

use Amondar\RepositoryPattern\Attributes\VersionField;
use Amondar\RepositoryPattern\Concerns\HasOptimisticLock;
use Amondar\RepositoryPattern\Contracts\Lockable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Class VersionedPost
 *
 * @author Amondar-SO
 */
#[VersionField('version_field')]
class VersionedPost extends Model implements Lockable
{
    use HasOptimisticLock, HasUuids;

    protected $fillable = ['title', 'body'];
}
