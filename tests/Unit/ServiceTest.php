<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Tests\_fixtures\CreatedEvent;
use Tests\_fixtures\CreatingEvent;
use Tests\_fixtures\UpdatedEvent;
use Tests\_fixtures\UpdatingEvent;
use Tests\_fixtures\UserData;
use Tests\_fixtures\UserRepository;

it('can create user', function () {
    Event::fake([
        CreatingEvent::class,
        CreatedEvent::class,
    ]);

    $model = App::make(\Tests\_fixtures\UserService::class)->create(
        UserData::from($data = [
            'name'      => 'Amondar-SO',
            'email'     => 'my@email.com',
            'password'  => '123456',
            'is_active' => true,
            'is_admin'  => false,
        ])
    );

    expect($model)
        ->name->toBe('Amondar-SO')
        ->email->not->toBe('my@email.com')->toBe('my+1@email.com')
        ->password->not->toBeEmpty()
        ->is_active->toBeTrue()
        ->is_admin->toBeFalse();

    Event::assertDispatched(CreatingEvent::class, function (CreatingEvent $event) {
        expect($event->email)->toBe('my+1@email.com');

        return true;
    });

    Event::assertDispatched(CreatedEvent::class, function (CreatedEvent $event) use ($model, $data) {
        expect($event->model->getKey())
            ->toBe($model->getKey())
            ->and($event->data)
            ->toMatchArray([
                ...$data,
                'email' => 'my+1@email.com',
            ])
            ->and($event->relations)
            ->toMatchArray([
                'addresses' => [ 'attached' => [ 1, 2, 3 ], 'detached' => [ 4, 5, 6 ] ],
            ]);

        return true;
    });
})->group('service');

it('can update user', function () {
    Event::fake([
        UpdatingEvent::class,
        UpdatedEvent::class,
    ]);

    $model = (new UserRepository)->create(UserData::from([
        'name'      => 'Amondar-SO',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]));

    $model = App::make(\Tests\_fixtures\UserService::class)->update(
        $model,
        UserData::factory()->from($data = [
            'name'      => 'Amondar-SO-1',
            'email'     => 'my+1@email.com',
            'is_active' => false,
            'is_admin'  => true,
        ])
    );

    // Service will unset password if it is NULL.
    unset($data[ 'password' ]);

    expect($model)
        ->name->toBe('Amondar-SO-1')
        ->email->toBe('my+1@email.com')
        ->password->not->toBeEmpty()
        ->is_active->toBeFalse()
        ->is_admin->toBeTrue();

    Event::assertDispatched(UpdatingEvent::class, function (UpdatingEvent $event) use ($data) {
        expect($event->data)->toMatchArray($data);

        return true;
    });

    Event::assertDispatched(UpdatedEvent::class, function (UpdatedEvent $event) use ($model, $data) {
        expect($event->model->getKey())
            ->toBe($model->getKey())
            ->and($event->data)
            ->toMatchArray($data)
            ->and($event->relations)
            ->toMatchArray([
                'addresses' => [ 'attached' => [ 1, 2, 3 ], 'detached' => [ 4, 5, 6 ] ],
            ]);

        return true;
    });
})->group('service');

it('should fire exception and rollback transaction', function () {
    Event::fake([
        CreatingEvent::class,
    ]);

    expect(fn() => App::make(\Tests\_fixtures\WrongUserService::class)->transaction->onLevel(1)->create(
        UserData::from([
            'name'      => 'Amondar-SO',
            'email'     => 'my@email.com',
            'password'  => '123456',
            'is_active' => true,
            'is_admin'  => false,
        ])
    ))->toThrow(Exception::class);

    // Event should wait commit transaction, but it failed.
    Event::assertNotDispatched(CreatingEvent::class);
})->group('service');
