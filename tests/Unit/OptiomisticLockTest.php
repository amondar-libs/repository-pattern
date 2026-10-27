<?php

declare(strict_types = 1);

use Amondar\RepositoryPattern\Exceptions\OptimisticLockException;
use Tests\_fixtures\VersionedPost;
use Tests\_fixtures\VersionedPostRepository;

it('should apply version field during creation', function () {
    // -------- Arrange
    $data = [
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ];

    // -------- Act
    $model = VersionedPost::create($data);

    // -------- Assert
    expect($model->lockVersion())->toBe(1);
});

it('should apply versioning logic during update', function () {
    // -------- Arrange
    $model = VersionedPost::create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);

    // -------- Act
    $model->title = 'My title';
    $model->save();

    // -------- Assert
    expect($model->lockVersion())->toBe(2);
});

it('should throw an exception on race condition', function () {
    // -------- Arrange
    $model = VersionedPost::create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);
    $same = $model->fresh();
    $expected = OptimisticLockException::fire(VersionedPost::class, 1, 2);

    // -------- Act
    $model->title = 'My title';
    $model->save();
    $updatedVersion = $model->lockVersion();
    $same->title = 'My title 2';
    $exception = $this->captureException(fn() => $same->save());

    // -------- Assert
    expect($updatedVersion)->toBe(2)
        ->and($exception)->toBeInstanceOf(OptimisticLockException::class)
        ->and($exception->getMessage())->toBe($expected->getMessage())
        ->and($same->lockVersion())->toBe(1);
});

it('should work with unblocking', function () {
    // -------- Arrange
    $model = VersionedPost::create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);
    $same = $model->fresh();

    // -------- Act
    $model->title = 'My title';
    $model->save();
    $updatedVersion = $model->lockVersion();
    $same->title = 'My title 2';
    $same->saveUnlocked();
    $freshSame = $same->fresh();
    $freshModel = $model->fresh();

    // -------- Assert
    expect($updatedVersion)->toBe(2)
        ->and($freshSame)->lockVersion()->toBe(2)->title->toBe('My title 2')
        ->and($freshModel)->lockVersion()->toBe(2)->title->toBe('My title 2');
});

it('can run optimistic lock thorough repository', function () {
    // -------- Arrange
    $repository = new VersionedPostRepository;
    $model = $repository->create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);
    $same = $model->fresh();
    $expected = OptimisticLockException::fire(VersionedPost::class, 1, 2);

    // -------- Act
    $updated = $repository->update($model, ['title' => 'My title']);
    $exception = $this->captureException(fn() => $repository->update($same, ['title' => 'My title 2']));

    // -------- Assert
    expect($updated->lockVersion())->toBe(2)
        ->and($exception)->toBeInstanceOf(OptimisticLockException::class)
        ->and($exception->getMessage())->toBe($expected->getMessage())
        ->and($same->lockVersion())->toBe(1);
});

it('can run unlocked thorough repository', function () {
    // -------- Arrange
    $repository = new VersionedPostRepository;
    $model = $repository->create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);
    $same = $model->fresh();

    // -------- Act
    $updated = $repository->unlocked->update($model, ['title' => 'My title']);
    $updatedVersion = $updated->lockVersion();
    $unlocked = $repository->unlocked->update($same, ['title' => 'My title 2']);
    $persisted = $model->fresh();

    // -------- Assert
    expect($updatedVersion)->toBe(2)
        ->and($unlocked)->title->toBe('My title 2')->lockVersion()->toBe(2)
        ->and($persisted)->title->toBe('My title 2')->lockVersion()->toBe(2);
});
