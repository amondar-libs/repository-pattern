<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Contracts;

use Closure;

/**
 * Interface Lockable
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 *
 * @author Amondar-SO
 */
interface Lockable
{
    /**
     * Determine if the current instance is locked.
     */
    public static function isLocked(): bool;

    /**
     * Unlocks the current instance by setting its locked state to false.
     */
    public static function unlock(): void;

    /**
     * Re-locks the model by setting its locked state to true.
     */
    public static function relock(): void;

    /**
     * Execute the given callback while the current process remains unlocked.
     *
     * @template TResult
     *
     * @param  Closure(static): TResult  $callback  The callback to execute while the process is unlocked.
     * @return TResult The result of the callback execution.
     */
    public static function unlocked(Closure $callback);

    /**
     * Save the model instance without applying locking mechanisms.
     *
     * @param  array  $options  Array of options to be passed while saving the model.
     * @return bool Returns true if the model was successfully saved; otherwise, false.
     */
    public function saveUnlocked(array $options = []): bool;

    /**
     * Retrieves the current version value of the model for optimistic locking purposes.
     * This is typically used to track changes and handle concurrency in database operations.
     *
     * @return int|null The version value of the model, or null if the version field is not set.
     */
    public function lockVersion(): ?int;

    /**
     * Calculates the next version number for the optimistic lock field.
     * Ensures that the version number increments for successive updates to maintain data integrity.
     *
     * @return int The next lock version number. If no current version exists, returns the default lock version.
     */
    public function getNextLockVersion(): int;
}
