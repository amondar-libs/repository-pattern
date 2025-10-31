<?php

namespace Amondar\RepositoryPattern\Enums;

enum LockType: string
{
    case FOR_UPDATE = 'lockForUpdate';
    case SHARED = 'sharedLock';
}
