<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Class OptimisticLockException
 *
 * @author Amondar-SO
 */
class OptimisticLockException extends RuntimeException
{
    public readonly ?int $oldVersion;

    public readonly ?int $newVersion;

    public function __construct(string $message = '', ?int $oldVersion = null, ?int $newVersion = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 409, $previous);

        $this->oldVersion = $oldVersion;
        $this->newVersion = $newVersion;
    }

    public static function fire(string $modelClass, int $oldVersion, int $newVersion): static
    {
        $baseClass = class_basename($modelClass);

        return new static(<<<MESSAGE
                            $baseClass has been changed during update.
                          MESSAGE
            , $oldVersion, $newVersion);
    }

    public static function interfaceRequired(string $modelClass): static
    {
        return new static(<<<MESSAGE
                            Model \"$modelClass\" should implement \"Amondar\\RepositoryPattern\\Contracts\\Lockable\" interface.
                        MESSAGE
        );
    }
}
