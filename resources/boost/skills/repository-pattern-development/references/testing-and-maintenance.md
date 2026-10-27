# Tests, measurements, and maintaining this skill

[Skill entrypoint](../SKILL.md)

## Choose the environment before the assertion

In the package checkout:

- `tests/Unit/RepositoryTest.php`: standard repository behavior.
- `tests/Unit/OptiomisticLockTest.php`: model/repository version checks; preserve the existing filename.
- `tests/Unit/ServiceTest.php`: command hooks and events.
- `tests/Cache/RepositoryCacheTest.php`: result/dependency caching.
- `tests/Measurements/RepositoryCacheMeasurementTest.php`: direct/miss/hit measurements.
- `tests/_fixtures/RepositoryCacheTestCase.php`: actual cache reads and database commits without RefreshDatabase's outer transaction.

Tests are export-ignored from package distributions. These paths are for the source checkout, not something to assume exists in a consuming application's vendor directory. In an application, use its own test structure and available runner.

For caching tests, a normal `RefreshDatabase` transaction can make every cached read bypass shared cache. It can also change after-commit behavior through Laravel's testing transaction manager. Do not mistake such a test for a real hit or an actual outermost commit.

Use an isolated database without an automatic outer transaction for cache behavior, and explicit transactions for commit/rollback cases. Serialized ArrayStore avoids object-reference aliasing when testing cached model snapshots. It is not a production Redis benchmark.

## Pest and three-zone AAA

For package tests, use Pest `it()`, `expect()`, datasets, and exactly these zones:

~~~php
use Tests\_fixtures\RepositoryCacheTestCase;

pest()->extend(RepositoryCacheTestCase::class);

it('invalidates a cached empty list after creating a matching user', function () {
    // -------- Arrange
    $repository = $this->repository;
    $repository->cache->usersNamed('Oleg Sereda');

    // -------- Act
    $this->createUser();
    $results = $repository->cache->usersNamed('Oleg Sereda');

    // -------- Assert
    expect($results)->toHaveCount(1)
        ->and($repository->calls('usersNamed'))->toBe(2);
});
~~~

Reuse existing User model, migrations, and creation payloads. The fixture creates users with `name`, `email`, `password`, `is_active`, and `is_admin`; do not create a second test schema just to test the same behavior.

Keep operations in Act and assertions on captured observations in Assert. For multi-stage operations, capture intermediate versions, transaction levels, or counts during Act and assert them at the end. Exception capture helpers can execute the failing call during Act rather than hiding execution inside a later `toThrow()` assertion.

The cache fixture stores mutable test state in an ArrayObject on a readonly repository. Use `offsetSet()` / `offsetUnset()` rather than offset assignment when PhpStorm flags it as a readonly property write.

In consuming applications, preserve the user's existing testing framework and conventions instead of converting unrelated tests to this package's style.

## Select meaningful cases

For a read/cache change, choose relevant cases rather than copying a universal test matrix:

- Miss then hit, including null/false/zero/empty results.
- Equivalent named/default arguments; tenant/filter changes producing distinct keys.
- Scope changes and expiry; empty lists becoming non-empty after creation.
- Old/new owner invalidation and shared aggregates.
- Nested cached hits/misses, direct inner calls, exception cleanup.
- Repository flush during an active read; no stale publication after a recorded version changes.
- Disabled caching, missing scope override, unsupported driver, invalid method/argument/result types.
- Independent frames for interleaved repository clones; sequential singleton cleanup.

For writes and locking, select applicable success/failure, stale model, rollback, inner rollback, outermost commit, non-default connection, quiet operation, bulk write, and partial relationship-save cases.

Avoid assertions on implementation wording or private frame layout when observable result freshness and method/query counts prove the behavior.

## Run focused checks

In this source checkout:

~~~bash
vendor/bin/pest tests/Cache/RepositoryCacheTest.php
vendor/bin/pest tests/Unit/RepositoryTest.php
vendor/bin/pest --exclude-group=measurement
vendor/bin/pest --group=measurement
~~~

In an application, inspect its configured PHPUnit/Pest setup and use targeted `php artisan test` or the existing project command. Use the project's Pint command/configuration for changed PHP files. Honor explicit user constraints on running tests or modifying runtime code.

## Measurement interpretation

Compare identical work for direct reads, misses, and warmed hits. Keep warmup and forced invalidation outside timed intervals; collect results during Act and assert them later.

Use monotonic timing, repeated batches, method/query counts, and an explicit backend/database label. Report microseconds per call (`us/call`) and the aggregation used. Do not assert that a hit must be faster on every machine, or present ArrayStore/SQLite numbers as production Redis latency.

## Maintaining the packaged skill

Edit the package's `resources/boost/skills/repository-pattern-development` source, not a consumer's generated agent copy. After API changes, update the affected reference and inspect examples against installed signatures.

Validate YAML frontmatter, skill/folder naming, relative links, and PHP snippet syntax. Also copy the skill folder into a temporary unrelated location and validate its reference links there: copied skills cannot rely on their original nesting under vendor.

Keep detailed references in this folder and the entrypoint selective. Do not introduce a runtime Boost dependency, service provider registration, application installation, or extra permissions just to ship Markdown resources.

Packaging follows [Laravel Boost's third-party skill convention](https://laravel.com/docs/13.x/boost#third-party-package-skills). Authoring follows the [Agent Skills format](https://agentskills.io/specification) with focused, on-demand references.
