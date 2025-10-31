<?php

declare( strict_types = 1 );

namespace Tests\Unit;

use Amondar\RepositoryPattern\Contracts\RepositoryContract;
use Amondar\RepositoryPattern\Exceptions\RepositoryModelNotFound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\resources\ChildUserRepository;
use Tests\resources\TestData;
use Tests\resources\User;
use Tests\resources\UserAddress;
use Tests\resources\UserData;
use Tests\resources\UserRepository;
use Tests\resources\WrongUserRepository;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

it('can make user model', function () {
    $repository = new UserRepository;

    expect($repository->makeModel())->toBeInstanceOf(User::class);
})->group('repository');

it('can create user', function () {
    Event::fake([
        $creatingEvent = 'eloquent.creating: ' . User::class,
        $createdEvent = 'eloquent.created: ' . User::class,
    ]);

    $repository = new UserRepository;

    expect($repository->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]))
        ->exists->toBeTrue()
        ->name->toBe('Oleg Sereda')
        ->email->toBe('my@email.com')
        ->is_active->toBeTrue()
        ->is_admin->toBeFalse();

    Event::assertDispatched($creatingEvent);
    Event::assertDispatched($createdEvent);
})->group('repository');

it('can create user quietly', function () {
    Event::fake([
        $creatingEvent = 'eloquent.creating: ' . User::class,
        $createdEvent = 'eloquent.created: ' . User::class,
    ]);

    $repository = new ChildUserRepository;

    // Global laravel db is transactional.
    expect(DB::transactionLevel())
        ->toBe(1)
        ->and($repository->transaction->quietly->create([
            'name'      => 'Oleg Sereda',
            'email'     => 'my@email.com',
            'password'  => '123456',
            'is_active' => true,
            'is_admin'  => false,
        ]))
        ->exists->toBeTrue()
        ->name->toBe('Oleg Sereda')
        ->email->toBe('my@email.com')
        ->is_active->toBeTrue()
        ->is_admin->toBeFalse();

    assertDatabaseHas('users', [
        'name' => 'Oleg Sereda',
    ]);

    expect(DB::transactionLevel())->toBe(1);

    Event::assertNotDispatched($creatingEvent);
    Event::assertNotDispatched($createdEvent);
})->group('repository');

it('can update user', function () {
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

    expect($repository->update($model, [
        'name'      => 'Oleg Sereda 2',
        'email'     => 'my+1@email.com',
        'password'  => '1234567',
        'is_active' => false,
        'is_admin'  => true,
    ]))
        ->name->toBe('Oleg Sereda 2')
        ->email->toBe('my+1@email.com')
        ->is_active->toBeFalse()
        ->is_admin->toBeTrue();

    Event::assertDispatched($updatingEvent);
    Event::assertDispatched($updatedEvent);
})->group('repository');

it('can update user quietly', function () {
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

    expect($repository->quietly->update($model, [
        'name'      => 'Oleg Sereda 2',
        'email'     => 'my+1@email.com',
        'password'  => '1234567',
        'is_active' => false,
        'is_admin'  => true,
    ]))
        ->name->toBe('Oleg Sereda 2')
        ->email->toBe('my+1@email.com')
        ->is_active->toBeFalse()
        ->is_admin->toBeTrue();

    Event::assertNotDispatched($updatingEvent);
    Event::assertNotDispatched($updatedEvent);
})->group('repository');

it('will throw an exception without attribute', function () {
    expect(fn() => new WrongUserRepository)->toThrow(
        RepositoryModelNotFound::make(WrongUserRepository::class)
    );
})->group('repository');

it('can apply builder calls', function () {
    $repository = new UserRepository;

    expect($repository->whereKey(1)->toSql())
        ->toBe('select * from "users" where "users"."id" = ?')
        ->and($repository->where('id', '>=', 1)->toSql())
        ->toBe('select * from "users" where "id" >= ?')
        // select specific columns
        ->and($repository->select('id', 'name')->toSql())
        ->toBe('select "id", "name" from "users"')
        // order by
        ->and($repository->orderBy('name')->toSql())
        ->toBe('select * from "users" order by "name" asc')
        // limit / take
        ->and($repository->take(10)->toSql())
        ->toBe('select * from "users" limit 10')
        // offset without limit (SQLite grammar uses limit -1)
        ->and($repository->offset(5)->toSql())
        ->toBe('select * from "users" offset 5')
        // where null
        ->and($repository->whereNull('deleted_at')->toSql())
        ->toBe('select * from "users" where "deleted_at" is null')
        // where in
        ->and($repository->whereIn('id', [ 1, 2, 3 ])->toSql())
        ->toBe('select * from "users" where "id" in (?, ?, ?)')
        // where between
        ->and($repository->whereBetween('id', [ 1, 10 ])->toSql())
        ->toBe('select * from "users" where "id" between ? and ?')
        // or where
        ->and($repository->where('name', '=', 'John')->orWhere('email', 'like', '%@example.com')->toSql())
        ->toBe('select * from "users" where "name" = ? or "email" like ?')
        // group by and having
        ->and($repository->groupBy('is_active')->having('is_active', '=', 1)->toSql())
        ->toBe('select * from "users" group by "is_active" having "is_active" = ?')
        // firstWhere appends limit 1
        ->and($repository->firstWhere('name', 'John'))
        ->toBeNull()
        // simple join
        ->and($repository->join('profiles', 'profiles.user_id', '=', 'users.id')->toSql())
        ->toBe('select * from "users" inner join "profiles" on "profiles"."user_id" = "users"."id"');
})->group('repository');

it('can normalize data', function () {
    $repository = new UserRepository;

    expect($repository->normalizeData([]))
        ->toBeArray()
        ->toBeEmpty()
        ->and($repository->normalizeData(NULL))
        ->toBeNull()
        ->and($repository->normalizeData(TestData::from([
            'name'  => 'Oleg Sereda',
            'email' => 'my@email.com',
        ])))
        ->toBeArray()
        ->toMatchArray([
            'name'  => 'Oleg Sereda',
            'email' => 'my@email.com',
        ]);
})->group('repository');

it('can upsert user', function () {
    $repository = new UserRepository;

    // Insert a new user via upsert using unique email
    $affected = $repository->upsert([
        'name'      => 'Upsert User',
        'email'     => $email = 'upsert@example.com',
        'password'  => Hash::make('123456'), // note: upsert does not apply model casts
        'is_active' => true,
        'is_admin'  => false,
    ], 'email');

    expect($affected)->toBeGreaterThan(0);

    assertDatabaseHas('users', [
        'email'     => $email,
        'name'      => 'Upsert User',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    expect($repository->count())->toBe(1);

    // Update existing user via upsert on the same unique key
    $affected = $repository->upsert([
        'name'      => 'Upsert User 2',
        'email'     => $email,
        'password'  => $password = Hash::make('654321'),
        'is_active' => false,
        'is_admin'  => true,
    ], 'email');

    expect($affected)->toBeGreaterThan(0);

    assertDatabaseHas('users', [
        'email'     => $email,
        'name'      => 'Upsert User 2',
        'password'  => $password,
        'is_active' => false,
        'is_admin'  => true,
    ]);

    expect($repository->count())->toBe(1);

    // Update using a Data object to ensure normalization is applied
    $affected = $repository->upsert(UserData::from([
        'name'      => 'Upsert User 3',
        'email'     => $email,
        'password'  => Hash::make('6543211'),
        'is_active' => true,
        'is_admin'  => false,
    ]), 'email', [ 'name' ]);

    expect($affected)->toBeGreaterThan(0);

    assertDatabaseHas('users', [
        'email'     => $email,
        'name'      => 'Upsert User 3',
        'password'  => $password,
        'is_active' => false,
        'is_admin'  => true,
    ]);

    expect($repository->count())->toBe(1);
})->group('repository');

it('can push user with all his relations', function () {
    $repository = new UserRepository;

    $model = $repository->makeModel();

    $model->name = 'Sereda Oleg';
    $model->email = 'my@email.com';
    $model->password = '123456';
    $model->is_active = true;
    $model->is_admin = false;

    $model->save();

    $model->addresses->push(new UserAddress([
        'first_line' => '123 Main St',
        'zip_code'   => '12345',
        'user_id'    => $model->getKey(),
    ]));

    $model->addresses->push(new UserAddress([
        'first_line' => '321 Main St',
        'zip_code'   => '54321',
        'user_id'    => $model->getKey(),
    ]));

    $repository->push($model);

    assertDatabaseHas('users', [
        'name' => 'Sereda Oleg',
    ]);

    assertDatabaseCount('user_addresses', 2);

    $model = $model->fresh([ 'addresses' ]);

    $model->addresses[ 1 ]->first_line = 'Oleg street';

    $repository->push($model);

    assertDatabaseHas('user_addresses', [
        'first_line' => 'Oleg street',
    ]);
})->group('repository');

it('can run transaction pessimistic lock', function () {
    $repository = new UserRepository;

    expect($repository->transaction->withTrashed->shouldUseTrashed())
        ->toBeTrue()
        ->and($repository->transaction->shouldUseTrashed())
        ->toBeFalse();

    $model = $repository->create([
        'name'      => 'Oleg Sereda',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    $result = $repository->transaction->withTrashed->onLevel(1)
                                                   ->forUpdate($model->getKey(),
                                                       function (User $model, RepositoryContract $repository) {
                                                           // Check for the new level of transaction.
                                                           expect(DB::transactionLevel())->toBe(2);

                                                           $repository->update($model, [
                                                               'name' => 'Oleg Sereda 2',
                                                           ]);

                                                           return $model;
                                                       });

    expect($result->name)->toBe('Oleg Sereda 2');

    $result = $repository->transaction->withTrashed->forUpdate($model->getKey(),
        function (User $model, RepositoryContract $repository) {
            // Check that level not changed.
            // Remember that tests running with RefreshDatabase trait, so all db actions are in transactions.
            expect(DB::transactionLevel())->toBe(1);

            $repository->update($model, [
                'name' => 'Oleg Sereda 3',
            ]);

            return $model;
        });

    expect($result->name)->toBe('Oleg Sereda 3');
})->group('repository');
