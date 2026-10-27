---
name: repository-pattern-development
description: Implement and troubleshoot amondar-libs/repository-pattern repositories, services, locking, and dependency-scoped caching. Use when application or package changes involve these APIs, their tests, or proxy IDE types.
license: MIT
---

# Repository Pattern Development

## When to use this skill

Use this skill when implementing or maintaining `Amondar\RepositoryPattern` repositories and services, configuring locking or result caching, or troubleshooting their tests and proxy IDE types. Follow the application's existing namespaces, DTOs, and schema.

## Establish context

Locate the package root: this checkout when its `composer.json` names `amondar-libs/repository-pattern`, otherwise its Composer installation, usually `vendor/amondar-libs/repository-pattern`. Paths prefixed with `PACKAGE_ROOT` in these references mean that location, not the copied skill directory.

Inspect the relevant installed source and application code before applying examples; installed code takes precedence when versions differ. Use Boost's application/schema information and documentation search when useful and available. In consumers, extend application code rather than editing vendor files.

## Features

Load only the references relevant to the current task:

- Model binding, arrays/DTOs, CRUD, builder passthrough, and extension points: [Repository basics](references/repository-basics.md).
- Quiet/transaction/unlocked composition, callbacks, depth rules, and both locking strategies: [Modifiers and locking](references/modifiers-and-locking.md).
- Services, command traits/contracts, hooks, and relationship writes: [Services and commands](references/services-and-commands.md).
- Cache enablement, keys, arguments, scopes, empty results, TTLs, and frames: [Cached reads](references/cache-reads.md).
- Automatic write invalidation, ownership changes, bulk operations, and custom writes: [Cache invalidation](references/cache-invalidation.md).
- Singleton/clone isolation, concurrent tasks, generic PHPDoc, and custom method completion: [Concurrency and types](references/concurrency-and-types.md).
- Package versus application tests, Pest AAA, commit callbacks, and measurements: [Testing and maintenance](references/testing-and-maintenance.md).

Read both cache references when a change affects reads and writes. Otherwise load only the reference needed for the task.

## Implementation decisions

For changes spanning cache, writes, or locking, make a short plan around the relevant boundaries:

1. Identify the model connection, transaction owner, and repository/service extension points. Preserve parent construction and invalidation when overriding base methods.
2. Map cached reads to dependencies and writes to old/new scopes. Declare list dependencies before queries so empty results and future matching rows are covered. Include tenant or user context in method arguments when it changes results.
3. Choose explicit invalidation for custom or cross-repository writes; a repository-wide flush does not automatically refresh another repository's logical scopes.
4. Verify cache misses/hits outside wrapping test transactions, then commit/rollback timing separately. Runtime tests alone cannot verify PhpStorm completion or a different cache/concurrency driver.

Skip inapplicable steps and keep the user's requested verification scope.

## API guardrails

- Repository subclasses are readonly; services are not. Omitted fields and explicit nulls have different update semantics.
- Enable caching with `usesCache()` and implement `getModelBaseScope()` with a tagged Laravel store; no `shouldUseCache` property exists.
- `->cache` is for declared public instance read methods. Hits skip the method body, so required mutations and side effects cannot use this path.
- Cache and write-modifier chains are separate APIs. Use repository clones for independent cache frames; shallow proxy clones and process boundaries do not propagate dependency frames.

When maintaining this package, keep references aligned with `PACKAGE_ROOT/src`, `README.md`, and `docs/cache.md`. Keep links within this skill folder portable: Boost copies the folder into the consuming application's agent directory.
