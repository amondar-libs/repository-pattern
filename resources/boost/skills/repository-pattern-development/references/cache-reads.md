# Cached reads, keys, and dependency scopes

[Skill entrypoint](../SKILL.md) · [Write invalidation](cache-invalidation.md)

Source: `PACKAGE_ROOT/src/Proxies/HighOrderRepositoryCacheProxy.php` and the scope/configuration methods in `Repository.php`. The longer human guide is `PACKAGE_ROOT/docs/cache.md`.

## Configure a scoped repository

This example assumes an existing `App\Models\Order` with fillable/casted integer `tenant_id` and `user_id` columns. Use the application's real IDs and fields.

~~~php
namespace App\Repositories;

use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Proxies\HighOrderRepositoryCacheProxy;
use Amondar\RepositoryPattern\Repository;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * @extends Repository<Order>
 * @property-read HighOrderRepositoryCacheProxy<Order, Data, OrderRepository> $cache
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

    /** @param Order $model */
    protected function getModelBaseScope(Model $model): string
    {
        return self::userScope($model->tenant_id, $model->user_id);
    }

    public function ordersForUser(int $tenantId, int $userId): Collection
    {
        $this->cache->dependsOn('orders:bulk', self::userScope($tenantId, $userId));

        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get();
    }

    public function orderCount(int $tenantId, int $userId): int
    {
        $this->cache->dependsOn('orders:bulk', self::userScope($tenantId, $userId));

        return $this->query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->count();
    }
}
~~~

~~~php
$orders = new \App\Repositories\OrderRepository(cache()->store('redis'));
$list = $orders->cache->ttl(100)->ordersForUser(1, 42);
$count = $orders->cache->orderCount(1, 42);
$uncached = $orders->ordersForUser(1, 42);
~~~

`usesCache()` defaults to false. There is no current cache-enabling property, constant, or method allowlist. The constructor checks that an enabled repository overrides `getModelBaseScope()` and uses a tagged store. Scope hooks must return non-empty strings; additional scopes are validated and deduplicated.

The dependency injection contract is Laravel's cache Repository, but the proxy currently checks for an actual `Illuminate\Cache\Repository` with `supportsTags()`. Redis/Memcached are shared options; serialized ArrayStore is suitable for tests. An untaggable store fails configuration, not silently falls back to a full application flush.

## Separate result identity from dependencies

Result keys hash concrete repository class, method name, and all normalized arguments. TTL is not part of that key.

A dependency scope is a logical string shared by any methods whose results depend on the same data. Here both the list and count depend on `orders:tenant:1:user:42`. Creating a new order for that owner invalidates both. User 43's entries remain valid.

The `orders:bulk` scope is an application convention for imports across repository classes. It does not change automatically on every write; [the invalidation reference](cache-invalidation.md) shows when to change it.

Declare list/aggregate/record scopes before querying. A cached empty list or count has no returned model from which to infer ownership. Similarly, a cached null ID lookup needs an explicit record scope so later creation invalidates it.

Automatically collected Model arguments/results supplement declared scopes. Collection/array traversal only extracts matching repository model types. It does not infer all related model types, missing records, filters, or authorization rules.

## Method and key requirements

When shared caching is active:

- Use a declared public instance method. Builder passthrough methods do not satisfy the reflection check.
- Its declared first argument must resolve to int, string, or a persisted unchanged Model. A default first parameter is allowed; no-parameter methods are not.
- Other arguments can contain primitives, null, supported arrays, enums, and persisted unchanged Models. Reference and variadic parameters are rejected.
- Model normalization uses class, connection name, and primary key. Unsaved/dirty models are rejected; loaded relations do not enter the key.
- Named/positional arguments and omitted defaults normalize to parameter order. Array contents preserve order.
- A top-level builder, generator, or non-Collection traversable is not a cacheable result. Return a materialized result and ensure nested values suit the driver's serialization.
- False, zero, null, and empty results are cached in an envelope without being mistaken for a missing entry.

Put tenant, user authorization, locale, filters, pagination, and other result-changing context into arguments as appropriate. Implicit globals are not automatically keyed. Declaring a tenant scope inside the method cannot repair a key that already returned another tenant's cached result.

Cache reads, not mutations or required side effects. Hits do not execute the body. Do not cache locking reads or suggest `cache->where(...)->get()`.

## TTL and stored entries

The result envelope contains `value` plus dependency-key/version pairs. Each token is stored separately under a hashed `repository-dependency:` key in the underlying cache repository.

- Result TTL: calling proxy's `ttl($seconds)`, default 300; positive seconds only.
- Initialized dependency token TTL: `max(proxy seconds, 300)`.
- Invalidated dependency token TTL: `max(proxy seconds, 300)` from replacement.
- `dependsOn()` adds missing tokens; it does not extend existing token TTLs.
- Each frame also records an automatic `repository:<concrete class>` dependency for full flushes.

A long outer TTL does not guarantee every dependency lasts equally long. A method's `$this->cache->dependsOn()` uses the base proxy, normally 300 seconds. Earlier token expiry safely causes extra misses.

Expired/evicted tokens are invalid, and recreated tokens are random rather than reused. Existing results may live until their own TTL, but cannot pass validation with changed/missing tokens. Laravel's separate tag metadata has driver-managed lifetimes.

`ttl()` clones the proxy while sharing its active dependency stack. Asking for a different TTL on a hit does not extend or replace the existing entry. The proxy does not deep-clone result objects; ArrayStore should serialize values in snapshot-sensitive tests.

## Frames and nested reads

A frame is temporary in-memory dependency collection for one running cache miss. It is removed in `finally`. No frame is stored in the cache.

~~~php
// Inside the same repository.
public function dashboard(int $tenantId, int $userId): array
{
    return [
        'orders' => $this->cache->ttl(30)->ordersForUser($tenantId, $userId),
        'count' => $this->cache->orderCount($tenantId, $userId),
    ];
}
~~~

Inner dependencies are copied to all active parent frames, including when the inner call is a hit. The first observed version is kept, so a mid-call change prevents publishing a supposedly fresh result.

A direct inner call executing `dependsOn()` also contributes to an active outer frame without becoming separately cached. With no active frame, `dependsOn()` is a no-op.

Different repository instances do not automatically share frames or inherit each other's metadata. See [concurrency and types](concurrency-and-types.md) before using a singleton, clone, or parallel task.
