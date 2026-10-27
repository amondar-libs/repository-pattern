# Repository result caching

[Back to the package README](../README.md) · [Brief cache usage](../README.md#result-caching)

This guide describes the current `HighOrderRepositoryCacheProxy` and the invalidation performed by `Repository`. The cache is opt-in, stores method results, and uses explicit dependency scopes to decide when those results are stale.

## 1. The three identities

Do not confuse these three things:

| Identity | Example | Purpose |
| --- | --- | --- |
| Result key | Hash of `OrderRepository`, `ordersForUser`, and its arguments | Identifies one method result |
| Dependency scope | `orders:tenant:1:user:42` | Describes data that can affect many results |
| Repository tag | `App\Repositories\OrderRepository::class` | Groups all result entries belonging to a concrete repository |

Two methods called with the same arguments still have different result keys. They can share a dependency scope, allowing one invalidation to make both results stale.

A scalar argument such as `42` contributes to a result key. It does not tell the proxy whether 42 means a user, order, or tenant. Your method supplies that meaning through `dependsOn()`.

## 2. A complete scoped repository

The following application example assumes an `orders` table containing `id`, `tenant_id`, `user_id`, `total_cents`, `is_public`, and timestamps. Here tenant/user IDs are integers. Adapt types to your application's schema, such as strings for UUIDs.

A migration can define those fields as follows:

~~~php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id');
    $table->unsignedBigInteger('user_id');
    $table->unsignedInteger('total_cents');
    $table->boolean('is_public')->default(false);
    $table->timestamps();
    $table->index(['tenant_id', 'user_id']);
});
~~~

Define the model:

~~~php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'total_cents', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'total_cents' => 'integer',
            'is_public' => 'boolean',
        ];
    }
}
~~~

Then define the repository:

~~~php
namespace App\Repositories;

use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryProxy;
use Amondar\RepositoryPattern\Repository;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * @extends Repository<Order>
 * @property-read HighOrderRepositoryCacheProxy<Order, Data, OrderRepository> $cache
 * @property-read HighOrderRepositoryProxy<Order, Data, OrderRepository> $quietly
 * @property-read HighOrderRepositoryProxy<Order, Data, OrderRepository> $transaction
 */
#[UseModel(Order::class)]
readonly class OrderRepository extends Repository
{
    public function usesCache(): bool
    {
        return true;
    }

    public static function userScope(int $tenantId, int $userId): string
    {
        return "orders:tenant:{$tenantId}:user:{$userId}";
    }

    public static function orderScope(int $tenantId, int $orderId): string
    {
        return "orders:tenant:{$tenantId}:id:{$orderId}";
    }

    public static function publicScope(int $tenantId): string
    {
        return "orders:tenant:{$tenantId}:public";
    }

    /** @param Order $model */
    protected function getModelBaseScope(Model $model): string
    {
        return self::userScope($model->tenant_id, $model->user_id);
    }

    /** @param Order $model */
    protected function getModelAdditionalScopes(Model $model): array
    {
        $scopes = [self::orderScope($model->tenant_id, (int) $model->getKey())];

        if ($model->is_public) {
            $scopes[] = self::publicScope($model->tenant_id);
        }

        return $scopes;
    }

    public function ordersForUser(
        int $tenantId,
        int $userId,
        bool $publicOnly = false,
    ): Collection {
        $this->cache->dependsOn(
            'orders:bulk',
            self::userScope($tenantId, $userId),
        );

        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->when($publicOnly, fn ($query) => $query->where('is_public', true))
            ->orderBy('id')
            ->get();
    }

    public function orderCount(int $tenantId, int $userId): int
    {
        $this->cache->dependsOn(
            'orders:bulk',
            self::userScope($tenantId, $userId),
        );

        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->count();
    }

    public function publicFeed(int $tenantId): Collection
    {
        $this->cache->dependsOn('orders:bulk', self::publicScope($tenantId));

        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('is_public', true)
            ->orderBy('id')
            ->get();
    }

    public function orderById(int $tenantId, int $orderId): ?Order
    {
        $this->cache->dependsOn('orders:bulk', self::orderScope($tenantId, $orderId));

        return $this->query()
            ->where('tenant_id', $tenantId)
            ->whereKey($orderId)
            ->first();
    }

    public function dashboard(int $tenantId, int $userId): array
    {
        return [
            'orders' => $this->cache->ttl(30)->ordersForUser($tenantId, $userId),
            'count' => $this->cache->orderCount($tenantId, $userId),
        ];
    }

    public function importOrders(array $rows): int
    {
        $affected = $this->upsert(
            $rows,
            ['id'],
            ['tenant_id', 'user_id', 'total_cents', 'is_public'],
        );

        if ($rows !== []) {
            $this->invalidateCacheAfterCommit(
                $this->makeModel()->getConnection(),
                ['orders:bulk'],
            );
        }

        return $affected;
    }

    public function markUserOrdersPublic(int $tenantId, int $userId): int
    {
        $affected = $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->update(['is_public' => true]);

        if ($affected > 0) {
            $this->invalidateCacheAfterCommit(
                $this->makeModel()->getConnection(),
                [
                    self::userScope($tenantId, $userId),
                    self::publicScope($tenantId),
                ],
            );
        }

        return $affected;
    }
}
~~~

The `orders:bulk` scope is a convention chosen for this example. Ordinary scoped writes do not change it. The custom `importOrders()` method changes it after a bulk import so dependent views in other repositories can also refresh.

The base scope does not have to use `user_id`. It can use a record ID, category, account, or a combination of persisted fields. It must return a non-empty string, and it must agree with the scope used by the corresponding read methods. Additional scopes are validated and deduplicated.

Scope strings are not parsed. Choose stable formats and include every required dimension. When arbitrary string components could contain your delimiters, encode or hash a canonical tuple rather than concatenating ambiguous strings.

## 3. Configure the store and enable caching

The base repository's `usesCache()` returns `false`. Override it to return `true`, as above. There is no `$shouldUseCache` property or `SHOULD_USE_CACHE` constant in the current API.

The base `getModelBaseScope()` throws. An enabled repository must override it, even if some reads return scalars. The constructor checks for that override and validates that the cache store supports tags before writes can run.

~~~php
use App\Repositories\OrderRepository;

// Use the application container's Cache Repository binding.
$orders = app(OrderRepository::class);

// Or inject a configured Laravel cache repository explicitly.
$orders = new OrderRepository(cache()->store('redis'));
~~~

The injected type is `Illuminate\Contracts\Cache\Repository`. The proxy currently requires an actual `Illuminate\Cache\Repository` implementation whose `supportsTags()` returns true. A custom implementation of the contract is not enough by itself.

Redis and Memcached are usual shared tagged stores. ArrayStore supports tags and is used in the tests, but its values are local to its PHP process. File and database stores do not meet this proxy's tag requirement.

If a subclass defines a constructor, it must call `parent::__construct($cacheRepository)`. The nullable default preserves direct construction such as `new OrderRepository()`; the proxy resolves the application's cache binding when no dependency is supplied.

## 4. Direct calls, misses, and hits

~~~php
use App\Repositories\OrderRepository;

$orders = new OrderRepository(cache()->store('redis'));

// Always executes the repository method.
$direct = $orders->ordersForUser(1, 42);

// A miss executes the method and stores its result.
$first = $orders->cache->ttl(100)->ordersForUser(1, 42);

// A valid hit returns the stored result without executing the method.
$second = $orders->cache->ttl(100)->ordersForUser(1, 42);
~~~

Only explicit `->cache` calls request result caching. There is no method allowlist. If caching is disabled, the proxy delegates directly.

On an enabled call outside a transaction, the proxy:

1. Resolves the declared method, binds named/positional arguments, and applies defaults.
2. Generates the result key and looks up a repository-tagged entry.
3. Checks the entry's dependency versions before returning a hit.
4. On a miss, opens an in-memory dependency frame and records the repository-wide dependency.
5. Collects applicable Model-argument scopes, then executes the real method.
6. Collects applicable Model-result scopes and checks all recorded versions again.
7. Stores the result if the versions are still current, and removes the frame in `finally`.

If a registered dependency changes while the method runs, the caller still receives that execution's result, but the proxy skips storing it. This is not a guarantee of a transactionally consistent snapshot.

~~~php
$orders->cache->ttl(100)->ordersForUser(1, 42, publicOnly: true);
~~~

Named/default argument normalization makes equivalent calls share a key. Different method names or argument values have different keys.

## 5. Cached method and argument requirements

These requirements apply when caching is enabled and not bypassed by a transaction:

- The method must be a declared public instance method. Builder methods handled only through repository `__call()` cannot be cached directly.
- Its declared first argument must resolve to an integer, string, or persisted unchanged Model. A default first parameter can satisfy this rule. A method with no parameters cannot.
- All arguments participate in the key, not only the first.
- Supported argument values are primitives, null, arrays of supported values, enums, and persisted unchanged Models. Reference and variadic parameters are rejected.
- Model identity uses model class, connection name, and primary key. Dirty or unsaved Model arguments are rejected. Loaded relations and arbitrary instance state do not participate.
- Array ordering is preserved; the proxy does not canonicalize associative arrays as unordered sets.
- Methods should return materialized results. A top-level Eloquent/query builder, generator, or non-Collection traversable is rejected. Nested return values must also be suitable for the selected store's serialization.

For example, call a declared `ordersForUser()` method that ends in `get()`. Do not try `$orders->cache->where(...)->get()`.

Never cache mutation methods, lock-taking reads, or operations with required side effects. A cache hit skips the method body. The proxy does not detect that a method named `updateUserFlag()` writes data.

Proxy methods such as `ttl()`, `forget()`, `invalidate()`, `dependsOn()`, `generateKey()`, `getStore()`, and `forRepository()` reserve those names on the cached path.

### Tenant and authorization context

Keys do not automatically include the authenticated user, locale, current tenant, default database connection, or global query-scope state. Put the context that changes the result into the method arguments.

The example's `ordersForUser($tenantId, $userId)` is deliberate. Reading an implicit tenant from a global context inside `ordersForUser($userId)` could reuse a result from another tenant. A dependency declared inside the method does not repair an ambiguous key: on a hit, that method body does not run.

## 6. What is stored and when it expires

A result entry conceptually contains:

~~~php
$entry = [
    'value' => $result,
    'dependencies' => [
        'repository-dependency:<hash-of-repository-scope>' => 'random-token-A',
        'repository-dependency:<hash-of-user-scope>' => 'random-token-B',
    ],
];
~~~

Dependency versions are separate keys in the same underlying cache repository. They are not stored inside the repository's tagged result namespace, allowing explicit scopes to be shared across repository classes.

| Entry | Lifetime |
| --- | --- |
| Method result | TTL configured on the calling proxy; default 300 seconds |
| Version initialized by `dependsOn()` | `max(proxy TTL, 300)` seconds |
| Version replaced by `invalidate()` | `max(proxy TTL, 300)` seconds from invalidation |
| Dependency frame | Memory only, until its method completes |
| Laravel tag metadata | Managed by the cache implementation, outside the proxy's TTL policy |

`ttl()` requires a positive number of seconds. It returns a clone without changing the base proxy:

~~~php
$short = $orders->cache->ttl(10)->ordersForUser(1, 42);
$default = $orders->cache->ordersForUser(1, 43); // Default remains 300 seconds.
~~~

TTL is not part of the result key. If an entry already exists, asking for a different TTL does not replace it or extend its expiry. The new TTL is used on the next store after a miss.

`dependsOn()` uses `add()`: reading an existing dependency version does not refresh its TTL. A call to `$this->cache->dependsOn()` inside a method uses that repository's base proxy, which normally has the 300-second default, even if the outer call used a longer TTL clone. A dependency can therefore expire before a result. This causes an early miss, not reuse of stale data.

Missing or evicted dependency versions fail validation. Recreated versions use fresh random tokens, so old results do not become valid again. Invalidation changes the token immediately; it does not scan and delete each result entry. Stale entries are overwritten on a later read or expire naturally.

Store serialization matters. The proxy does not deep-clone result objects. Shared stores normally serialize them; ArrayStore can keep references unless serialization is enabled. The measurement/cache tests use serialized ArrayStore.

## 7. Creation, updates, and deletion

### Creating the first order for a user

~~~php
// This may cache an empty collection.
$empty = $orders->cache->ordersForUser(1, 42);

$order = $orders->create([
    'tenant_id' => 1,
    'user_id' => 42,
    'total_cents' => 500,
    'is_public' => false,
]);

// Recomputes and includes the newly created order.
$updated = $orders->cache->ordersForUser(1, 42);
~~~

After a successful save, base `create()` resolves the saved model's base/additional scopes and schedules invalidation. Here that includes the user's scope and the new record's scope. Other users' lists remain cached.

The empty collection is handled correctly because `ordersForUser()` declares its owner scope before the query. Inferring dependencies only from returned models would miss this case.

### Updating data or changing ownership

~~~php
$orders->cache->ordersForUser(1, 42);
$orders->cache->ordersForUser(1, 43);

$orders->update($order, ['user_id' => 43]);
~~~

Base `update()` resolves scopes from the supplied model's original attributes, the current persisted row when available, and the successfully saved model. Both previous and new owner lists become stale. The additional record scope also invalidates cached `orderById()` results.

Updates read the persisted row to handle an already-dirty or stale caller-supplied instance. This adds a read when caching is enabled. It is not a row lock; competing writes can still race with the snapshot.

### Public/shared views

~~~php
$feed = $orders->cache->publicFeed(1);
$orders->update($order, ['is_public' => true]);
$updatedFeed = $orders->cache->publicFeed(1);
~~~

The example's additional scopes include the public feed whenever the model is public. Since update considers previous and resulting state, moving into or out of that feed invalidates it. Private order writes do not invalidate the public feed merely because a user list changed.

If changing one table affects every cached view, declare a shared scope in every affected read and invalidate that scope from the write policy.

### Deleting records

~~~php
$orders->deleteBy($order);
$orders->deleteBy([$firstOrderId, $secondOrderId]);
$orders->deleteBy($order, 'id');
~~~

`deleteBy()` collects affected model scopes through a cursor before executing the query delete. It invalidates them when the deletion count is positive. If no scopes were captured but records were deleted, it falls back to a repository-wide flush. A zero-match deletion does not invalidate.

Bulk query deletes do not supply individual model events, so the repository performs that collection itself. Large deletes incur the extra read, and the pre-read is not automatically locked against concurrent changes.

### Write behavior reference

| Operation | Automatic invalidation |
| --- | --- |
| Successful `create()` | Saved model's base and additional scopes |
| Successful `update()` | Original, current persisted, and saved model scopes |
| `deleteBy()` with deleted rows | Captured scopes, or a full repository flush when unavailable |
| Non-empty successful `upsert()` | Full concrete-repository flush |
| Empty `upsert([])` | None |
| `push()` | Full concrete-repository flush in `finally`, including failures after partial saves |
| Save returning false in `create()` / `update()` | No successful-write invalidation |
| Builder/model writes bypassing these methods | No automatic repository invalidation |

Quiet base writes still invalidate. The implementation calls invalidation explicitly rather than relying on Eloquent model events.

## 8. Transactions and custom mutations

Base writes use the written model's database connection for commit callbacks. Without an active transaction, invalidation runs immediately after the write. Within a transaction, Laravel runs the callback after the outermost commit and discards it on rollback.

~~~php
use App\Models\Order;

$connection = (new Order)->getConnection();

$connection->transaction(function () use ($orders, $order) {
    $orders->update($order, ['total_cents' => 900]);

    // Runs the actual read and does not update shared cached results.
    $insideTransaction = $orders->cache->ordersForUser(
        $order->tenant_id,
        $order->user_id,
    );
});
~~~

The read proxy bypasses cache when its repository model connection has an active transaction. It does not inspect unrelated connections or track additional connections queried inside your custom method.

Manual `cache->invalidate()` and `cache->forget()` execute immediately. They do not schedule themselves after commit. Base write methods schedule them for you; custom mutation methods can call the protected `invalidateCacheAfterCommit()` helper, as `markUserOrdersPublic()` does in the complete repository example.

~~~php
// This method uses a builder update and explicitly schedules the required scopes.
$orders->markUserOrdersPublic(1, 42);
~~~

Do not put that mutation behind `->cache`. Query writes inside arbitrary custom methods, direct `Model::save()`, and relationship writes are not automatically intercepted. Make their scope policy explicit.

`push()` is special: it can persist a root model before a related save returns false or throws. Its full flush is therefore scheduled in `finally`. A surrounding rollback discards that callback; without a transaction, partial persisted changes require invalidation.

Database commits and cache writes are not one atomic operation. A cache failure in an after-commit callback can surface after the database has committed. Handle recovery/retries at the application level; the cache layer cannot roll that database commit back.

## 9. Upsert, imports, and cross-repository views

`upsert()` returns an affected-row count, not saved Models or their previous owners. Input can omit scope fields or change ownership. The base implementation does not infer targeted dependencies from that count; it flushes all result entries tagged with its concrete repository class.

~~~php
$orders->upsert([
    [
        'id' => $order->getKey(),
        'tenant_id' => 1,
        'user_id' => 43,
        'total_cents' => 1200,
        'is_public' => true,
    ],
], ['id'], ['tenant_id', 'user_id', 'total_cents', 'is_public']);
~~~

To keep base invalidation in the write path, add a custom import method that calls `upsert()`, then schedules shared-scope invalidation. The example's `importOrders()` does exactly this:

~~~php
$orders->importOrders([
    [
        'id' => $order->getKey(),
        'tenant_id' => 1,
        'user_id' => 43,
        'total_cents' => 1500,
        'is_public' => true,
    ],
]);
~~~

Another repository can declare the same logical dependencies before querying orders:

~~~php
// Inside an enabled reporting repository with its own model scope policy.
public function orderSummary(int $tenantId, int $userId): array
{
    $this->cache->dependsOn(
        'orders:bulk',
        \App\Repositories\OrderRepository::userScope($tenantId, $userId),
    );

    $query = \App\Models\Order::query()
        ->where('tenant_id', $tenantId)
        ->where('user_id', $userId);

    return [
        'count' => (clone $query)->count(),
        'total_cents' => $query->sum('total_cents'),
    ];
}
~~~

Both repositories must use the same underlying cache store and namespace. Scoped base writes change shared owner tokens, so reporting results depending on those tokens become stale too.

A repository-wide `forget()` changes only that repository's automatic dependency and tagged entries. It does not invalidate every custom logical scope registered by other repositories. This also applies to the base `upsert()` and `push()` fallbacks. Use an explicit shared scope, such as `orders:bulk`, for dependent views across classes.

## 10. Null results, empty lists, and multiple scopes

For a lookup that can return null, register the record scope before querying:

~~~php
$missing = $orders->cache->orderById(1, 999);
~~~

If order 999 is later created through the repository, the additional record scope makes that cached null stale. Simply caching inherited `findById()` cannot infer a missing record's dependency from a null result. Wrap it in a declared method with an explicit scope when creation must invalidate that miss.

Model scopes are collected automatically only for Models matching the repository's configured model type, including matching Models nested in arrays/Collections. This supplements explicit scopes. It does not discover arbitrary relationships, other model types, query filters, missing rows, or aggregate dependencies.

A result can declare several dependencies:

~~~php
$this->cache->dependsOn(
    'orders:bulk',
    OrderRepository::userScope($tenantId, $userId),
    'settings:tenant:' . $tenantId,
);
~~~

Changing any recorded token invalidates the result. Scope registration is idempotent within one frame: the first recorded version is kept. If the version changes during execution, keeping the first version prevents the result from being treated as fresh.

## 11. Nested calls and frames

A frame is an in-memory map from dependency keys to their versions for one executing cached method. It is not a cache entry and has no TTL.

In the example:

~~~php
$dashboard = $orders->cache->ttl(120)->dashboard(1, 42);
~~~

The outer dashboard starts a frame. Its cached `ordersForUser()` and `orderCount()` calls add dependencies to their own frames and all active parent frames. A nested cache hit contributes the dependencies stored with that hit, even though the inner method does not execute.

Each frame is removed in `finally`, including when the method or store operation throws. Sequential singleton reuse does not accumulate frames; the maximum number follows active nesting depth.

A direct inner call also contributes when it executes `dependsOn()` while an outer frame is active:

~~~php
// Inside OrderRepository.
public function directDashboard(int $tenantId, int $userId): array
{
    return ['orders' => $this->ordersForUser($tenantId, $userId)];
}
~~~

Here the orders list is not separately cached, but its declared dependencies still belong to a cached `directDashboard()`. Without any active cached call, `dependsOn()` is a no-op.

Frames belong to a proxy's shared stack. They are not global across different repository instances. A cached call on another repository does not automatically transfer its metadata into the caller; the caller must declare shared scopes explicitly.

## 12. Cloning, singletons, and concurrency

TTL clones intentionally share their repository's frame stack, so an outer `ttl()` call still sees `$this->cache->dependsOn()` calls in the real repository.

Repository clones are different:

~~~php
$firstRepository = clone $orders;
$secondRepository = clone $orders;

$first = $firstRepository->cache->ordersForUser(1, 42);
$second = $secondRepository->cache->ordersForUser(1, 43);
~~~

`Repository::__clone()` constructs a fresh proxy bound to the cloned repository, with empty frames. The injected cache backend and proxy TTL are preserved. Clones of the same concrete class still share cache keys/tags and can reuse valid results.

Use a separate repository clone per overlapping execution context when sharing a singleton in one process. Plainly sharing the original repository across overlapping Fibers can mix frames. Cloning only `$repository->cache` does not isolate them; `ttl()` deliberately shares them.

This is isolation of the cache dependency stack, not a deep clone of every repository dependency. Other object-valued properties, connections, Eloquent event dispatchers, and optimistic-lock static state are not made task-local. Subclasses overriding `__clone()` must call `parent::__clone()`.

For Laravel's default process-based Concurrency driver, resolve the repository inside each child rather than serializing a live repository/cache connection:

~~~php
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\Concurrency;

$results = Concurrency::run([
    static fn () => app(OrderRepository::class)->cache->ordersForUser(1, 42),
    static fn () => app(OrderRepository::class)->cache->ordersForUser(1, 43),
]);
~~~

The process driver runs tasks in separate PHP processes. The fork driver has separate memory copied from its parent; a fork made during an active cached call can inherit a frame snapshot. The sync driver runs tasks sequentially.

Frames and dependency metadata do not automatically travel back with a child process's return value. An outer cached method launching concurrent tasks must declare the combined scopes itself, or the application must explicitly transport/merge dependency metadata. That transport is not part of this proxy.

## 13. Manual invalidation and diagnostics

~~~php
// Invalidate all views that declared this particular logical scope.
$orders->cache->invalidate(OrderRepository::userScope(1, 42));

// Invalidate every result belonging to this concrete repository class.
$orders->cache->forget();

// Inspect the tagged store and a normalized method key.
$store = $orders->cache->getStore();
$key = $orders->cache->generateKey('ordersForUser', [1, 42]);
$entry = $store->get($key);
~~~

Use proxy `forget()` for repository invalidation rather than calling `getStore()->flush()` directly. The proxy also changes the automatic `repository:<class>` dependency token, which invalidates results computed by already-running reads.

Dependency versions are checked before a hit and again before storage. They are not locks and do not prevent duplicate computation on simultaneous misses. There is no stampede lock, stale-while-revalidate behavior, or distributed transaction between cache and database.

The proxy does not provide `$orders->cache->quietly->...`, `$orders->quietly->cache->...`, or other combinations of cache properties with the write-modifier proxy. Cache reads explicitly; use the write proxy separately. Ordinary `quietly`, `transaction`, and `unlocked` modifiers remain composable with each other as described in the [README](../README.md#quiet-operations-and-transactions).

## 14. PhpStorm and validation

The complete repository example supplies concrete `@property-read` proxy types. These help PhpStorm retain custom methods such as `ordersForUser()` through `$orders->cache->ttl(...)` when inherited `static` inference is insufficient.

Add concrete annotations on subclasses that introduce additional custom methods. The annotation describes completion; it does not create a method or bypass the runtime method/argument rules.

Run the regression and measurement suites separately:

~~~bash
vendor/bin/pest tests/Cache
vendor/bin/pest tests/Measurements
~~~

Regression coverage includes expiry, missing dependency tokens, nested calls, exceptions, ownership changes, partial relationship writes, rollback/commit handling, and independent frames in interleaved repository clones. Measurements use serialized ArrayStore and SQLite and report microseconds per call. They check results and query counts without hardware-dependent speed assertions; test numbers are not production cache benchmarks.

[Return to the README](../README.md).
