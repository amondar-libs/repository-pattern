<?php

declare(strict_types = 1);

use Amondar\RepositoryPattern\Exceptions\OptimisticLockException;
use Tests\_fixtures\VersionedPost;
use Tests\_fixtures\VersionedPostRepository;

it('should apply version field during creation', function () {
    $model = VersionedPost::create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);

    expect($model->lockVersion())->toBe(1);
});

it('should apply versioning logic during update', function () {
    $model = VersionedPost::create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);

    $model->title = 'My title';
    $model->save();

    expect($model->lockVersion())->toBe(2);
});

it('should throw an exception on race condition', function () {
    $model = VersionedPost::create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);

    $same = $model->fresh();

    $model->title = 'My title';
    $model->save();

    expect($model->lockVersion())->toBe(2);

    $same->title = 'My title 2';

    expect(fn() => $same->save())
        ->toThrow(OptimisticLockException::fire(VersionedPost::class, 1, 2))
        ->and($same->lockVersion())->toBe(1);
});

it('should work with unblocking', function () {
    $model = VersionedPost::create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);

    $same = $model->fresh();

    $model->title = 'My title';
    $model->save();

    expect($model->lockVersion())->toBe(2);

    $same->title = 'My title 2';
    $same->saveUnlocked();

    expect($same->fresh())
        ->lockVersion()->toBe(2)
        ->title->toBe('My title 2')
        ->and($model->fresh())
        ->lockVersion()->toBe(2)
        ->title->toBe('My title 2');
});

it('can run optimistic lock thorough repository', function () {
    $repo = new VersionedPostRepository;

    $model = $repo->create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);

    $same = $model->fresh();

    $model = $repo->update($model, [
        'title' => 'My title',
    ]);

    expect($model->lockVersion())
        ->toBe(2)
        ->and(fn() => $repo->update($same, [
            'title' => 'My title 2',
        ]))
        ->toThrow(OptimisticLockException::fire(VersionedPost::class, 1, 2))
        ->and($same->lockVersion())->toBe(1);
});

it('can run unlocked thorough repository', function () {
    $repo = new VersionedPostRepository;

    $model = $repo->create([
        'title' => $this->faker->title,
        'body'  => $this->faker->sentence(5),
    ]);

    $same = $model->fresh();

    $model = $repo->unlocked->update($model, [
        'title' => 'My title',
    ]);

    expect($model->lockVersion())
        ->toBe(2)
        ->and($repo->unlocked->update($same, [
            'title' => 'My title 2',
        ]))
        ->title
        ->toBe('My title 2')
        ->lockVersion()
        ->toBe(2)
        ->and($model->fresh())
        ->title
        ->toBe('My title 2')
        ->lockVersion()
        ->toBe(2);
});
