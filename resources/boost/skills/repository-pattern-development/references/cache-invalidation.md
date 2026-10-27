# Write invalidation and transaction boundaries

[Skill entrypoint](../SKILL.md) · [Cached reads](cache-reads.md)

Source: `PACKAGE_ROOT/src/Repository.php` and the `invalidate()` / `forget()` methods in `HighOrderRepositoryCacheProxy.php`.

## Map writes to the scopes declared by reads

Before modifying a cached domain, identify:

1. Which record/list/aggregate results can change.
2. Which persisted fields define those scopes before and after the write.
3. Whether the write uses a base repository operation or bypasses it.
4. Which database connection commits the data.
5. Whether other repository classes depend on that data.

Do not invalidate every user merely because the repository has one class-wide tag. Use the model's base/additional logical scopes for targeted changes.

| Write | Current base behavior when caching is enabled |
| --- | --- |
| Successful `create()` | Resolve saved model scopes |
| Successful `update()` | Resolve supplied original, current persisted, and saved scopes |
| Positive `deleteBy()` | Pre-read affected scopes via cursor; full repository fallback if unavailable |
| Zero-match delete | No invalidation |
| Non-empty successful `upsert()` | Full concrete-repository flush |
| Empty `upsert([])` | No invalidation |
| `push()` | Full concrete-repository flush in finally, including partial-save failures |
| Save returning false in create/update | No successful-write invalidation |
| Arbitrary builder/model/relationship writes | No automatic repository interception |

A cached update performs an additional persisted-row read to account for stale ownership. Delete scope capture is also an extra read. These are not automatic locks against concurrent changes.

## Creation and ownership changes

Using the owner scope from [the read example](cache-reads.md):

~~~php
$orders->cache->ordersForUser(1, 42); // May cache an empty list.
$orders->cache->ordersForUser(1, 43);

$order = $orders->create([
    'tenant_id' => 1,
    'user_id' => 42,
    'total_cents' => 500,
    'is_public' => false,
]);

$orders->update($order, ['user_id' => 43]);
~~~

The model must define/fill those application fields. Creation invalidates user 42's scope. The ownership update invalidates both old and new scopes. New list membership cannot be inferred from cached result IDs alone.

## Additional scopes for record and public views

Inside the same OrderRepository, a matching example is:

~~~php
/** @param Order $model */
protected function getModelAdditionalScopes(Model $model): array
{
    $scopes = ["orders:tenant:{$model->tenant_id}:id:{$model->getKey()}"];

    if ($model->is_public) {
        $scopes[] = "orders:tenant:{$model->tenant_id}:public";
    }

    return $scopes;
}
~~~

A nullable `orderById($tenantId, $orderId)` read must declare the same record string before its query. A public feed must declare the public string before querying. Base updates inspect both states, so entering or leaving the public feed invalidates it.

For a model scoped by ID rather than owner, make its base scope the record scope and add owner/list scopes as additional scopes. Composite tenant/category/account identities are equally valid if reads and writes use the exact same strings.

## Commit timing

Base writes pass the written model's connection to protected `invalidateCacheAfterCommit()`:

- No transaction on that connection: invalidate immediately after the operation.
- Active transaction: register Laravel's after-commit callback.
- Inner commit: wait for the outermost commit.
- Rollback of that transaction: discard its invalidation callback.

Shared cached reads are bypassed during an active repository model-connection transaction. The proxy does not detect unrelated connections queried by a custom method.

Manual `cache->invalidate()` and `cache->forget()` execute immediately. They do not defer themselves. Use the protected helper from a custom repository mutation instead of requiring every caller to remember commit timing.

## Custom builder writes

Inside a repository whose model has the shown owner/public fields:

~~~php
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
                "orders:tenant:{$tenantId}:public",
            ],
        );
    }

    return $affected;
}
~~~

Call it normally, never through `->cache`. If a custom write changes ownership, capture previous scopes before mutation and include resulting scopes. Relationship writes from service hooks need their own policy.

Quiet base writes still invalidate because repository maintenance is explicit, not observer-based. Bulk writes cannot rely on individual model events either.

## Upsert and dependent repositories

An affected-row count is insufficient to identify old owners. Input may omit ownership fields or update existing records under unique indexes. Preserve the conservative full-repository fallback unless a deliberately designed custom operation supplies complete scopes.

A full flush changes the concrete repository's automatic token and its tagged results. It does not change every logical user scope used by other repositories.

For cross-repository aggregates, have affected reads declare a shared scope such as `orders:bulk`, then add an import method:

~~~php
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
~~~

This example assumes the order table's primary ID and those columns. The read reference already includes `orders:bulk`; a reporting repository must explicitly declare it too and use the same cache backend/namespace.

The same principle applies to relationship pushes and other broad fallbacks. Cross-instance frame inheritance is not a substitute for shared scopes.

## Invalidation API and failure boundaries

- `invalidate($scope, ...)` changes version tokens with finite TTLs; dependent entries fail their next validation.
- `forget()` changes the repository-wide token and flushes the repository's tagged entries.
- Calling `getStore()->flush()` directly omits the extra token used to invalidate in-flight reads.
- Neither API is a distributed lock or cache-stampede mechanism.

`push()` can save the root before a related save fails; finally-based invalidation covers persisted partial changes. A surrounding rollback discards that callback.

Database and cache writes are not atomic together. A cache exception after commit cannot undo the database write. Preserve the application's recovery policy rather than silently treating a committed operation as rolled back.
