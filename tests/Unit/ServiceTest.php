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
    // -------- Arrange
    Event::fake([CreatingEvent::class, CreatedEvent::class]);
    $service = App::make(\Tests\_fixtures\UserService::class);
    $data = [
        'name'      => 'Amondar-SO',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ];
    $input = UserData::from($data);

    // -------- Act
    $model = $service->create($input);

    // -------- Assert
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
        expect($event->model->getKey())->toBe($model->getKey())
            ->and($event->data)->toMatchArray([...$data, 'email' => 'my+1@email.com']);

        return true;
    });
});

it('can update user', function () {
    // -------- Arrange
    Event::fake([UpdatingEvent::class, UpdatedEvent::class]);
    $service = App::make(\Tests\_fixtures\UserService::class);
    $model = (new UserRepository)->create(UserData::from([
        'name'      => 'Amondar-SO',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]));
    $data = [
        'name'      => 'Amondar-SO-1', 'email' => 'my+1@email.com',
        'is_active' => false, 'is_admin' => true,
    ];
    $updates = UserData::factory()->from($data);

    // -------- Act
    $updated = $service->update($model, $updates);

    // -------- Assert
    expect($updated)
        ->name->toBe('Amondar-SO-1')
        ->email->toBe('my+1@email.com')
        ->password->not->toBeEmpty()
        ->is_active->toBeFalse()
        ->is_admin->toBeTrue();
    Event::assertDispatched(UpdatingEvent::class, function (UpdatingEvent $event) use ($data) {
        expect($event->data)->toMatchArray($data);

        return true;
    });
    Event::assertDispatched(UpdatedEvent::class, function (UpdatedEvent $event) use ($updated, $data) {
        expect($event->model->getKey())->toBe($updated->getKey())
            ->and($event->data)->toMatchArray($data);

        return true;
    });
});

it('should fire exception and rollback transaction', function () {
    // -------- Arrange
    Event::fake([CreatingEvent::class]);
    $service = App::make(\Tests\_fixtures\WrongUserService::class);
    $repository = new UserRepository;
    $data = UserData::from([
        'name'      => 'Amondar-SO',
        'email'     => 'my@email.com',
        'password'  => '123456',
        'is_active' => true,
        'is_admin'  => false,
    ]);

    // -------- Act
    $exception = $this->captureException(fn() => $service->transaction->onLevel(1)->create($data));
    $remainingUsers = $repository->count();

    // -------- Assert
    expect($exception)->toBeInstanceOf(Exception::class)
        ->and($remainingUsers)->toBe(0);
    Event::assertNotDispatched(CreatingEvent::class);
});
