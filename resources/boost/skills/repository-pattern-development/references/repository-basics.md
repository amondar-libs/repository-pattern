# Repository bindings, DTOs, and operations

[Skill entrypoint](../SKILL.md)

Source: `PACKAGE_ROOT/src/Repository.php`, `Contracts/RepositoryContract.php`, `Attributes/UseModel.php`, and `composer.json`.

## Bind an existing model

Follow the application's namespace and ID conventions. The example assumes `App\Models\User` and an application DTO already exist.

~~~php
namespace App\Repositories;

use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Repository;
use App\Data\UserData;
use App\Models\User;

/** @extends Repository<User, UserData> */
#[UseModel(User::class)]
readonly class UserRepository extends Repository {}
~~~

An inherited `UseModel` can be resolved from a parent repository. A missing binding throws `RepositoryModelNotFound` during construction. Model fillable/guarded rules, casts, events, and database constraints still apply.

Prefer container resolution when the application supplies dependencies:

~~~php
$users = app(\App\Repositories\UserRepository::class);

$user = $users->create([
    'name' => 'Jane',
    'email' => 'jane@example.com',
    'password' => 'secret',
    'is_active' => true,
    'is_admin' => false,
]);
~~~

This payload assumes those columns are fillable and the model hashes passwords. Match the application's actual schema rather than copying these columns into an unrelated model.

The base constructor accepts an optional `Illuminate\Contracts\Cache\Repository`. A custom constructor must call `parent::__construct($cacheRepository)`. Direct `new UserRepository()` remains possible; enabled caching resolves the Laravel cache binding when none was injected.

## Arrays and Data

The dependency on `spatie/laravel-data` is required by Composer, even when the application only uses arrays. DTO usage itself is optional.

`Repository<TModel, TData>` bounds the model to Eloquent Model and the DTO to Spatie Data; `TData` defaults to `Data`, so `@extends Repository<Post>` is supported.

~~~php
$user = $users->update($user, ['name' => 'Jane Roe']);
$normalized = $users->normalizeData($dataObject);
~~~

`create()` and `update()` call `fillTheModel()`, which normalizes a Data object using `toArray()`. A null value is passed through; the repository does not automatically remove it. For PATCH behavior, omit absent fields or use the application's DTO/Optional/service policy. Do not invent blanket password-null filtering.

## Choose the operation

| API | Behavior to preserve |
| --- | --- |
| `create($data)` | Fills a new model, calls save, and returns that model |
| `update($model, $data)` | Fills and saves the supplied model, then returns it |
| `upsert($data, $uniqueBy, $update)` | Builder bulk operation returning an affected-row count |
| `push($model)` | Saves the model and loaded relationships; can partially save before failure |
| `deleteBy($value, $field = null)` | Query delete returning a count; absent field means primary key |
| `findById(string $id, $select = ['*'], ?Lock $lock = null)` | Fetches one model or null |
| `findBy($field, $value, $select = ['*'], ?Lock $lock = null)` | Fetches one model or null by a field |
| `existsBy($field, $value, $primaryKey = null)` | Boolean existence lookup, optionally constrained to a key |
| `query()`, `makeModel()`, `model()` | Builder, fresh model instance, and model class respectively |
| `normalizeData($data)` | Array unchanged, Data converted to array, null preserved |

A false return from Model save does not automatically become an exception in base create/update. Inspect the result or use the service's create-command failure handling when that distinction matters.

With `deleteBy($model, 'email')`, the email attribute supplies the value. With `deleteBy($model)`, the primary key is used. Strings, scalar keys, and primary-key arrays can be used where the builder accepts them.

## Builder passthrough and bulk behavior

~~~php
$active = $users->where('is_active', true)->orderBy('name')->get();
$deleted = $users->deleteBy('jane@example.com', 'email');
~~~

Unknown repository methods forward to a fresh Eloquent builder. A returned builder's later calls are outside any proxy wrapper that already finished.

`upsert()` does not apply ordinary per-model fill/cast/save hooks. Supply already-normalized values, such as an appropriately hashed password, and match real unique indexes and database upsert behavior. It does not provide per-model optimistic-lock checks.

Builder writes and direct model writes bypass repository cache maintenance. Read [cache invalidation](cache-invalidation.md) before changing a cached domain.

## Extension points and scope

Prefer custom repository methods and the protected `fillTheModel()`, `isDataClass()`, and cache-scope hooks. When overriding a base write, delegate to the parent or explicitly preserve its invalidation policy; confirm current signatures/finality in the installed version.

Do not introduce a repository interface, service, DTO, or migration for every change just because the package supports them. Reuse the application's established boundaries.

`WithAttributesCache` currently declares `getAttributesCache()`, but the package's attribute lookups do not wire it into the parser. Do not promise that implementing it enables metadata caching.
