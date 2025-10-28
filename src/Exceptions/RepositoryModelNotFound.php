<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Exceptions;

use RuntimeException;

/**
 * Class RepositoryModelNotFound
 *
 * @author Amondar-SO
 */
class RepositoryModelNotFound extends RuntimeException
{
    public static function make(string $repositoryClass): static
    {
        return new static(<<<MESSAGE
                            Repository model not found in "$repositoryClass"
                          MESSAGE
            , 500);
    }
}
