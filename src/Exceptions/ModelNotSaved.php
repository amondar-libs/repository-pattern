<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Exceptions;

use RuntimeException;

/**
 * Class ModelNotSaved
 *
 * @author Amondar-SO
 */
class ModelNotSaved extends RuntimeException
{
    public static function notCreated(string $modelClass): static
    {
        $baseClass = class_basename($modelClass);

        return new static(<<<MESSAGE
                            Model "$baseClass" not created in DB.
                          MESSAGE
            , 500);
    }
}
