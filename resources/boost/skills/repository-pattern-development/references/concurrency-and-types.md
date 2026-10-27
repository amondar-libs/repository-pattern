# Clone isolation, concurrency, and IDE types

[Skill entrypoint](../SKILL.md)

Source: `PACKAGE_ROOT/src/Repository.php::__clone`, both repository proxies, and the concrete repository annotations in package test fixtures.

## Sequential singleton use

Frames follow active nested cache calls, not historical calls. Finally removes each frame, including after exceptions. Ordinary sequential singleton reuse therefore does not grow the stack indefinitely.

Overlapping calls on the same proxy can mix frames. The implementation does not provide automatic Fiber-local or coroutine-local storage.

## Give overlapping tasks independent repository clones

~~~php
$firstRepository = clone $orders;
$secondRepository = clone $orders;

$first = new Fiber(fn () => $firstRepository->cache->ordersForUser(1, 42));
$second = new Fiber(fn () => $secondRepository->cache->ordersForUser(1, 43));
~~~

This illustrates task ownership; Fiber scheduling depends on the application and the work actually yielding.

Repository cloning creates a new cache proxy bound to the cloned repository with empty frames. The injected cache backend and proxy TTL are preserved. Clones of the same concrete class still share keys, tags, and valid results.

Do not replace this with a shallow `clone $orders->cache`: the ArrayObject stack remains shared. `ttl()` intentionally uses that shared stack so declarations made by the real repository contribute to the outer cached call.

`forRepository($repository)` creates a freshly bound proxy with empty frames and preserved TTL/backend. Prefer ordinary repository cloning for application task isolation.

Other object-valued repository properties are still shallow-copied. Model dispatcher state, optimistic-lock static flags, and database connections are not automatically isolated. A subclass overriding `__clone()` must invoke `parent::__clone()`.

## Laravel Concurrency

For the default process driver, resolve inside each child instead of serializing a repository and live cache connection:

~~~php
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\Concurrency;

$results = Concurrency::run([
    static fn () => app(OrderRepository::class)->cache->ordersForUser(1, 42),
    static fn () => app(OrderRepository::class)->cache->ordersForUser(1, 43),
]);
~~~

Process tasks run in separate PHP processes. Fork has separate memory copied from the parent, including any already-active stack snapshot; sync runs sequentially. In-process clone tests are not proof that every process/fork/Octane setup is verified.

Returning values from child processes does not return dependency metadata. An outer cached aggregate launching parallel tasks must explicitly register its combined scopes, or the application must deliberately transport metadata. There is no built-in cross-process merge.

Frames also do not automatically propagate between independently constructed repository objects in one process. Shared backend scopes allow invalidation across those objects, not automatic parent-frame collection.

## Concrete PhpStorm annotations

The proxies carry the model, DTO, and concrete repository as generics and use `@mixin` for custom methods. PhpStorm may lose inherited `static` inference. Put concrete properties on the repository that introduces the custom methods:

~~~php
use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryProxy;
use Amondar\RepositoryPattern\Repository;
use App\Data\UserData;
use App\Models\User;

/**
 * @extends Repository<User, UserData>
 * @property-read HighOrderRepositoryCacheProxy<User, UserData, UserRepository> $cache
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $quietly
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $transaction
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $unlocked
 * @property-read HighOrderRepositoryProxy<User, UserData, UserRepository> $unlock
 */
#[UseModel(User::class)]
readonly class UserRepository extends Repository
{
    public function usersNamed(string $name): \Illuminate\Database\Eloquent\Collection
    {
        return $this->query()->where('name', $name)->get();
    }
}
~~~

This example demonstrates type completion, not cache enablement; add the [cache policy](cache-reads.md) when actual caching is required.

A subclass adding new methods may need its own concrete annotations rather than inheriting a parent repository's concrete type. Fluent proxy methods must retain their generic return arguments so `ttl()`, `onLevel()`, or `withQuery()` do not erase the repository type.

Do not claim a union or intersection makes the proxy an actual instance of the concrete repository. Avoid suppressing all undefined-method inspections to hide inference loss. Runtime tests verify behavior, not IDE completion; state which check was actually performed.
