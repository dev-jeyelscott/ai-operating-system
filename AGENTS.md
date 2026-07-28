# AGENTS.md

## Purpose

This file defines the mandatory operating rules for AI coding agents working in this Laravel repository.

Use the installed package versions, existing architecture, repository conventions, tests, and version-specific Laravel Boost documentation as the source of truth. Do not rely on remembered framework behavior when repository evidence or documentation is available.

## Instruction Priority

When instructions conflict, follow this order:

1. Explicit requirements for the current task.
2. This `AGENTS.md` file.
3. Existing architecture, conventions, and tests.
4. Version-specific documentation returned by Laravel Boost `search-docs`.
5. General framework conventions.

Do not silently ignore a conflict. Report it before making an incompatible change.

---

# Agent Workflow

## Reasoning Discipline

Use an evidence-driven workflow designed for reliable execution with a medium reasoning budget.

For every non-trivial task:

1. **Inspect** the named files, surrounding implementation, tests, routes, configuration, schema, and relevant documentation.
2. **Define** the objective, current behavior, expected behavior, acceptance criteria, constraints, and out-of-scope work.
3. **Verify assumptions** through repository inspection or version-specific documentation.
4. **Identify risks** involving authorization, validation, transactions, concurrency, lock ordering, idempotency, rollback, security, and edge cases.
5. **Plan** the smallest complete implementation and its validation steps.
6. **Implement** only the required scope, following existing patterns.
7. **Validate** with focused tests first, then broader checks when justified.
8. **Review the diff** as an independent senior engineer before reporting completion.

For trivial, isolated changes, keep the plan brief. Do not add process overhead that exceeds the task.

Do not expose private chain-of-thought. Report conclusions, evidence, decisions, changed files, validation results, and remaining risks.

## Before Editing

You MUST:

* Read every file explicitly named in the task.
* Inspect sibling files for naming, structure, and implementation patterns.
* Search for existing components, actions, services, queries, helpers, factories, and tests before creating new ones.
* Activate the relevant skill from `**/skills/**` when working in that domain.
* Use Laravel Boost `search-docs` before making code changes.
* Inspect relevant routes, configuration, policies, events, jobs, migrations, and relationships when applicable.
* Confirm the tests that will prove the requested behavior.

Do not begin implementation from the task description alone when repository evidence is available.

## Scope Control

* Make the smallest complete change that satisfies the acceptance criteria.
* Do not add speculative features, abstractions, migrations, dependencies, or cleanup.
* Do not modify unrelated files.
* Do not create new top-level directories without approval.
* Do not change dependencies without approval.
* Preserve backward compatibility unless the task explicitly requires otherwise.
* Reuse established project patterns before introducing a new pattern.
* Keep production errors deterministic and safe; do not expose sensitive internals.

## Validation

Every behavior change MUST be programmatically tested.

Run validation in this order:

1. The narrowest relevant test or test file.
2. Related feature or integration tests.
3. Relevant formatting, linting, static analysis, type checking, or build checks.
4. Broader suites only when the change has wider impact.
5. Final diff inspection for accidental or unrelated changes.

Do not claim success without evidence. If a required command was not run, say so clearly.

## Final Self-Review

Before finalizing, check for:

* Missing acceptance criteria.
* Incorrect assumptions.
* Regression risk.
* Authorization or validation gaps.
* Transaction or rollback gaps.
* Race conditions or inconsistent lock ordering.
* Idempotency failures.
* Unsafe exception or data exposure.
* Missing negative-path tests.
* Incomplete loading, empty, or error states.
* Unnecessary files, abstractions, dependencies, or unrelated changes.

## Stop Conditions

Stop editing and report the issue when:

* Authoritative requirements conflict.
* Proceeding requires a material unverified assumption.
* The requested approach violates an established invariant or architecture.
* A destructive operation is required without explicit approval.
* The safe implementation requires an unapproved dependency, migration, public contract, or architecture change.
* Validation reveals a broader failure that materially changes the implementation path.

## Completion Report

Keep the final response concise and include:

* What changed.
* Files changed.
* Tests and validation commands executed.
* Results.
* Remaining risks, assumptions, or unverified items.

---

# Application Context

## Installed Stack

Treat these versions as authoritative:

* PHP 8.5
* Laravel Framework 13
* Inertia Laravel 3
* Inertia React 3
* React 19
* Tailwind CSS 4
* Laravel Fortify 1
* Laravel Horizon 5
* Laravel Prompts 0
* Laravel Reverb 1
* Laravel Wayfinder 0
* Laravel Boost 2
* Laravel MCP 0
* Laravel Pail 1
* Laravel Pint 1
* Laravel Sail 1
* Larastan 3
* Pest 4
* PHPUnit 12
* Laravel Echo 2
* Laravel Echo React 2
* Laravel Vite Plugin Wayfinder 0
* ESLint 9
* Prettier 3

## Repository Conventions

* Follow existing code conventions.
* Check sibling files before creating or editing code.
* Use descriptive names such as `isRegisteredForDiscounts`, not vague names such as `discount()`.
* Reuse existing components and abstractions before creating new ones.
* Keep the existing directory structure.
* Create documentation files only when explicitly requested.
* Prefer tests over temporary verification scripts or Tinker experiments.
* Keep explanations focused on important decisions and results.
* Use `./vendor/bin/sail` when running `composer` or `pnpm`

---

# Laravel Boost

## Preferred Tools

Prefer Laravel Boost tools over manual alternatives when available:

* `search-docs`: version-specific framework and package documentation.
* `database-query`: read-only database queries instead of raw SQL in Tinker.
* `database-schema`: schema inspection before changing migrations or models.
* `get-absolute-url`: resolve the correct scheme, host, and port before sharing an application URL.
* `browser-logs`: recent browser errors, warnings, and exceptions. Ignore stale entries.

## Documentation Search

You MUST use `search-docs` before making code changes.

* Pass a `packages` array when the relevant package is known.
* Use multiple broad, topic-focused queries.
* Do not include package names in query text because package metadata is supplied separately.
* Prefer documentation matching installed versions over remembered behavior.

Examples:

```text
queries=["rate limiting", "routing rate limiting", "routing"]
queries=["deferred props", "infinite scroll", "polling"]
```

Search behavior:

1. `rate limit` uses auto-stemmed AND matching.
2. `"infinite scroll"` requires the exact adjacent phrase.
3. `middleware "rate limit"` combines normal and exact matching.
4. Multiple queries provide OR-style coverage.

---

# Laravel Rules

## Artisan

* Use `php artisan make:*` commands for framework-managed files.
* Use `php artisan make:class` for generic PHP classes.
* Pass `--no-interaction` to generation commands.
* Use `php artisan list` to discover commands.
* Use `php artisan <command> --help` before relying on unfamiliar options.

Useful inspection commands:

```bash
php artisan route:list
php artisan route:list --method=GET
php artisan route:list --name=users
php artisan route:list --path=api
php artisan route:list --except-vendor
php artisan route:list --only-vendor
php artisan config:show app.name
php artisan config:show database.default
```

## Models and Test Data

* Use model factories in tests.
* Check for existing factory states before manually specifying attributes.
* When adding a model, create useful factories and seeders when the project requires them.
* Check `php artisan make:model --help` before selecting generator options.
* Do not create production models or data through Tinker without explicit approval.
* Follow the repository's Faker convention: either `$this->faker` or `fake()`.

## APIs

* Default to Eloquent API Resources and API versioning for new APIs.
* Follow an existing repository pattern when the application already uses another established API architecture.

## Routing and URLs

* Prefer named routes and `route()`.
* Use Wayfinder-generated TypeScript route functions.
* Import controller actions from `@/actions/`.
* Import named routes from `@/routes/`.

## Configuration

* Use `php artisan config:show` or inspect files in `config/`.
* Do not assume `.env.example` values are active runtime values.

## Tinker

Use Tinker only when a Boost tool, Artisan command, or automated test is not more appropriate.

Use single quotes around shell arguments:

```bash
php artisan tinker --execute 'User::where("active", true)->count();'
```

---

# PHP Rules

* Always use braces for control structures, including single-line bodies.
* Use constructor property promotion when appropriate.
* Do not add empty public zero-argument constructors. Private constructors are allowed when required by the design.
* Add explicit parameter types and return types.
* Use TitleCase enum cases such as `FavoritePerson`, `BestLake`, and `Monthly`.
* Prefer PHPDoc blocks over inline comments.
* Add inline comments only for genuinely non-obvious logic.
* Use PHPDoc array shapes where they improve static analysis.

Example:

```php
public function __construct(public GitHub $github)
{
}

public function isAccessible(User $user, ?string $path = null): bool
{
    // ...
}
```

---

# Testing and Code Quality

## Test Enforcement

* Every behavior change requires a new test or an update to an existing test.
* Prefer feature tests for application behavior.
* Use unit tests for isolated logic without framework integration.
* Test success, failure, and rollback behavior when applicable.
* Do not delete tests without approval.

## Pest Commands

Create tests with:

```bash
php artisan make:test --pest SomeFeatureTest --no-interaction
php artisan make:test --pest SomeUnitTest --unit --no-interaction
```

Do not include `Feature/` or `Unit/` in the test name argument.

Run focused tests with:

```bash
php artisan test --compact tests/Feature/Path/SomeFeatureTest.php
php artisan test --compact --filter=testName
```

## Laravel Pint

After modifying PHP files, run:

```bash
vendor/bin/pint --dirty --format agent
```

Do not use `vendor/bin/pint --test --format agent` as the final formatting step.

## Other Checks

Run only checks relevant to the change, using existing repository scripts:

* Larastan or project PHP static analysis.
* ESLint for frontend code.
* Prettier or repository formatting scripts.
* TypeScript type checking.
* Production builds when bundling behavior may be affected.

Do not invent verification scripts when existing project scripts cover the same behavior.

---

# Inertia and React

## Architecture

* Inertia pages live in `resources/js/pages` unless `vite.config.js` specifies otherwise.
* Use `Inertia::render()` for server-driven Inertia pages instead of introducing Blade views.
* Activate the `inertia-react-development` skill for Inertia React work.
* Use `search-docs` before implementing Inertia behavior.

## Inertia 3 Rules

* Use standalone HTTP requests through `useHttp` when appropriate.
* Optimistic updates support automatic rollback.
* Layout props are available through `useLayoutProps`.
* Instant visits are supported.
* SSR is handled through `@inertiajs/vite` during Vite development.
* Deferred props, infinite scroll, merged props, polling, prefetching, once props, and flash data remain available.
* Use `Inertia::optional()` instead of removed `Inertia::lazy()` or `LazyProp` APIs.
* Nested prop types support dot-notation paths.
* Use the built-in XHR client unless Axios is explicitly installed.
* Use `httpException` instead of the removed `invalid` event.
* Use `networkError` instead of the removed `exception` event.
* Use `router.cancelAll()` instead of removed `router.cancel()`.
* Do not use the removed `future` configuration namespace.
* Deferred data must have an accessible loading or empty state with an appropriate skeleton.

## Frontend Bundling

When a frontend change is not reflected in the browser, verify the relevant process:

```bash
pnpm run dev
pnpm run build
composer run dev
```

For an `Illuminate\Foundation\ViteException` reporting a missing manifest entry, run the relevant frontend build or development command before changing application code.

---

# Deployment

* Laravel Cloud is a supported Laravel deployment option.
* Do not alter deployment architecture, infrastructure dependencies, or production configuration unless explicitly required.
* Treat deployment changes as production-sensitive. Review rollback, queues, scheduler, storage, cache, sessions, and environment implications when applicable.

---

# Completion Checklist

Before declaring a task complete, confirm:

* [ ] Relevant skills were activated.
* [ ] Version-specific documentation was searched.
* [ ] Existing patterns and sibling files were inspected.
* [ ] Scope and acceptance criteria were identified.
* [ ] The smallest complete implementation was made.
* [ ] Required tests were added or updated.
* [ ] Focused tests passed.
* [ ] Relevant formatting, linting, static analysis, type checking, or build checks passed.
* [ ] PHP files were formatted with Pint when applicable.
* [ ] The final diff contains no unrelated changes.
* [ ] Security, authorization, transactions, concurrency, idempotency, rollback, and error exposure were reviewed when relevant.
* [ ] Remaining risks and unverified items were reported honestly.
