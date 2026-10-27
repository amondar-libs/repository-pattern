<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Amondar\RepositoryPattern\Contracts\RepositoryContract;
use Amondar\RepositoryPattern\Exceptions\RepositoryModelNotFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\_fixtures\ChildUserRepository;
use Tests\_fixtures\TestData;
use Tests\_fixtures\User;
use Tests\_fixtures\UserAddress;
use Tests\_fixtures\UserData;
use Tests\_fixtures\UserRepository;
use Tests\_fixtures\WrongUserRepository;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

it('can make user model', function () {
    // -------- Arrange
    $repository = new UserRepository;

    // -------- Act
    $model = $repository->makeModel();

    // -------- Assert
    expect($model)->toBeInstanceOf(User::class);
});

it('can create user', function () {
    // -------- Arrange
    Event::fake([
        $creatingEvent = 'eloquent.creating: ' . User::class,
        $createdEvent = 'eloquent.created: ' . User::class,
    ]);
    $repository = new UserRepository;
    $data = [
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ];

    // -------- Act
    $model = $repository->create($data);

    // -------- Assert
    expect($model)
        ->exists->toBeTrue()
        ->name->toBe('Oleg Sereda')
        ->email->toBe('my@email.com')
        ->is_active->toBeTrue()
        ->is_admin->toBeFalse();

    Event::assertDispatched($creatingEvent);
    Event::assertDispatched($createdEvent);
});

it('can create user quietly', function () {
    // -------- Arrange
    Event::fake([
        $creatingEvent = 'eloquent.creating: ' . User::class,
        $createdEvent = 'eloquent.created: ' . User::class,
    ]);
    $repository = new ChildUserRepository;
    $data = [
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ];

    // -------- Act
    $levelBefore = DB::transactionLevel();
    $model = $repository->transaction->quietly->create($data);
    $levelAfter = DB::transactionLevel();

    // -------- Assert
    expect($model)
        ->exists->toBeTrue()
        ->name->toBe('Oleg Sereda')
        ->email->toBe('my@email.com')
        ->is_active->toBeTrue()
        ->is_admin->toBeFalse();
    expect($levelBefore)->toBe(1)->and($levelAfter)->toBe(1);
    assertDatabaseHas('users', ['name' => 'Oleg Sereda']);
    Event::assertNotDispatched($creatingEvent);
    Event::assertNotDispatched($createdEvent);
});

it('can update user', function () {
    // -------- Arrange
    Event::fake([
        $updatingEvent = 'eloquent.updating: ' . User::class,
        $updatedEvent = 'eloquent.updated: ' . User::class,
    ]);
    $repository = new UserRepository;
    $model = $repository->quietly->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);
    $updates = [
        'name'      => 'Oleg Sereda 2',
        'email'     => 'my+1@email.com',
        'password'  => '1234567',
        'is_active' => false,
        'is_admin'  => true,
    ];

    // -------- Act
    $updated = $repository->update($model, $updates);

    // -------- Assert
    expect($updated)
        ->name->toBe('Oleg Sereda 2')
        ->email->toBe('my+1@email.com')
        ->is_active->toBeFalse()
        ->is_admin->toBeTrue();
    Event::assertDispatched($updatingEvent);
    Event::assertDispatched($updatedEvent);
});

it('can update user quietly', function () {
    // -------- Arrange
    Event::fake([
        $updatingEvent = 'eloquent.updating: ' . User::class,
        $updatedEvent = 'eloquent.updated: ' . User::class,
    ]);
    $repository = new UserRepository;
    $model = $repository->quietly->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);
    $updates = [
        'name'      => 'Oleg Sereda 2',
        'email'     => 'my+1@email.com',
        'password'  => '1234567',
        'is_active' => false,
        'is_admin'  => true,
    ];

    // -------- Act
    $updated = $repository->quietly->update($model, $updates);

    // -------- Assert
    expect($updated)
        ->name->toBe('Oleg Sereda 2')
        ->email->toBe('my+1@email.com')
        ->is_active->toBeFalse()
        ->is_admin->toBeTrue();
    Event::assertNotDispatched($updatingEvent);
    Event::assertNotDispatched($updatedEvent);
});

it('will throw an exception without attribute', function () {
    // -------- Arrange
    $expected = RepositoryModelNotFound::make(WrongUserRepository::class);

    // -------- Act
    $exception = $this->captureException(fn() => new WrongUserRepository);

    // -------- Assert
    expect($exception)->toBeInstanceOf(RepositoryModelNotFound::class)
        ->and($exception->getMessage())->toBe($expected->getMessage());
});

it('can apply builder calls', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $expected = [
        'key'        => 'select * from "users" where "users"."id" = ?',
        'comparison' => 'select * from "users" where "id" >= ?',
        'columns'    => 'select "id", "name" from "users"',
        'order'      => 'select * from "users" order by "name" asc',
        'limit'      => 'select * from "users" limit 10',
        'offset'     => 'select * from "users" offset 5',
        'null'       => 'select * from "users" where "deleted_at" is null',
        'in'         => 'select * from "users" where "id" in (?, ?, ?)',
        'between'    => 'select * from "users" where "id" between ? and ?',
        'or'         => 'select * from "users" where "name" = ? or "email" like ?',
        'group'      => 'select * from "users" group by "is_active" having "is_active" = ?',
        'join'       => 'select * from "users" inner join "profiles" on "profiles"."user_id" = "users"."id"',
    ];

    // -------- Act
    $queries = [
        'key'        => $repository->whereKey(1)->toSql(),
        'comparison' => $repository->where('id', '>=', 1)->toSql(),
        'columns'    => $repository->select('id', 'name')->toSql(),
        'order'      => $repository->orderBy('name')->toSql(),
        'limit'      => $repository->take(10)->toSql(),
        'offset'     => $repository->offset(5)->toSql(),
        'null'       => $repository->whereNull('deleted_at')->toSql(),
        'in'         => $repository->whereIn('id', [1, 2, 3])->toSql(),
        'between'    => $repository->whereBetween('id', [1, 10])->toSql(),
        'or'         => $repository->where('name', '=', 'John')->orWhere('email', 'like', '%@example.com')->toSql(),
        'group'      => $repository->groupBy('is_active')->having('is_active', '=', 1)->toSql(),
        'join'       => $repository->join('profiles', 'profiles.user_id', '=', 'users.id')->toSql(),
    ];
    $missing = $repository->firstWhere('name', 'John');

    // -------- Assert
    expect($queries)->toBe($expected)->and($missing)->toBeNull();
});

it('can normalize data', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $data = ['name' => 'Oleg Sereda', 'email' => 'my@email.com'];
    $object = TestData::from($data);

    // -------- Act
    $empty = $repository->normalizeData([]);
    $null = $repository->normalizeData(null);
    $normalized = $repository->normalizeData($object);

    // -------- Assert
    expect($empty)->toBeArray()->toBeEmpty()
        ->and($null)->toBeNull()
        ->and($normalized)->toBeArray()->toMatchArray($data);
});

it('can upsert user', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $email = 'upsert@example.com';
    $password = Hash::make('654321');
    $insert = [
        'name'     => 'Upsert User', 'email' => $email,
        'password' => Hash::make('123456'), 'is_active' => true, 'is_admin' => false,
    ];
    $update = [
        'name'     => 'Upsert User 2', 'email' => $email,
        'password' => $password, 'is_active' => false, 'is_admin' => true,
    ];
    $partial = UserData::from([
        'name'     => 'Upsert User 3', 'email' => $email,
        'password' => Hash::make('6543211'), 'is_active' => true, 'is_admin' => false,
    ]);

    // -------- Act
    $insertedCount = $repository->upsert($insert, 'email');
    $inserted = $repository->query()->first()->toArray();
    $countAfterInsert = $repository->count();
    $updatedCount = $repository->upsert($update, 'email');
    $updated = $repository->query()->first()->getAttributes();
    $countAfterUpdate = $repository->count();
    $partialCount = $repository->upsert($partial, 'email', ['name']);
    $final = $repository->query()->first()->getAttributes();
    $countAfterPartialUpdate = $repository->count();

    // -------- Assert
    expect($insertedCount)->toBeGreaterThan(0)
        ->and($inserted)->toMatchArray([
            'email' => $email, 'name' => 'Upsert User', 'is_active' => true, 'is_admin' => false,
        ])
        ->and($countAfterInsert)->toBe(1)
        ->and($updatedCount)->toBeGreaterThan(0)
        ->and($updated)->toMatchArray([
            'email'     => $email, 'name' => 'Upsert User 2', 'password' => $password,
            'is_active' => 0, 'is_admin' => 1,
        ])
        ->and($countAfterUpdate)->toBe(1)
        ->and($partialCount)->toBeGreaterThan(0)
        ->and($final)->toMatchArray([
            'email'     => $email, 'name' => 'Upsert User 3', 'password' => $password,
            'is_active' => 0, 'is_admin' => 1,
        ])
        ->and($countAfterPartialUpdate)->toBe(1);
});

it('can push user with all his relations', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $model = $repository->create([
        'name'      => 'Sereda Oleg', 'email' => 'my@email.com', 'password' => '123456',
        'is_active' => true, 'is_admin' => false,
    ]);
    $model->addresses->push(new UserAddress([
        'first_line' => '123 Main St', 'zip_code' => '12345', 'user_id' => $model->getKey(),
    ]));
    $model->addresses->push(new UserAddress([
        'first_line' => '321 Main St', 'zip_code' => '54321', 'user_id' => $model->getKey(),
    ]));

    // -------- Act
    $repository->push($model);
    $initialAddressCount = UserAddress::count();
    $model = $model->fresh(['addresses']);
    $model->addresses[1]->first_line = 'Oleg street';
    $repository->push($model);

    // -------- Assert
    expect($initialAddressCount)->toBe(2);
    assertDatabaseHas('users', ['name' => 'Sereda Oleg']);
    assertDatabaseCount('user_addresses', 2);
    assertDatabaseHas('user_addresses', ['first_line' => 'Oleg street']);
});

it('can get pessimistic lock', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $model = $repository->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    // -------- Act
    $result = $repository->transaction->withTrashed->getLock($model->getKey());

    // -------- Assert
    expect($result->name)->toBe('Oleg Sereda');
});

it('can run transaction pessimistic lock', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $model = $repository->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);
    Event::fake([$updatedEvent = 'eloquent.updated: ' . User::class]);
    $transactionLevel = null;
    $sharedLevel = null;

    // -------- Act
    $withTrashed = $repository->transaction->withTrashed->shouldUseTrashed();
    $withoutTrashed = $repository->transaction->shouldUseTrashed();
    $result = $repository->transaction->withTrashed->onLevel(1)->forUpdate(
        $model->getKey(),
        function (User $model, RepositoryContract $repository) use (&$transactionLevel): User {
            $transactionLevel = DB::transactionLevel();
            $repository->update($model, ['name' => 'Oleg Sereda 2']);

            return $model;
        }
    );
    $firstName = $result->name;
    $shared = $repository->transaction->withTrashed->quietly->asShared()->forUpdate(
        $model->getKey(),
        function (User $model, RepositoryContract $repository) use (&$sharedLevel): User {
            $sharedLevel = DB::transactionLevel();
            $repository->update($model, ['name' => 'Oleg Sereda 3']);

            return $model;
        }
    );

    // -------- Assert
    expect($withTrashed)->toBeTrue()
        ->and($withoutTrashed)->toBeFalse()
        ->and($transactionLevel)->toBe(2)
        ->and($firstName)->toBe('Oleg Sereda 2')
        ->and($sharedLevel)->toBe(1)
        ->and($shared->name)->toBe('Oleg Sereda 3');
    Event::assertDispatchedTimes($updatedEvent, 1);
});

it('can run transaction pessimistic lock with query update', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $model = $repository->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);
    $transactionLevel = null;

    // -------- Act
    $withTrashed = $repository->transaction->withTrashed->shouldUseTrashed();
    $withoutTrashed = $repository->transaction->shouldUseTrashed();
    $exception = $this->captureException(fn() => $repository->transaction->withTrashed
        ->onLevel(1)
        ->withQuery(static fn(Builder $query): Builder => $query->where('is_active', false)->where('is_admin', false))
        ->getLock($model->getKey()));
    $result = $repository->transaction->withTrashed->onLevel(1)
        ->withQuery(fn(Builder $query): Builder => $query->where('is_active', true)->where('is_admin', false))
        ->forUpdate(
            $model->getKey(),
            function (User $model, RepositoryContract $repository) use (&$transactionLevel): User {
                $transactionLevel = DB::transactionLevel();
                $repository->update($model, ['name' => 'Oleg Sereda 2']);

                return $model;
            }
        );

    // -------- Assert
    expect($withTrashed)->toBeTrue()
        ->and($withoutTrashed)->toBeFalse()
        ->and($exception)->toBeInstanceOf(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and($exception->getMessage())->toContain('No query results for model [Tests\\_fixtures\\User].')
        ->and($transactionLevel)->toBe(2)
        ->and($result->name)->toBe('Oleg Sereda 2');
});

it('can delete record by primary key', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $model = $repository->quietly->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    // -------- Act
    $existedBefore = $repository->query()->whereKey($model->getKey())->exists();
    $repository->deleteBy($model, 'id');
    $existsAfter = $repository->query()->whereKey($model->getKey())->exists();

    // -------- Assert
    expect($existedBefore)->toBeTrue()->and($existsAfter)->toBeFalse();
});

it('can delete record by any field', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $model = $repository->quietly->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    // -------- Act
    $existedBefore = $repository->query()->whereKey($model->getKey())->exists();
    $repository->deleteBy('my@email.com', 'email');
    $existsAfter = $repository->query()->whereKey($model->getKey())->exists();

    // -------- Assert
    expect($existedBefore)->toBeTrue()->and($existsAfter)->toBeFalse();
});

it('can run transaction directly as callback', function () {
    // -------- Arrange
    Event::fake([
        $creatingEvent = 'eloquent.creating: ' . User::class,
        $createdEvent = 'eloquent.created: ' . User::class,
    ]);
    $repository = new UserRepository;
    $data = [
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ];
    $existedAfterCreate = null;
    $existsAfterDelete = null;

    // -------- Act
    $result = $repository->transaction->run(function ($repository) use ($data, &$existedAfterCreate, &$existsAfterDelete) {
        $model = $repository->create($data);
        $existedAfterCreate = $repository->query()->whereKey($model->getKey())->exists();
        $repository->deleteBy('my@email.com', 'email');
        $existsAfterDelete = $repository->query()->whereKey($model->getKey())->exists();

        return 'My result';
    });

    // -------- Assert
    expect($result)->toBe('My result')
        ->and($existedAfterCreate)->toBeTrue()
        ->and($existsAfterDelete)->toBeFalse();
    Event::assertDispatched($creatingEvent);
    Event::assertDispatched($createdEvent);
});

it('can run transaction directly as callback quietly', function () {
    // -------- Arrange
    Event::fake([
        $creatingEvent = 'eloquent.creating: ' . User::class,
        $createdEvent = 'eloquent.created: ' . User::class,
    ]);
    $repository = new UserRepository;
    $data = [
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ];
    $existedAfterCreate = null;
    $existsAfterDelete = null;

    // -------- Act
    $result = $repository->transaction->quietly->run(function ($repository) use ($data, &$existedAfterCreate, &$existsAfterDelete) {
        $model = $repository->create($data);
        $existedAfterCreate = $repository->query()->whereKey($model->getKey())->exists();
        $repository->deleteBy('my@email.com', 'email');
        $existsAfterDelete = $repository->query()->whereKey($model->getKey())->exists();

        return 'My quietly result';
    });

    // -------- Assert
    expect($result)->toBe('My quietly result')
        ->and($existedAfterCreate)->toBeTrue()
        ->and($existsAfterDelete)->toBeFalse();
    Event::assertNotDispatched($creatingEvent);
    Event::assertNotDispatched($createdEvent);
});

it('can fetch by id', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $user = $repository->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    // -------- Act
    $result = $repository->findById($user->getKey(), ['email']);

    // -------- Assert
    expect($result->email)->toBe($user->email)
        ->and($result->toArray())->toBe(['email' => $user->email]);
});

it('can fetch by any field', function () {
    // -------- Arrange
    $repository = new UserRepository;
    $user = $repository->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    // -------- Act
    $byName = $repository->findBy('name', $user->name, ['email']);
    $byStatus = $repository->findBy('is_active', $user->is_active, ['email']);

    // -------- Assert
    expect($byName->email)->toBe($user->email)
        ->and($byName->toArray())->toBe(['email' => $user->email])
        ->and($byStatus->email)->toBe($user->email)
        ->and($byStatus->toArray())->toBe(['email' => $user->email]);
});
