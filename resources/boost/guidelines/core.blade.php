## Repository Pattern

`amondar-libs/repository-pattern` provides Eloquent repositories, Spatie Data input support, service command hooks, composable write modifiers, locking, and dependency-scoped result caching under `Amondar\RepositoryPattern`.

### Core conventions

- Bind an existing Eloquent model with `#[UseModel(Model::class)]`. Repository subclasses must be `readonly`; service subclasses must not be readonly. Follow the application's existing directory and namespace conventions.
- Add `@@extends Repository<YourModel, YourData>` for DTO-specific types, or `@@extends Repository<YourModel>` when using arrays. Preserve parent construction when adding constructor dependencies.
- `create()` and `update()` accept arrays or Spatie Data. Explicit null values are retained; omitted fields are different. Model fillable rules, casts, and events still apply.
- Use `quietly`, `transaction`, and `unlocked`/`unlock` for composable repository calls. Quiet calls suppress model events; unlocked calls bypass this package's optimistic-lock check.

This example assumes an existing `App\Models\User` with a fillable `name` attribute:

@verbatim
<code-snippet name="Define and use a repository" lang="php">
namespace App\Repositories;

use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Repository;
use App\Models\User;

/** @extends Repository<User> */
#[UseModel(User::class)]
readonly class UserRepository extends Repository {}

$users = app(UserRepository::class);
$user = $users->findById('42');

if ($user !== null) {
    $users->quietly->transaction->update($user, ['name' => 'Jane']);
}
</code-snippet>
@endverbatim

### Cache conventions

- Caching is disabled by default. Override `usesCache(): bool`, implement `getModelBaseScope(Model $model): string`, and configure a Laravel store supporting tags before enabling it.
- Explicit `->cache->ttl(100)->customRead($id)` caches a declared public instance method. The first declared argument must be an integer, string, or persisted unchanged model; include every result-changing tenant/user context in arguments. Hits skip the method body: never cache required writes or side effects.
- Declare list/owner dependencies with `$this->cache->dependsOn($scope)` before querying. Returned models alone cannot cover empty lists or new matching records.
- Base repository writes invalidate after the model connection's outermost commit, or immediately without a transaction. Custom writes and direct builder writes need explicit invalidation; shared cache reads are bypassed inside transactions.

Activate the `repository-pattern-development` skill for implementation examples, cache scope design and invalidation, service hooks, locking, concurrency isolation, proxy IDE types, or package tests.
