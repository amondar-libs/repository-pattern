<?php

declare(strict_types = 1);

use Amondar\RepositoryPattern\Enums\Lock;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\_fixtures\CachedUserRepository;
use Tests\_fixtures\RepositoryCacheTestCase;
use Tests\_fixtures\User;
use Tests\_fixtures\UserAddress;
use Tests\_fixtures\UserRepository;

pest()->extend(RepositoryCacheTestCase::class);

/**
 * Executes a failing operation in the Act zone so assertions remain separate.
 */
function captureRepositoryCacheException(Closure $operation): ?Throwable
{
    try {
        $operation();

        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

dataset('cache falsey values', ['null' => [null], 'false' => [false], 'zero' => [0], 'empty string' => [''], 'empty array' => [[]]]);

dataset('cache invalid arguments', [
    'missing method'            => ['missing', [42], LogicException::class],
    'protected method'          => ['protectedRead', ['42'], LogicException::class],
    'static method'             => ['staticRead', ['42'], LogicException::class],
    'variadic method'           => ['variadic', ['42'], LogicException::class],
    'reference parameter'       => ['byReference', ['42'], LogicException::class],
    'no parameters'             => ['noArguments', [], InvalidArgumentException::class],
    'boolean first parameter'   => ['booleanFirst', [true], InvalidArgumentException::class],
    'missing required argument' => ['userCount', [], InvalidArgumentException::class],
    'unknown named argument'    => ['userCount', ['name' => 'Oleg', 'unknown' => 1], InvalidArgumentException::class],
    'extra positional argument' => ['userCount', ['Oleg', 1], InvalidArgumentException::class],
    'duplicate named argument'  => ['userCount', [0 => 'Oleg', 'name' => 'Other'], InvalidArgumentException::class],
    'unsupported object'        => ['value', [42, new stdClass], InvalidArgumentException::class],
]);

dataset('cache non-materialized results', [['resultBuilder'], ['resultQueryBuilder'], ['resultStream'], ['resultLazyCollection']]);

dataset('cache invalid TTLs', [[0], [-1]]);

it('cache misses then hits without executing the read again', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $proxy = $this->repository->cache;

    // -------- Act
    $transactionLevel = DB::transactionLevel();
    $miss = $proxy->usersNamed($user->name);
    $hit = $proxy->usersNamed($user->name);

    // -------- Assert
    expect($transactionLevel)->toBe(0)
        ->and($miss->modelKeys())->toBe([$user->id])
        ->and($hit->modelKeys())->toBe([$user->id])
        ->and($this->repository->calls('usersNamed'))->toBe(1);
});

it('falsey results are cached', function (mixed $value): void {
    // -------- Arrange
    $proxy = $this->repository->cache;

    // -------- Act
    $miss = $proxy->value(42, $value);
    $hit = $proxy->value(42, $value);

    // -------- Assert
    expect($miss)->toBe($value)
        ->and($hit)->toBe($value)
        ->and($this->repository->calls('value'))->toBe(1);
})->with('cache falsey values');

it('disabled caching needs neither tag support nor a scope override', function (): void {
    // -------- Arrange
    $store = new CacheRepository(new FileStore($this->app['files'], '/unused/cache'));
    $repository = new UserRepository($store);

    // -------- Act
    $enabled = $repository->usesCache();
    $result = $repository->cache->myFunction();

    // -------- Assert
    expect($enabled)->toBeFalse()
        ->and($result)->toBeNull();
});

it('cache dependency can be resolved from the container', function (): void {
    // -------- Arrange
    $this->app->instance(CacheContract::class, $this->cache);
    $repository = new CachedUserRepository;

    // -------- Act
    $result = $repository->cache->userCount('Oleg Sereda');
    $entry = $this->entry('userCount', ['Oleg Sereda'], $repository);

    // -------- Assert
    expect($result)->toBe(0)
        ->and($entry)->not->toBeNull();
});

it('enabled caching rejects a missing scope override before writes', function (): void {
    // -------- Arrange
    $construct = fn() => new readonly class($this->cache) extends UserRepository
    {
        public function usesCache(): bool
        {
            return true;
        }
    };

    // -------- Act
    $exception = captureRepositoryCacheException($construct);

    // -------- Assert
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('must implement getModelBaseScope()');
});

it('enabled caching rejects untaggable stores', function (): void {
    // -------- Arrange
    $store = new CacheRepository(new FileStore($this->app['files'], '/unused/cache'));

    // -------- Act
    $exception = captureRepositoryCacheException(fn() => new CachedUserRepository($store));

    // -------- Assert
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('tag support');
});

it('named arguments defaults and all argument values participate in keys', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $name = 'Oleg Sereda';

    // -------- Act
    $defaultKey = $proxy->generateKey('usersNamed', [$name]);
    $namedKey = $proxy->generateKey('usersNamed', ['activeOnly' => false, 'name' => $name]);
    $filteredKey = $proxy->generateKey('usersNamed', [$name, true]);
    $integerKey = $proxy->generateKey('value', [42]);
    $stringKey = $proxy->generateKey('value', ['42']);
    $enumKey = $proxy->generateKey('value', [42, Lock::shared]);
    $arrayKey = $proxy->generateKey('value', [42, ['enum', Lock::class, 'shared']]);
    $namedResult = $proxy->userCount(name: $name);
    $positionalResult = $proxy->userCount($name);

    // -------- Assert
    expect($namedKey)->toBe($defaultKey)
        ->and($filteredKey)->not->toBe($defaultKey)
        ->and($stringKey)->not->toBe($integerKey)
        ->and($arrayKey)->not->toBe($enumKey)
        ->and($namedResult)->toBe(0)
        ->and($positionalResult)->toBe(0)
        ->and($this->repository->calls('userCount'))->toBe(1);
});

it('invalid cached method signatures and arguments fail', function (string $method, array $arguments, string $exception): void {
    // -------- Arrange
    $proxy = $this->repository->cache;

    // -------- Act
    $caught = captureRepositoryCacheException(fn() => $proxy->generateKey($method, $arguments));

    // -------- Assert
    expect($caught)->toBeInstanceOf($exception);
})->with('cache invalid arguments');

it('models use persisted identity and dirty or unsaved models are rejected', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $proxy = $this->repository->cache;
    $fresh = $user->fresh();
    $unsaved = new User;
    $dirty = tap($user->fresh(), fn($model) => $model->name = 'Unsaved');

    // -------- Act
    $originalKey = $proxy->generateKey('modelName', [$user]);
    $freshKey = $proxy->generateKey('modelName', [$fresh]);
    $beforeUpdate = $proxy->modelName($user);
    $this->repository->update($user, ['name' => 'Other']);
    $afterUpdate = $proxy->modelName($user->fresh());
    $unsavedException = captureRepositoryCacheException(fn() => $proxy->generateKey('modelName', [$unsaved]));
    $dirtyException = captureRepositoryCacheException(fn() => $proxy->generateKey('modelName', [$dirty]));

    // -------- Assert
    expect($freshKey)->toBe($originalKey)
        ->and($beforeUpdate)->toBe('Oleg Sereda')
        ->and($afterUpdate)->toBe('Other')
        ->and($this->repository->calls('modelName'))->toBe(2)
        ->and($unsavedException)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($unsavedException->getMessage())->toContain('persisted and unchanged')
        ->and($dirtyException)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($dirtyException->getMessage())->toContain('persisted and unchanged');
});

it('builders and streams are not cached', function (string $method): void {
    // -------- Arrange
    $proxy = $this->repository->cache;

    // -------- Act
    $exception = captureRepositoryCacheException(fn() => $proxy->{$method}('42'));
    $entry = $this->entry($method, ['42']);

    // -------- Assert
    expect($exception)->toBeInstanceOf(LogicException::class)
        ->and($exception->getMessage())->toContain('materialized')
        ->and($entry)->toBeNull();
})->with('cache non-materialized results');

it('ttl clones do not change default ttl and results expire', function (): void {
    // -------- Arrange
    $this->freezeTime();
    $proxy = $this->repository->cache;

    // -------- Act
    $proxy->ttl(2)->value(1, 'short');
    $proxy->value(2, 'default');
    $this->travel(3)->seconds();
    $shortEntry = $this->entry('value', [1, 'short']);
    $defaultEntry = $this->entry('value', [2, 'default']);
    $proxy->ttl(2)->value(1, 'short');
    $callsAfterRefill = $this->repository->calls('value');
    $this->travel(298)->seconds();
    $expiredDefault = $this->entry('value', [2, 'default']);

    // -------- Assert
    expect($shortEntry)->toBeNull()
        ->and($defaultEntry)->not->toBeNull()
        ->and($callsAfterRefill)->toBe(3)
        ->and($expiredDefault)->toBeNull();
});

it('nonpositive ttls are rejected', function (int $ttl): void {
    // -------- Arrange
    $proxy = $this->repository->cache;

    // -------- Act
    $exception = captureRepositoryCacheException(fn() => $proxy->ttl($ttl));

    // -------- Assert
    expect($exception)->toBeInstanceOf(InvalidArgumentException::class);
})->with('cache invalid TTLs');

it('dependency versions expire and are never reused', function (): void {
    // -------- Arrange
    $this->freezeTime();
    $proxy = $this->repository->cache;
    $key = $this->scopeKey('users:name:Oleg');
    $proxy->ttl(600)->scopedValue('Oleg');
    $firstVersion = $this->cache->get($key);

    // -------- Act
    $proxy->invalidate('users:name:Oleg');
    $invalidatedVersion = $this->cache->get($key);
    $afterInvalidation = $proxy->ttl(600)->scopedValue('Oleg');
    $this->travel(301)->seconds();
    $expiredVersion = $this->cache->get($key);
    $remainingEntry = $this->entry('scopedValue', ['Oleg']);
    $afterExpiration = $proxy->ttl(600)->scopedValue('Oleg');
    $recreatedVersion = $this->cache->get($key);
    $this->travel(601)->seconds();
    $expiredRecreatedVersion = $this->cache->get($key);

    // -------- Assert
    expect($invalidatedVersion)->not->toBe($firstVersion)
        ->and($afterInvalidation)->toBe(2)
        ->and($expiredVersion)->toBeNull()
        ->and($remainingEntry)->not->toBeNull()
        ->and($afterExpiration)->toBe(3)
        ->and($recreatedVersion)->not->toBe($invalidatedVersion)
        ->and($expiredRecreatedVersion)->toBeNull();
});

it('deleted dependency versions force recomputation', function (): void {
    // -------- Arrange
    $this->repository->cache->scopedValue('Oleg');
    $key = $this->scopeKey('users:name:Oleg');

    // -------- Act
    $this->cache->forget($key);
    $result = $this->repository->cache->scopedValue('Oleg');

    // -------- Assert
    expect($result)->toBe(2);
});

it('multiple methods share scopes but other scopes remain cached', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $proxy->usersNamed('Oleg');
    $proxy->userCount('Oleg');
    $proxy->userCount('Other');

    // -------- Act
    $proxy->invalidate('users:name:Oleg');
    $proxy->usersNamed('Oleg');
    $proxy->userCount('Oleg');
    $proxy->userCount('Other');

    // -------- Assert
    expect($this->repository->calls('usersNamed'))->toBe(2)
        ->and($this->repository->calls('userCount'))->toBe(3);
});

it('dependency inheritance handles nested misses hits and direct inner calls', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $proxy->userCount('Oleg');

    // -------- Act
    $proxy->dashboard('Oleg');
    $proxy->directDashboard('Oleg');
    $callsBeforeInvalidation = $this->repository->calls('userCount');
    $proxy->invalidate('users:name:Oleg');
    $proxy->dashboard('Oleg');
    $proxy->directDashboard('Oleg');

    // -------- Assert
    expect($callsBeforeInvalidation)->toBe(2)
        ->and($this->repository->calls('dashboard'))->toBe(2)
        ->and($this->repository->calls('directDashboard'))->toBe(2)
        ->and($this->repository->calls('userCount'))->toBe(4);
});

it('nested ttl clone dependencies outlive inner result entries', function (): void {
    // -------- Arrange
    $this->freezeTime();
    $proxy = $this->repository->cache;
    $proxy->ttl(100)->dashboard('Oleg');

    // -------- Act
    $this->travel(8)->seconds();
    $innerEntry = $this->entry('userCount', ['Oleg']);
    $cachedDashboard = $proxy->dashboard('Oleg');
    $callsBeforeInvalidation = $this->repository->calls('dashboard');
    $proxy->invalidate('users:name:Oleg');
    $proxy->dashboard('Oleg');

    // -------- Assert
    expect($innerEntry)->toBeNull()
        ->and($cachedDashboard)->toBe(['count' => 0])
        ->and($callsBeforeInvalidation)->toBe(1)
        ->and($this->repository->calls('dashboard'))->toBe(2);
});

it('direct dependency registration is a noop and exception frames are removed', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $directKey = $this->scopeKey('users:name:Direct');
    $afterExceptionKey = $this->scopeKey('after-exception');

    // -------- Act
    $this->repository->userCount('Direct');
    $directVersion = $this->cache->get($directKey);
    $exception = captureRepositoryCacheException(fn() => $proxy->throwing('Failed'));
    $proxy->dependsOn('after-exception');
    $afterExceptionVersion = $this->cache->get($afterExceptionKey);
    $failedEntry = $this->entry('throwing', ['Failed']);
    $proxy->scopedValue('Clean');
    $cleanEntry = $this->entry('scopedValue', ['Clean']);

    // -------- Assert
    expect($directVersion)->toBeNull()
        ->and($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($afterExceptionVersion)->toBeNull()
        ->and($failedEntry)->toBeNull()
        ->and($cleanEntry['dependencies'])->not->toHaveKey($this->scopeKey('users:name:Failed'));
});

it('changed dependencies during a read prevent result publication', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $this->repository->state->offsetSet('onRead', fn($repository, $name) => $repository->cache->invalidate("users:name:$name"));

    // -------- Act
    $invalidatedRead = $proxy->scopedValue('Oleg');
    $unpublishedEntry = $this->entry('scopedValue', ['Oleg']);
    $this->repository->state->offsetUnset('onRead');
    $refilled = $proxy->scopedValue('Oleg');
    $hit = $proxy->scopedValue('Oleg');

    // -------- Assert
    expect($invalidatedRead)->toBe(1)
        ->and($unpublishedEntry)->toBeNull()
        ->and($refilled)->toBe(2)
        ->and($hit)->toBe(2);
});

it('repository flush during a read prevents result publication', function (): void {
    // -------- Arrange
    $this->repository->state->offsetSet('onRead', fn($repository) => $repository->cache->forget());

    // -------- Act
    $this->repository->cache->scopedValue('Oleg');
    $entry = $this->entry('scopedValue', ['Oleg']);

    // -------- Assert
    expect($entry)->toBeNull();
});

it('create invalidates empty lists and preserves other users results', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $proxy->usersNamed('Oleg Sereda');
    $proxy->usersNamed('Other');

    // -------- Act
    $this->createUser();
    $affected = $proxy->usersNamed('Oleg Sereda');
    $unaffected = $proxy->usersNamed('Other');

    // -------- Assert
    expect($affected)->toHaveCount(1)
        ->and($unaffected)->toHaveCount(0)
        ->and($this->repository->calls('usersNamed'))->toBe(3);
});

it('update invalidates previous and new scopes even for already dirty models', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $proxy = $this->repository->cache;
    $proxy->usersNamed('Oleg Sereda');
    $proxy->usersNamed('Other');
    $user->name = 'Other';

    // -------- Act
    $this->repository->update($user, ['is_active' => false]);
    $previousOwner = $proxy->usersNamed('Oleg Sereda');
    $newOwner = $proxy->usersNamed('Other');

    // -------- Assert
    expect($previousOwner)->toHaveCount(0)
        ->and($newOwner)->toHaveCount(1)
        ->and($this->repository->calls('usersNamed'))->toBe(4);
});

it('update invalidates current database owner when supplied model is stale', function (): void {
    // -------- Arrange
    $stale = $this->createUser();
    $this->repository->update($stale->fresh(), ['name' => 'Other']);
    $this->repository->cache->usersNamed('Other');

    // -------- Act
    $this->repository->update($stale, ['name' => 'New']);
    $previousOwner = $this->repository->cache->usersNamed('Other');
    $newOwner = $this->repository->cache->usersNamed('New');

    // -------- Assert
    expect($previousOwner)->toHaveCount(0)
        ->and($newOwner)->toHaveCount(1);
});

it('returned models automatically register scopes and cached models are snapshots', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $proxy = $this->repository->cache;
    $first = $proxy->modelById($user->id);

    // -------- Act
    $first->name = 'Unsaved';
    $cachedName = $proxy->modelById($user->id)->name;
    $this->repository->update($user, ['name' => 'Other']);
    $updatedName = $proxy->modelById($user->id)->name;

    // -------- Assert
    expect($cachedName)->toBe('Oleg Sereda')
        ->and($updatedName)->toBe('Other')
        ->and($this->repository->calls('modelById'))->toBe(2);
});

it('additional scopes are invalidated when a model leaves them', function (): void {
    // -------- Arrange
    $user = $this->createUser('Oleg Sereda', ['is_admin' => true]);
    $before = $this->repository->cache->administrators();

    // -------- Act
    $this->repository->update($user, ['is_admin' => false]);
    $after = $this->repository->cache->administrators();

    // -------- Assert
    expect($before)->toHaveCount(1)
        ->and($after)->toHaveCount(0);
});

it('delete captures all affected model scopes before bulk deletion', function (): void {
    // -------- Arrange
    $first = $this->createUser();
    $second = $this->createUser('Other');
    $proxy = $this->repository->cache;
    $proxy->usersNamed($first->name);
    $proxy->usersNamed($second->name);

    // -------- Act
    $deleted = $this->repository->deleteBy([$first->id, $second->id]);
    $firstOwner = $proxy->usersNamed($first->name);
    $secondOwner = $proxy->usersNamed($second->name);

    // -------- Assert
    expect($deleted)->toBe(2)
        ->and($firstOwner)->toHaveCount(0)
        ->and($secondOwner)->toHaveCount(0);
});

it('delete by alternative field and zero match deletes', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $proxy = $this->repository->cache;
    $proxy->userCount($user->name);

    // -------- Act
    $unmatched = $this->repository->deleteBy('missing@email.com', 'email');
    $proxy->userCount($user->name);
    $callsAfterUnmatchedDelete = $this->repository->calls('userCount');
    $deleted = $this->repository->deleteBy($user, 'email');
    $remaining = $proxy->userCount($user->name);

    // -------- Assert
    expect($unmatched)->toBe(0)
        ->and($callsAfterUnmatchedDelete)->toBe(1)
        ->and($deleted)->toBe(1)
        ->and($remaining)->toBe(0);
});

it('upsert flushes the repository but empty upsert does not', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $proxy = $this->repository->cache;
    $proxy->userCount($user->name);
    $proxy->userCount('Unrelated');
    $rows = [
        ['name' => 'Other', 'email' => $user->email, 'password' => $user->password],
        ['name' => 'Other', 'email' => 'new@email.com', 'password' => $user->password],
    ];

    // -------- Act
    $emptyUpsert = $this->repository->upsert([], ['email']);
    $proxy->userCount('Unrelated');
    $callsAfterEmptyUpsert = $this->repository->calls('userCount');
    $this->repository->upsert($rows, ['email'], ['name']);
    $previousOwner = $proxy->userCount($user->name);
    $newOwner = $proxy->userCount('Other');
    $proxy->userCount('Unrelated');

    // -------- Assert
    expect($emptyUpsert)->toBe(0)
        ->and($callsAfterEmptyUpsert)->toBe(2)
        ->and($previousOwner)->toBe(0)
        ->and($newOwner)->toBe(2)
        ->and($this->repository->calls('userCount'))->toBe(5);
});

it('push flushes repository after persisting relationships', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $address = UserAddress::create(['user_id' => $user->id, 'first_line' => 'Before', 'zip_code' => '12345']);
    $user->setRelation('addresses', $user->newCollection([$address]));
    $this->repository->cache->userCount('Unrelated');
    $address->first_line = 'After';

    // -------- Act
    $saved = $this->repository->push($user);
    $persistedLine = $address->fresh()->first_line;
    $this->repository->cache->userCount('Unrelated');

    // -------- Assert
    expect($saved)->toBeTrue()
        ->and($persistedLine)->toBe('After')
        ->and($this->repository->calls('userCount'))->toBe(2);
});

it('failed push still invalidates when root model has already been saved', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $address = UserAddress::create(['user_id' => $user->id, 'first_line' => 'Before', 'zip_code' => '12345']);
    $user->setRelation('addresses', $user->newCollection([$address]));
    $this->repository->cache->userCount('Oleg Sereda');
    $user->name = 'Other';
    $address->first_line = 'After';
    UserAddress::saving(fn() => false);

    // -------- Act
    $saved = $this->repository->push($user);
    $persistedName = $user->fresh()->name;
    $remaining = $this->repository->cache->userCount('Oleg Sereda');

    // -------- Assert
    expect($saved)->toBeFalse()
        ->and($persistedName)->toBe('Other')
        ->and($remaining)->toBe(0);
});

it('failed create and update do not invalidate cached results', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $this->repository->cache->userCount($user->name);
    User::saving(fn() => false);

    // -------- Act
    $created = $this->createUser();
    $this->repository->update($user, ['name' => 'Other']);
    $remaining = $this->repository->cache->userCount('Oleg Sereda');

    // -------- Assert
    expect($created->exists)->toBeFalse()
        ->and($remaining)->toBe(1)
        ->and($this->repository->calls('userCount'))->toBe(1);
});

it('transactions bypass cache and only outermost commit invalidates', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $proxy->userCount('Oleg Sereda');
    $key = $this->scopeKey('users:name:Oleg Sereda');
    $before = $this->cache->get($key);

    // -------- Act
    DB::beginTransaction();
    DB::beginTransaction();
    $this->createUser();
    $insideTransaction = $proxy->userCount('Oleg Sereda');
    $storedValue = $this->entry('userCount', ['Oleg Sereda'])['value'];
    DB::commit();
    $afterInnerCommit = $this->cache->get($key);
    DB::commit();
    $afterOuterCommit = $this->cache->get($key);
    $committedResult = $proxy->userCount('Oleg Sereda');

    // -------- Assert
    expect($insideTransaction)->toBe(1)
        ->and($storedValue)->toBe(0)
        ->and($afterInnerCommit)->toBe($before)
        ->and($afterOuterCommit)->not->toBe($before)
        ->and($committedResult)->toBe(1)
        ->and($this->repository->calls('userCount'))->toBe(3);
});

it('rollback discards invalidation and preserves cached results', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $proxy->userCount('Oleg Sereda');
    $key = $this->scopeKey('users:name:Oleg Sereda');
    $before = $this->cache->get($key);

    // -------- Act
    DB::beginTransaction();
    $this->createUser();
    DB::rollBack();
    $after = $this->cache->get($key);
    $result = $proxy->userCount('Oleg Sereda');

    // -------- Assert
    expect($after)->toBe($before)
        ->and($result)->toBe(0)
        ->and($this->repository->calls('userCount'))->toBe(1);
});

it('inner rollback discards only inner invalidation', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $proxy->userCount('Oleg Sereda');
    $proxy->userCount('Other');
    $otherKey = $this->scopeKey('users:name:Other');
    $otherVersion = $this->cache->get($otherKey);

    // -------- Act
    DB::beginTransaction();
    $this->createUser();
    DB::beginTransaction();
    $this->createUser('Other');
    DB::rollBack();
    DB::commit();
    $committedCount = $proxy->userCount('Oleg Sereda');
    $otherVersionAfterCommit = $this->cache->get($otherKey);
    $rolledBackCount = $proxy->userCount('Other');

    // -------- Assert
    expect($committedCount)->toBe(1)
        ->and($otherVersionAfterCommit)->toBe($otherVersion)
        ->and($rolledBackCount)->toBe(0);
});

it('quiet writes invalidate without model events', function (): void {
    // -------- Arrange
    $this->repository->cache->userCount('Oleg Sereda');
    $data = [
        'name'      => 'Oleg Sereda', 'email' => 'quiet@email.com', 'password' => '123456',
        'is_active' => true, 'is_admin' => false,
    ];

    // -------- Act
    $this->repository->quietly->create($data);
    $count = $this->repository->cache->userCount('Oleg Sereda');

    // -------- Assert
    expect($count)->toBe(1)
        ->and($this->repository->calls('userCount'))->toBe(2);
});

it('flush is repository scoped but dependencies can be shared across classes', function (): void {
    // -------- Arrange
    $other = new readonly class($this->cache) extends CachedUserRepository {};
    $this->repository->cache->userCount('Oleg');
    $other->cache->userCount('Oleg');

    // -------- Act
    $this->repository->cache->forget();
    $other->cache->userCount('Oleg');
    $callsAfterFlush = $other->calls('userCount');
    $this->repository->cache->invalidate('users:name:Oleg');
    $other->cache->userCount('Oleg');

    // -------- Assert
    expect($callsAfterFlush)->toBe(1)
        ->and($other->calls('userCount'))->toBe(2);
});

it('repository clone is bound to itself but shares cached entries', function (): void {
    // -------- Arrange
    $original = $this->repository;

    // -------- Act
    $clone = clone $original;
    $clonedIdentity = $clone->cache->identity('clone');
    $originalIdentity = $original->cache->identity('original');
    $original->cache->userCount('Oleg');
    $clone->cache->userCount('Oleg');

    // -------- Assert
    expect($original->cache)->not->toBe($clone->cache)
        ->and($clonedIdentity)->toBe(spl_object_id($clone))
        ->and($originalIdentity)->toBe(spl_object_id($original))
        ->and($original->calls('userCount'))->toBe(1);
});

it('concurrent repository clones have independent frames', function (): void {
    // -------- Arrange
    $clone = clone $this->repository;
    $first = new Fiber(fn() => $this->repository->cache->fiberRead('First'));
    $second = new Fiber(fn() => $clone->cache->fiberRead('Second'));

    // -------- Act
    $firstRepository = $first->start();
    $secondRepository = $second->start();
    $first->resume();
    $second->resume();
    $firstResult = $first->getReturn();
    $secondResult = $second->getReturn();
    $firstEntry = $this->entry('fiberRead', ['First']);
    $secondEntry = $this->entry('fiberRead', ['Second'], $clone);

    // -------- Assert
    expect($firstRepository)->toBe($this->repository)
        ->and($secondRepository)->toBe($clone)
        ->and($firstResult)->toBe('First')
        ->and($secondResult)->toBe('Second')
        ->and($firstEntry['dependencies'])->not->toHaveKey($this->scopeKey('users:name:Second'))
        ->and($secondEntry['dependencies'])->not->toHaveKey($this->scopeKey('users:name:First'));
});

it('cloning during an active read does not inherit parent dependencies', function (): void {
    // -------- Arrange
    $this->repository->state->offsetSet('onRead', function ($repository) {
        $repository->state->offsetUnset('onRead');
        $clone = clone $repository;
        $clone->cache->scopedValue('Child');
    });

    // -------- Act
    $this->repository->cache->scopedValue('Parent');
    $childEntry = $this->entry('scopedValue', ['Child']);

    // -------- Assert
    expect($childEntry['dependencies'])->not->toHaveKey($this->scopeKey('users:name:Parent'));
});

it('for repository preserves ttl and uses fresh frames', function (): void {
    // -------- Arrange
    $this->freezeTime();
    $clone = clone $this->repository;
    $proxy = $this->repository->cache->ttl(2)->forRepository($clone);

    // -------- Act
    $identity = $proxy->identity('rebound');
    $this->travel(3)->seconds();
    $entry = $this->entry('identity', ['rebound'], $clone);

    // -------- Assert
    expect($identity)->toBe(spl_object_id($clone))
        ->and($entry)->toBeNull();
});

dataset('cache malformed entries', [
    'not an envelope'            => ['invalid'],
    'missing value'              => [['dependencies' => []]],
    'missing dependencies'       => [['value' => 999]],
    'invalid dependency list'    => [['value' => 999, 'dependencies' => null]],
    'missing dependency version' => [['value' => 999, 'dependencies' => ['missing-dependency' => 'old']]],
]);

it('recomputes malformed or stale cache entries', function (mixed $entry): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $proxy->getStore()->put($proxy->generateKey('userCount', ['Oleg']), $entry, 300);

    // -------- Act
    $result = $proxy->userCount('Oleg');

    // -------- Assert
    expect($result)->toBe(0)
        ->and($this->repository->calls('userCount'))->toBe(1);
})->with('cache malformed entries');

it('rejects empty dependency scopes and cleans up the failed frame', function (): void {
    // -------- Arrange
    $proxy = $this->repository->cache;
    $this->repository->state->offsetSet('onRead', fn($repository) => $repository->cache->dependsOn(''));

    // -------- Act
    $invalidationException = captureRepositoryCacheException(fn() => $proxy->invalidate(''));
    $readException = captureRepositoryCacheException(fn() => $proxy->scopedValue('Oleg'));
    $proxy->dependsOn('outside-call');
    $outsideVersion = $this->cache->get($this->scopeKey('outside-call'));
    $entry = $this->entry('scopedValue', ['Oleg']);

    // -------- Assert
    expect($invalidationException)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($readException)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($outsideVersion)->toBeNull()
        ->and($entry)->toBeNull();
});

it('deduplicates model scopes and rejects invalid additional scopes', function (): void {
    // -------- Arrange
    $repository = new readonly class($this->cache) extends CachedUserRepository
    {
        protected function getModelAdditionalScopes(Model $model): array
        {
            return $this->state['additionalScopes'] ?? [];
        }
    };
    $user = $this->createUser();
    $repository->state->offsetSet('additionalScopes', ['users:name:Oleg Sereda', 'users:admins', 'users:admins']);

    // -------- Act
    $scopes = $repository->getCacheScopesForModel($user);
    $repository->state->offsetSet('additionalScopes', ['']);
    $emptyScopeException = captureRepositoryCacheException(fn() => $repository->getCacheScopesForModel($user));
    $repository->state->offsetSet('additionalScopes', [42]);
    $numericScopeException = captureRepositoryCacheException(fn() => $repository->getCacheScopesForModel($user));

    // -------- Assert
    expect($scopes)->toBe(['users:name:Oleg Sereda', 'users:admins'])
        ->and($emptyScopeException)->toBeInstanceOf(RuntimeException::class)
        ->and($emptyScopeException->getMessage())->toContain('non-empty strings')
        ->and($numericScopeException)->toBeInstanceOf(RuntimeException::class)
        ->and($numericScopeException->getMessage())->toContain('non-empty strings');
});

it('does not grow dependency frames when a singleton is reused sequentially', function (): void {
    // -------- Arrange
    $this->app->instance(CachedUserRepository::class, $this->repository);
    $instances = [];

    // -------- Act
    for ($iteration = 0; $iteration < 30; $iteration++) {
        $repository = app(CachedUserRepository::class);
        $instances[] = $repository;
        $repository->cache->userCount('Oleg');
        $repository->cache->dependsOn('outside-singleton-call');
    }
    $outsideVersion = $this->cache->get($this->scopeKey('outside-singleton-call'));

    // -------- Assert
    expect($instances)->each->toBe($this->repository);
    expect($this->repository->calls('userCount'))->toBe(1)
        ->and($outsideVersion)->toBeNull();
});

it('invalidates additional scopes when a model enters them', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $before = $this->repository->cache->administrators();

    // -------- Act
    $this->repository->update($user, ['is_admin' => true]);
    $after = $this->repository->cache->administrators();

    // -------- Assert
    expect($before)->toHaveCount(0)
        ->and($after)->toHaveCount(1)
        ->and($this->repository->calls('administrators'))->toBe(2);
});

it('preserves cached entries when an upsert transaction rolls back', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $proxy = $this->repository->cache;
    $proxy->userCount($user->name);
    $proxy->userCount('Other');
    $rows = [['name' => 'Other', 'email' => $user->email, 'password' => $user->password]];

    // -------- Act
    DB::beginTransaction();
    $this->repository->upsert($rows, ['email'], ['name']);
    DB::rollBack();
    $originalCount = $proxy->userCount($user->name);
    $otherCount = $proxy->userCount('Other');

    // -------- Assert
    expect($originalCount)->toBe(1)
        ->and($otherCount)->toBe(0)
        ->and($this->repository->calls('userCount'))->toBe(2);
});

it('invalidates after a related push throws following a successful root save', function (): void {
    // -------- Arrange
    $user = $this->createUser();
    $address = UserAddress::create(['user_id' => $user->id, 'first_line' => 'Before', 'zip_code' => '12345']);
    $user->setRelation('addresses', $user->newCollection([$address]));
    $this->repository->cache->userCount($user->name);
    $user->name = 'Other';
    $address->first_line = 'After';
    UserAddress::saving(fn() => throw new RuntimeException('Related save failed.'));

    // -------- Act
    $exception = captureRepositoryCacheException(fn() => $this->repository->push($user));
    $persistedName = $user->fresh()->name;
    $remaining = $this->repository->cache->userCount('Oleg Sereda');

    // -------- Assert
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('Related save failed.')
        ->and($persistedName)->toBe('Other')
        ->and($remaining)->toBe(0);
});

it('uses the model connection rather than the default connection for commit callbacks', function (): void {
    // -------- Arrange
    config(['database.connections.cache_other' => config('database.connections.testing')]);
    $otherConnection = DB::connection('cache_other');
    $schema = DB::selectOne("select sql from sqlite_master where type = 'table' and name = 'users'")->sql;
    $otherConnection->statement($schema);
    $repository = new readonly class($this->cache) extends CachedUserRepository
    {
        public function makeModel()
        {
            return parent::makeModel()->setConnection('cache_other');
        }

        public function query()
        {
            return $this->makeModel()->newQuery();
        }
    };
    $repository->cache->userCount('Oleg Sereda');
    $key = $this->scopeKey('users:name:Oleg Sereda');
    $data = [
        'name'     => 'Oleg Sereda', 'email' => 'other-connection@email.com',
        'password' => '123456', 'is_active' => true, 'is_admin' => false,
    ];

    // -------- Act
    DB::beginTransaction();
    try {
        $user = $repository->create($data);
        $immediateCount = $repository->cache->userCount('Oleg Sereda');
        $before = $this->cache->get($key);
        $otherConnection->beginTransaction();
        $repository->update($user, ['name' => 'Other']);
        $beforeModelCommit = $this->cache->get($key);
        $otherConnection->commit();
        $afterModelCommit = $this->cache->get($key);
        $committedCount = $repository->cache->userCount('Oleg Sereda');
    } finally {
        if ($otherConnection->transactionLevel() > 0) {
            $otherConnection->rollBack();
        }
        DB::rollBack();
    }

    // -------- Assert
    expect($immediateCount)->toBe(1)
        ->and($beforeModelCommit)->toBe($before)
        ->and($afterModelCommit)->not->toBe($before)
        ->and($committedCount)->toBe(0);
});
