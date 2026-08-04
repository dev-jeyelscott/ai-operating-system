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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/wayfinder (WAYFINDER) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- @laravel/echo-react (ECHO_REACT) - v2
- laravel-echo (ECHO) - v2
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `pnpm run build`, `pnpm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `pnpm run build` or ask the user to run `pnpm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
