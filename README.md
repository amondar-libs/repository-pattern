# amondar-libs/repository-pattern

Laravel package to implement a clean Repository + Service pattern with a tiny, expressive API.

- Model-first repository with a single source of truth declared via attribute
- Optional data normalization via spatie/laravel-data
- Higher-order helpers for running methods quietly (without model events)
- Higher-order helpers for running methods inside a database transaction


## Requirements
- PHP ^8.3
- Laravel 10/11/12 (uses Eloquent, DB facade)
- spatie/laravel-data (optional, for typed DTOs)


## Installation
```
composer require amondar-libs/repository-pattern
```

No service provider registration is required.

## Simple usage
This section shows the typical way to use the package: define a Model, optionally a Data object, and a Repository bound to that Model via an attribute.

### 1) Your Eloquent model
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Model
{
    use HasUuids;

    // fillable or guarded...

    protected function casts(): array
    {
        return [
            'password'  => 'hashed',
            'is_active' => 'boolean',
            'is_admin'  => 'boolean',
        ];
    }
}
```

### 2) (Optional) A Data object
If you prefer typed DTOs, use spatie/laravel-data. The repository accepts arrays or Data instances and normalizes them automatically.

```php
use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password,
        public bool $is_active,
        public bool $is_admin,
    ) {}
}
```

### 3) Your Repository bound to the model via attribute
```php
use Amondar\RepositoryPattern\Attributes\UseModel;
use Amondar\RepositoryPattern\Repository;

/**
 * @extends Repository<User, UserData>
 */
#[UseModel(User::class)]
readonly class UserRepository extends Repository {}
```

### 4) Use the repository
```php
$repo = new UserRepository();

// Create (array input)
$model = $repo->create([
    'name'      => 'Jane',
    'email'     => 'jane@example.com',
    'password'  => 'secret',
    'is_active' => true,
    'is_admin'  => false,
]);

// Update (spatie Data input)
$model = $repo->update($model, UserData::from([
    'name'      => 'Jane Roe',
    'email'     => 'jane+1@example.com',
    'password'  => null, // your model/service can decide how to treat nulls
    'is_active' => false,
    'is_admin'  => true,
]));

// Query builder passthrough
$activeAdmins = $repo->where('is_active', true)
                    ->where('is_admin', true)
                    ->orderBy('name')
                    ->get();
```

Notes:
- The repository exposes your model’s query builder via magic __call, so you can chain any Builder methods directly on the repository.
- If you forget to bind a model via #[UseModel], a RepositoryModelNotFound exception will be thrown on repository construction.


## Dive deep: quietly and transaction
Two higher‑order helpers are available to control side effects.

- quietly: disables Eloquent model events for the wrapped call
- transaction: runs the wrapped call inside a database transaction

You can use them independently or together.

### Run quietly (suppress Eloquent events)
```php
$repo = new UserRepository();

// Will NOT fire creating/created/updating/updated/etc. model events
$user = $repo->quietly->create([
    'name'      => 'Silent User',
    'email'     => 'silent@example.com',
    'password'  => '123456',
    'is_active' => true,
    'is_admin'  => false,
]);
```
Under the hood, quietly uses Model::withoutEvents(...) around your repository call.

### Run in a database transaction
```php
$repo = new UserRepository();

// Wrap a single method call inside DB::transaction(...)
$user = $repo->transaction->create([
    'name'      => 'Tx User',
    'email'     => 'tx@example.com',
    'password'  => '123456',
    'is_active' => true,
    'is_admin'  => false,
]);
```

### Combine: transaction + quietly
Sometimes you want both: atomic writes and no model events.

```php
$repo = new UserRepository();

$user = $repo->transaction->quietly->create([
    'name'      => 'Stealth Tx User',
    'email'     => 'stealth@example.com',
    'password'  => '123456',
    'is_active' => true,
    'is_admin'  => false,
]);
```

Tip: Laravel’s `ShouldDispatchAfterCommit` events will be dispatched only after a successful commit when used inside a transaction. If the transaction rolls back, those events won’t be dispatched.

### Run transaction with pessimistic locking 
Sometimes you want to make an atomic update with pessimistic lock.

```php
$repo = new UserRepository();

$user = $repo->transaction->forUpdate(1, function(User $user, UserRepository $repository){
    // do Some logic under SELECT FRO UPDATE lock.
});
```

## Service layer (optional)
You can build services on top of repositories. The Service base class provides a transaction helper as well. Example with handy create/update traits.
Also, you can use traits to add target methods into commands in the CQRS pattern.

```php
use Amondar\RepositoryPattern\Service;
use Amondar\RepositoryPattern\Extensions\HasCreateCommand;
use Amondar\RepositoryPattern\Extensions\HasUpdateCommand;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Service<User, UserData, UserRepository>
 */
readonly class UserService extends Service
{
    /**
     * @use HasCreateCommand<User, UserData, UserRepository>
     * @use HasUpdateCommand<User, UserData, UserRepository>
     */
    use HasCreateCommand, HasUpdateCommand;

    public function __construct(private UserRepository $users) {}

    // Define an abstract repository for target methods (create/update).
    protected function repository(): UserRepository
    {
        return $this->users;
    }

    // Override hooks if needed
}
```

### Service transactions
The service also exposes a transaction helper:

```php
$service = app(UserService::class);

// Run a service method in a DB transaction
$user = $service->transaction->create(UserData::from([
    'name'      => 'Jane',
    'email'     => 'jane@example.com',
    'password'  => 'secret',
    'is_active' => true,
    'is_admin'  => false,
]));
```

If the method throws, the transaction is rolled back and any ShouldDispatchAfterCommit events are not dispatched.


## Data normalization
Repository::create() and Repository::update() accept either:
- plain arrays
- Spatie Data instances (Data), which will be converted to arrays via toArray()

This lets you keep controllers/services strongly typed while repositories remain simple.


## Exceptions
- RepositoryModelNotFound — thrown when a Repository has no #[UseModel(...)] attribute assigned.
- ModelNotSaved — helper exception you can use in services when save operations fail (see traits usage).


## Types and generics (PHPDoc)
The classes are annotated with generics to improve static analysis in IDEs:
- Repository<TModel, TData>
- Service<TModel, TData, TRepository>
- Proxies and traits carry through those generics as well


## License
MIT. See LICENSE.md.
