# amondar-libs/repository-pattern

A Laravel repository and service layer with model binding, composable write helpers, optimistic locking, and optional result caching.

- Bind a repository to an Eloquent model with `#[UseModel]`.
- Accept arrays or Spatie Data objects for writes.
- Compose `quietly`, `transaction`, and `unlocked` modifiers.
- Cache explicit read calls and invalidate their dependencies after writes.

For the cache architecture and complete examples, see the [cache guide](docs/cache.md).

## Requirements and installation

- PHP `^8.3`.
- A Laravel application with Eloquent and the database/cache services.
- Package dependencies include `spatie/laravel-data:^4.18`, `spatie/laravel-package-tools:^1.16`, and `amondar-libs/class-attributes:^3.0`. Using DTOs is optional; installing Spatie Data is not.
- The development setup uses Orchestra Testbench `^10.0` and Laravel 12. This is not a claim that Laravel 10, 11, or 13 has been verified.

~~~bash
composer require amondar-libs/repository-pattern
~~~

Laravel package discovery registers the service provider. It also registers the `Blueprint::versionable()` migration macro.

## Basic usage

Define a model with the fields your repository can fill:

~~~php
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'name', 'email', 'password', 'is_active', 'is_admin',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }
}
~~~

Optionally define a DTO:

~~~php
use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public bool $is_active,
        public bool $is_admin,
    ) {}
}
~~~

Bind the repository to the model. The repository must be readonly because the base class is readonly.

~~~php
use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Repository;

/** @extends Repository<User, UserData> */
#[UseModel(User::class)]
readonly class UserRepository extends Repository {}
~~~

~~~php
$users = app(UserRepository::class);

$user = $users->create([
    'name' => 'Jane',
    'email' => 'jane@example.com',
    'password' => 'secret',
    'is_active' => true,
    'is_admin' => false,
]);

$user = $users->update($user, UserData::from([
    'name' => 'Jane Roe',
    'email' => 'jane.roe@example.com',
    'password' => 'updated-secret',
    'is_active' => false,
    'is_admin' => true,
]));

$admins = $users->where('is_admin', true)->orderBy('name')->get();
$selected = $users->findById((string) $user->getKey(), ['email']);
$byEmail = $users->findBy('email', $user->email);
~~~

`create()` and `update()` normalize DTOs with `toArray()` and fill the model. They do not automatically discard null values or provide partial-update DTO semantics. Omit fields you do not want to change, or implement that policy in your service.

Other operations include `upsert()`, `push()`, `deleteBy()`, `existsBy()`, `query()`, `makeModel()`, and `model()`:

~~~php
$users->deleteBy($user);                  // Model primary key.
$users->deleteBy('jane@example.com', 'email');
$users->deleteBy($user, 'email');         // Value of the model's email attribute.
~~~

Missing methods are forwarded to a fresh Eloquent builder. Missing `#[UseModel]` configuration throws `RepositoryModelNotFound` during construction.

## Quiet operations and transactions

These modifiers use the same immutable `HighOrderRepositoryProxy`. Property access collects options; the final method call performs the operation.

~~~php
$users->quietly->update($user, ['is_active' => true]);

$users->transaction->quietly->update($user, ['is_active' => false]);
$users->quietly->transaction->update($user, ['is_active' => false]);

$result = $users->transaction->run(function (UserRepository $repository) use ($user) {
    return $repository->update($user, ['name' => 'Jane Smith']);
}, attempts: 3);
~~~

- `quietly` uses the model's `withoutEvents()` scope. Required event-driven initialization must be handled separately.
- `transaction` wraps ordinary method calls and `run()` in `DB::transaction()`. These calls start a transaction even when another transaction is active.
- `run()` receives the real repository, so typed callbacks work.
- Only the method executed through the proxy is wrapped. For example, `$users->quietly->query()->get()` executes `get()` after the quiet scope has ended.
- Transaction helpers use Laravel's default database connection. For a model on another connection, start the transaction on that connection explicitly.

## Pessimistic locking

~~~php
$user = $users->transaction->forUpdate(
    $user->getKey(),
    function (User $locked, UserRepository $repository): User {
        return $repository->update($locked, ['name' => 'Jane Locked']);
    }
);
~~~

`forUpdate()` has a depth rule: it opens a transaction only when the default connection's current transaction level equals the configured level, which defaults to zero. Otherwise, it uses the existing context. `onLevel()` changes this depth condition, not SQL isolation.

~~~php
$user = $users->transaction
    ->quietly
    ->withTrashed
    ->asShared()
    ->withQuery(fn ($query) => $query->where('is_admin', false))
    ->forUpdate($user->getKey(), fn ($locked, $repository) => $locked);
~~~

`asShared()` changes the query to a shared lock. `withTrashed` applies to lock queries when the model supports soft deletes; it cannot be combined with ordinary proxied repository calls. Direct `getLock()` only fetches the locked row: the caller must provide the surrounding transaction.

## Optimistic locking

Add a version column:

~~~php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->versionable('version');
    $table->timestamps();
});
~~~

~~~php
use Amondar\RepositoryPattern\Attributes\VersionField;
use Amondar\RepositoryPattern\Concerns\HasOptimisticLock;
use Amondar\RepositoryPattern\Contracts\Lockable;
use Illuminate\Database\Eloquent\Model;

#[VersionField('version')]
class Post extends Model implements Lockable
{
    use HasOptimisticLock;

    protected $fillable = ['title', 'body'];
}
~~~

Creation initializes the version to 1. A dirty model update checks the previous version and increments it. A stale update throws `OptimisticLockException`. Bulk builder writes and `upsert()` do not run this per-model save logic.

Quiet creation suppresses the trait's creating listener. If you create versioned records quietly, initialize the required version through an appropriate filling policy or database default.

~~~php
use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Repository;

/** @extends Repository<Post> */
#[UseModel(Post::class)]
readonly class PostRepository extends Repository {}

$posts = app(PostRepository::class);
$post = $posts->create(['title' => 'Original', 'body' => 'Content']);
$stale = $post->fresh();

$posts->update($post, ['title' => 'First edit']); // Version 2.

// A normal update of $stale would now throw OptimisticLockException.
$posts->unlocked->transaction->quietly->update($stale, ['title' => 'Override']);
~~~

`unlocked` requires a model implementing `Lockable`; `unlock` is an alias. It disables the optimistic version predicate, but still increments the version derived from the model instance. It does not disable pessimistic database locks. At model level, use `saveUnlocked()` or `Post::unlocked($callback)`. The initial version can be configured through `Post::$defaultLockVersion`.

## Result caching

Caching is disabled by default. Override `usesCache()`, define a model scope, and declare matching read dependencies before querying. A tagged Laravel cache store, such as Redis, is required.

~~~php
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** @property-read HighOrderRepositoryCacheProxy<User, UserData, CachedUserRepository> $cache */
readonly class CachedUserRepository extends UserRepository
{
    public function usesCache(): bool
    {
        return true;
    }

    protected static function nameScope(string $name): string
    {
        return 'users:name:' . $name;
    }

    protected function getModelBaseScope(Model $model): string
    {
        return self::nameScope($model->name);
    }

    public function usersNamed(string $name): Collection
    {
        $this->cache->dependsOn(self::nameScope($name));

        return $this->query()->where('name', $name)->get();
    }
}

$cachedUsers = new CachedUserRepository(cache()->store('redis'));
$before = $cachedUsers->cache->ttl(100)->usersNamed($user->name);
$cachedUsers->update($user, ['name' => 'Jane Cached']); // Invalidates old and new scopes.
$after = $cachedUsers->cache->ttl(100)->usersNamed('Jane Cached');
~~~

The first argument of a cached method must be an integer, string, or persisted unchanged Model. All arguments form the cache key. Use `->cache` for reads; write methods must execute normally. Base writes schedule invalidation after the model connection's outermost commit. Cached reads bypass shared cache inside an active model-connection transaction.

See the [cache guide](docs/cache.md) for creation/deletion, scoped lists, empty results, cross-repository dependencies, TTLs, cloning, concurrency, and current limitations.

## Service layer

The service base class is not readonly; subclasses must also be non-readonly. Constructor dependencies can still be readonly.

~~~php
use Amondar\RepositoryPattern\Concerns\HasCreateCommand;
use Amondar\RepositoryPattern\Concerns\HasUpdateCommand;
use Amondar\RepositoryPattern\Service;

/** @extends Service<User, UserData, UserRepository> */
class UserService extends Service
{
    /**
     * @use HasCreateCommand<User, UserData, UserRepository>
     * @use HasUpdateCommand<User, UserData, UserRepository>
     */
    use HasCreateCommand, HasUpdateCommand;

    public function __construct(private readonly UserRepository $users) {}

    protected function repository(): UserRepository
    {
        return $this->users;
    }
}
~~~

The traits provide hooks such as `creatingHook()`, `createdHook()`, `updatingHook()`, and `updatedHook()`. Override `storeModelRelations()` and `shouldLoadRelationsAfterChangesApplied()` when needed. Those relation writes need their own cache scopes if they affect other cached views.

~~~php
$service = app(UserService::class);
$user = $service->transaction->create(UserData::from([
    'name' => 'Service User',
    'email' => 'service@example.com',
    'password' => 'secret',
    'is_active' => true,
    'is_admin' => false,
]));
~~~

The service transaction proxy opens a transaction only at its configured depth, default zero, and otherwise runs within the existing context. `onLevel(1)` allows a new transaction at level 1. Explicit Laravel events implementing `ShouldDispatchAfterCommit` follow Laravel's commit/rollback handling; ordinary custom events are not automatically deferred.

## Types and PhpStorm

Base types use `Repository<TModel, TData>` and `Service<TModel, TData, TRepository>`. Model types extend Eloquent `Model`, and `TData` defaults to Spatie `Data`.

For custom method completion through proxies, PhpStorm may need concrete annotations on each repository rather than inherited `static` alone:

~~~php
/**
 * @extends Repository<User, UserData>
 * @property-read \Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy<User, UserData, UserRepository> $cache
 * @property-read \Amondar\RepositoryPattern\Proxies\HighOrderRepositoryProxy<User, UserData, UserRepository> $quietly
 * @property-read \Amondar\RepositoryPattern\Proxies\HighOrderRepositoryProxy<User, UserData, UserRepository> $transaction
 */
~~~

Add the equivalent `unlocked` and `unlock` annotations when used. A child repository introducing new methods may need its own concrete annotations. Proxy method names are reserved: for example, `$repository->cache->ttl()` configures caching rather than invoking a repository method named `ttl`.

## Exceptions and extension points

- `RepositoryModelNotFound`: the repository has no model attribute.
- `OptimisticLockException`: a versioned model update affects no matching row.
- `ModelNotSaved`: used by the create-command trait when the model was not created.
- Cache configuration and scope failures use `RuntimeException`; invalid cached signatures and arguments use `LogicException` or `InvalidArgumentException`.

Use custom methods and the protected filling/scope hooks for application behavior. If you override a base write method, delegate to its parent implementation or reproduce its invalidation policy; bypassing it also bypasses automatic cache maintenance.

`WithAttributesCache` is an existing contract, but the current repository and optimistic-lock attribute lookups do not call `getAttributesCache()` or configure an attribute parser cache. Implementing that contract alone does not enable metadata caching. The result cache described here is a separate system.

## Laravel Boost skill

The package ships a [repository-pattern-development skill](resources/boost/skills/repository-pattern-development/SKILL.md) with focused references for repositories, services, modifiers, locking, cache reads/invalidation, concurrency, IDE types, and tests. It includes a short workflow for changes that span those boundaries.

In a consuming application that already has Laravel Boost installed, run:

~~~bash
php artisan boost:install
~~~

Select this package's skills when prompted. After package updates, refresh the installed resources with `php artisan boost:update`. The skill follows [Boost's package skill format](https://laravel.com/docs/13.x/boost#third-party-package-skills); no runtime Boost dependency or PHP registration was added to this package.

## Development

~~~bash
vendor/bin/pest --exclude-group=measurement
vendor/bin/pest --group=measurement
~~~

The cache tests use the existing User fixtures and migrations without a wrapping `RefreshDatabase` transaction. Measurements compare direct calls, cache misses, and hits using serialized ArrayStore and SQLite. They assert results and query counts, not timing thresholds; their timings do not predict Redis or production performance.

## License

MIT. See [LICENSE.md](LICENSE.md).
