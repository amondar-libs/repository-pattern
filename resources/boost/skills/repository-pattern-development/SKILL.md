---
name: repository-pattern-development
description: Build and maintain Laravel repositories and services using amondar-libs/repository-pattern. Use for UseModel bindings, repository CRUD and DTOs, higher-order quiet/transaction/unlocked calls, optimistic or pessimistic locking, dependency-scoped result caching, clone isolation, and related Pest tests or PhpStorm types. Apply to this package and its consumers, not generic repository-pattern design without this package.
license: MIT
---

# Repository Pattern Development

Use the installed package's APIs and preserve the application's existing architecture. This skill covers `Amondar\RepositoryPattern`; it does not require converting unrelated Eloquent code to repositories.

## Establish context

Locate the package root: this checkout when its `composer.json` names `amondar-libs/repository-pattern`, otherwise its Composer installation, usually `vendor/amondar-libs/repository-pattern`. Paths prefixed with `PACKAGE_ROOT` in these references mean that location, not the copied skill directory.

Inspect the relevant installed source and application models, migrations, repositories, services, cache configuration, and tests. Installed code takes precedence over examples when versions differ. Use Boost's application/schema information and version-aware documentation search when available and relevant; local source is the fallback. Do not install Boost, change stores, or edit a consumer's vendor files just to use this skill.

## Load only the relevant references

- Model binding, arrays/DTOs, CRUD, builder passthrough, and extension points: [Repository basics](references/repository-basics.md).
- Quiet/transaction/unlocked composition, callbacks, depth rules, and both locking strategies: [Modifiers and locking](references/modifiers-and-locking.md).
- Services, command traits/contracts, hooks, and relationship writes: [Services and commands](references/services-and-commands.md).
- Cache enablement, keys, arguments, scopes, empty results, TTLs, and frames: [Cached reads](references/cache-reads.md).
- Automatic write invalidation, ownership changes, bulk operations, and custom writes: [Cache invalidation](references/cache-invalidation.md).
- Singleton/clone isolation, concurrent tasks, generic PHPDoc, and custom method completion: [Concurrency and types](references/concurrency-and-types.md).
- Package versus application tests, Pest AAA, commit callbacks, and measurements: [Testing and maintenance](references/testing-and-maintenance.md).

Read both cache references for a change that affects reads and writes. Follow their links only when the additional topic matters.

## Work through the behavior

For a small change, implement directly. For a multi-file feature or a cache/locking change, make a short task-specific plan covering the applicable steps:

1. Identify the model, repository, DTO, database connection, and required behavior.
2. Map each cached read to its scopes and every write to the old/new scopes it can affect. Decide the transaction boundary and how bulk writes refresh dependent views.
3. Implement through existing repository/service extension points. Preserve parent construction and write invalidation when extending them.
4. Verify observable behavior with the project's tests; for caching, exercise real misses/hits outside automatic test transactions and verify commit/rollback timing separately.
5. Report changed behavior, checks actually run, and remaining limits. Do not claim an IDE inspection, cache driver, or concurrency mode was verified solely because runtime tests passed.

Skip inapplicable steps. Do not expand a documentation or application task into a package rewrite.

## Package invariants

- Repository subclasses are readonly; service subclasses are not. Arrays and Spatie Data are accepted, but omitted fields and null values are not interchangeable.
- Use the current class names: `HighOrderRepositoryProxy`, `HighOrderRepositoryCacheProxy`, and `HigherOrderServiceTransactionProxy`. The old repository proxy classes were removed.
- Caching is enabled by overriding `usesCache()`, not by inventing a property or constant. An enabled repository needs an implemented model base scope and a tagged Laravel store.
- Explicit `->cache` opts a declared read method into caching. Cache hits skip its body; never route required writes or side effects through that path.
- Owner/list scopes must be declared before queries. Returned models cannot describe empty results or future matching rows.
- Shared cache reads are bypassed inside an active model-connection transaction. Base writes defer invalidation to that connection's outermost commit.
- Cache and write-modifier proxy chains are separate APIs; do not invent `->cache->quietly` or `->transaction->cache`.
- Repository-wide flushes do not automatically refresh other repositories' logical scopes. Design shared dependencies explicitly.
- Do not promise frame isolation from a shallow proxy clone or implicit cross-process inheritance.

## Installation and maintenance context

This package ships this skill at `resources/boost/skills/repository-pattern-development`. Its references are self-contained so Boost can copy the entire skill to the consumer's configured agent directory.

When maintaining the package, keep these references consistent with `PACKAGE_ROOT/src`, `PACKAGE_ROOT/README.md`, and `PACKAGE_ROOT/docs/cache.md`. In a consuming application, extend application code rather than editing the installed skill or package vendor files. Preserve the user's requested task scope and verification constraints.
