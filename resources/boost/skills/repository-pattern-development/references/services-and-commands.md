# Services and command traits

[Skill entrypoint](../SKILL.md)

Source: `PACKAGE_ROOT/src/Service.php`, `Concerns/HasCreateCommand.php`, `Concerns/HasUpdateCommand.php`, command contracts, and `Proxies/HigherOrderServiceTransactionProxy.php`.

## Compose a service

The example assumes the application's User, UserData, and UserRepository already exist and use the fields shown in its schema.

~~~php
namespace App\Services;

use Amondar\RepositoryPattern\Concerns\HasCreateCommand;
use Amondar\RepositoryPattern\Concerns\HasUpdateCommand;
use Amondar\RepositoryPattern\Contracts\CreationCommandContract;
use Amondar\RepositoryPattern\Contracts\UpdateCommandContract;
use Amondar\RepositoryPattern\Service;
use App\Data\UserData;
use App\Models\User;
use App\Repositories\UserRepository;

/**
 * @extends Service<User, UserData, UserRepository>
 * @implements CreationCommandContract<User, UserData>
 * @implements UpdateCommandContract<User, UserData>
 */
class UserService extends Service implements CreationCommandContract, UpdateCommandContract
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

Service is not readonly, so its subclass cannot be declared readonly. A readonly constructor dependency remains valid.

The command contracts are optional when interface-based consumers need them. Their generic `@implements` annotations and the traits' `@use` annotations retain model/DTO types.

For standalone command classes, the traits require compatible `repository()`, `storeModelRelations()`, and `shouldLoadRelationsAfterChangesApplied()` implementations. A Service subclass inherits default relation behavior. Do not narrow an inherited untyped model parameter incompatibly when overriding its hook.

## Preserve hook ordering

Create command flow:

1. `creatingHook(&$data)`.
2. `repository()->create($data)`.
3. If the model exists, `storeModelRelations($model, &$data)`.
4. `createdHook($model, $data)`.
5. Refresh configured relations and return the model with `wasRecentlyCreated` set.

A failed create whose model does not exist raises `ModelNotSaved::notCreated()`.

Update command flow:

1. `updatingHook($model, &$data)`.
2. `repository()->update($model, $data)`.
3. `storeModelRelations($model, &$data)`.
4. `updatedHook($model, $data)`.
5. Load configured relations and return the model.

The update trait does not add a blanket save-failure exception or automatically remove null fields. Apply the requested application policy in its hooks.

## Transactions and lock-and-update

~~~php
$service = app(\App\Services\UserService::class);
$user = $service->transaction->create($data);
$user = $service->lockAndUpdate($user->getKey(), $updates);
~~~

The service transaction proxy opens a transaction only when the current default-connection depth equals its configured level, default zero. Otherwise it executes in the current context. `onLevel(1)` requests a new transaction when the depth is 1.

`HasUpdateCommand::lockAndUpdate()` explicitly wraps its lock lookup and update in `DB::transaction()`; its protected `getLock()` calls the repository lock-query helper. Both use the default connection. Use a matching explicit connection boundary when the model lives elsewhere.

Ordinary custom events are not automatically deferred. Events implementing Laravel's `ShouldDispatchAfterCommit` use Laravel's commit/rollback handling. Event requirements belong to the domain, not to an invented package-wide rule.

## Related writes and cache invalidation

Repository create/update invalidation covers the model scopes resolved by those base operations. It cannot infer every relationship, custom builder write, event listener side effect, or cross-repository view touched later by service hooks.

For a cached domain:

- Resolve the required relation/aggregate scopes explicitly.
- Keep the full write sequence inside the intended transaction.
- Schedule additional invalidation on the connection that performed the writes. A public custom repository method can expose an operation using its protected `invalidateCacheAfterCommit()` helper.
- Do not cache a service or repository mutation just because it returns a Model.

See [cache invalidation](cache-invalidation.md) for examples. Avoid adding transaction/event behavior to unrelated methods during a narrow refactor.
