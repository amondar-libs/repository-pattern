<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Enums;

enum LockType: string
{
    case forUpdate = 'lockForUpdate';
    case shared = 'sharedLock';
}
