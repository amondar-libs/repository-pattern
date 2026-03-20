<?php

declare(strict_types = 1);

namespace Amondar\RepositoryPattern\Concerns;

use Amondar\ClassAttributes\Support\Attribute;
use Amondar\RepositoryPattern\Attributes\VersionField;
use Amondar\RepositoryPattern\Contracts\Lockable;
use Amondar\RepositoryPattern\Exceptions\OptimisticLockException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Trait HasOptimisticLock
 *
 * @mixin Model
 *
 * @author Amondar-SO
 */
trait HasOptimisticLock
{
    /**
     * Version field name.
     */
    public static ?string $versionField = null;

    /**
     * Default lock version.
     */
    public static int $defaultLockVersion = 1;

    /**
     * Determine that the model is locked.
     */
    protected static bool $locked = true;

    /**
     * Retrieves the field name used for optimistic locking in the current model.
     *
     * @return string|null The name of the optimistic lock field if defined, or null if not available.
     */
    public static function getOptimisticLockFieldName(): ?string
    {
        $parse = Attribute::for(VersionField::class)
            ->ascend()
            ->on(static::class);

        return ($parse[0] ?? null)?->attribute->field;
    }

    /**
     * Execute the given callback while the current process remains unlocked.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback  The callback to execute while the process is unlocked.
     * @return TResult The result of the callback execution.
     */
    public static function unlocked(Closure $callback)
    {
        if ( ! static::$locked) {
            return $callback();
        }

        static::unlock();

        try {
            return $callback();
        } finally {
            static::relock();
        }
    }

    /**
     * Unlocks the current instance by setting its locked state to false.
     */
    public static function unlock(): void
    {
        static::$locked = false;
    }

    /**
     * Re-locks the model by setting its locked state to true.
     */
    public static function relock(): void
    {
        static::$locked = true;
    }

    /**
     * Determine if the current instance is locked.
     */
    public static function isLocked(): bool
    {
        return static::$locked;
    }

    /**
     * Retrieves the current version value of the model for optimistic locking purposes.
     * This is typically used to track changes and handle concurrency in database operations.
     *
     * @return int|null The version value of the model, or null if the version field is not set.
     */
    public function lockVersion(): ?int
    {
        return $this->getAttribute(static::$versionField);
    }

    /**
     * Calculates the next version number for the optimistic lock field.
     * Ensures that the version number increments for successive updates to maintain data integrity.
     *
     * @return int The next lock version number. If no current version exists, returns the default lock version.
     */
    public function getNextLockVersion(): int
    {
        if ($current = $this->lockVersion()) {
            return $current + 1;
        }

        return static::$defaultLockVersion;
    }

    /**
     * Save the model instance without applying locking mechanisms.
     *
     * @param  array  $options  Array of options to be passed while saving the model.
     * @return bool Returns true if the model was successfully saved; otherwise, false.
     */
    public function saveUnlocked(array $options = []): bool
    {
        return static::unlocked(fn(): bool => $this->save($options));
    }

    /**
     * Boots the optimistic lock functionality for a model utilizing this trait.
     * Typically used to handle scenarios where concurrent updates to the same record might occur, ensuring data
     * integrity.
     */
    protected static function bootHasOptimisticLock(): void
    {
        // Load the version field attribute from the class attributes' library.
        if (empty(static::$versionField)) {
            static::$versionField = static::getOptimisticLockFieldName() ?? 'version';
        }

        // Subscribe to "creating" event to set the version field value.
        static::creating(function (Model $model) {
            if ( ! $model instanceof Lockable) {
                throw OptimisticLockException::interfaceRequired(static::class);
            }

            if ($model->lockVersion() === null) {
                $model->setAttribute(static::$versionField, $model->getNextLockVersion());
            }
        });
    }

    /**
     * Perform a model update operation.
     *
     * @param  Builder<static>  $query
     */
    #[Override]
    protected function performUpdate(Builder $query): bool
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query);

            // Run locking logic.
            $this->performLockingLogic($query, $dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Executes the core logic for optimistic locking during updates, ensuring that the record
     * has not been modified or deleted by another process. If a concurrency conflict is detected,
     * an exception is thrown.
     *
     * @param  Builder  $query  The query builder instance used to perform the update operation.
     * @param  array  $dirty  An array of attributes that have been modified and need to be persisted.
     *
     * @throws OptimisticLockException If the record was modified or deleted by another process,
     *                                 making the update operation invalid.
     */
    protected function performLockingLogic(Builder $query, array $dirty): void
    {
        $versionField = static::$versionField;

        $oldVersion = $this->lockVersion();

        // Add the version field condition to the query.
        // That is the main point of optimistic locking.
        if (static::isLocked()) {
            $query->where($versionField, '=', $oldVersion);
        }

        // Calculate the new version number.
        $version = tap(
            $this->getNextLockVersion(),
            fn($newVersion) => $this->setAttribute($versionField, $newVersion)
        );

        // Set version change as a dirty field.
        $dirty[ $versionField ] = $version;

        // If our update query didn't modify any records, this means
        // either another process has modified the record already,
        // or the record has been deleted. Since we consider deletion
        // as a type of update, we'll throw an exception in either case.
        $affected = $query->update($dirty);

        if ($affected === 0) {
            $this->setAttribute($versionField, $oldVersion);

            throw OptimisticLockException::fire(static::class, $oldVersion, $version);
        }
    }
}
