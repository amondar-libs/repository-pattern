# Higher-order modifiers and locking

[Skill entrypoint](../SKILL.md)

Source: `PACKAGE_ROOT/src/Proxies/HighOrderRepositoryProxy.php`, `Enums/Lock.php`, `Concerns/HasOptimisticLock.php`, and `Contracts/Lockable.php`.

## Composable write modifiers

~~~php
$users->quietly->transaction->update($user, ['is_active' => false]);
$posts->unlocked->transaction->quietly->update($post, ['title' => 'Override']);

$result = $users->transaction->run(
    fn ($repository) => $repository->update($user, ['name' => 'Jane']),
    attempts: 3,
);
~~~

The immutable proxy collects options through `makeWithOptions()`. Modifier order preserves the selected flags; repeated modifiers do not create additional wrappers by themselves.

- `quietly` suppresses model events for the operation through `withoutEvents()`.
- `transaction` makes ordinary method calls and `run()` use `DB::transaction()`, including a nested transaction when one is active.
- `unlocked` and alias `unlock` require a model implementing `Lockable`. They disable optimistic version checking, not database row locks.
- `run()` receives the real repository, preserving typed callback parameters and the wrappers around direct writes inside that callback.

An explicit proxy `run()` without the transaction flag does not itself add a transaction. These helpers use the default DB connection. For models on another connection, wrap work using that model's connection explicitly.

A chain such as `$users->quietly->query()->get()` wraps only `query()`; `get()` runs after the wrapper ends. Do not suggest the later builder execution is quiet or transactional.

## Pessimistic row locks

For an existing user and a repository bound to its model:

~~~php
$user = $users->transaction->forUpdate(
    $user->getKey(),
    function ($locked, $repository) {
        return $repository->update($locked, ['name' => 'Locked edit']);
    },
);
~~~

`forUpdate()` checks the default connection's current transaction depth:

- Equal to the configured level, default `0`: start a transaction for lock acquisition and callback.
- Any other depth: acquire the lock and run the callback in the current context, without starting one.

`onLevel(1)` changes this depth condition. It does not select a SQL isolation level. If it does not match and there is no transaction, the proxy does not create one as a fallback.

Lock options:

~~~php
$result = $users->transaction
    ->withTrashed
    ->asShared()
    ->withQuery(fn ($query) => $query->where('is_admin', false))
    ->forUpdate($user->getKey(), fn ($locked, $repository) => $locked);
~~~

- `withTrashed` is for the lock-query path and only includes trashed rows when the builder supports it. The model still needs SoftDeletes configuration.
- `withTrashed` on ordinary proxied repository methods throws.
- `asShared()` uses a shared lock rather than the default lock-for-update.
- `withQuery()` must return the builder.
- `getLock($key)` fetches a locked row but does not open a surrounding transaction on its own.
- `forUpdate()` receives the locked model and real repository. Quiet/unlocked scopes apply around the lock callback.

For single repository lookups, `Lock::forUpdate` and `Lock::shared` can be passed to `findById()` / `findBy()`. Supply the surrounding transaction. Lock-taking reads should not be result-cached.

## Optimistic locking

The model needs all three pieces: numeric version column, trait, and Lockable contract. Choose a matching column name.

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

In a migration after the package provider is registered:

~~~php
$table->versionable('version');
~~~

The macro creates an unsigned big integer column. Creation normally initializes it to `1`; `$defaultLockVersion` customizes that starting value. A dirty save/update adds the previous version predicate and increments the version. A stale/deleted row can produce `OptimisticLockException`.

~~~php
$first = $post->fresh();
$stale = $post->fresh();

$first->title = 'First edit';
$first->save();

// This stale instance should now fail with OptimisticLockException.
$stale->title = 'Second edit';
$stale->save();
~~~

Do not silently bypass that exception. Use `saveUnlocked()`, `Post::unlocked($callback)`, or a repository unlocked modifier only when the requested behavior requires bypassing the check.

Unlocked saves still derive and write the next version from the supplied instance. They do not guarantee advancing from the database's newest version. Bulk builder operations do not execute this per-model protection.

Quiet creation suppresses the trait's creating listener. Required version initialization then needs an explicit compatible filling policy or database default.

## State boundaries

Model event dispatchers and optimistic-lock flags are shared static state. Repository clone isolation only protects cache frames; it does not make simultaneous quiet/unlocked operations in one process independently scoped. See [concurrency and types](concurrency-and-types.md).

The service transaction helper has its own depth-sensitive behavior. Do not assume it always starts the same nested transaction as a repository's ordinary `transaction` method call; see [services](services-and-commands.md).
