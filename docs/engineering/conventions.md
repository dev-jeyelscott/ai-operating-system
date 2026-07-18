# Engineering Conventions

## PHP

- Use PHP 8.5.
- Add `declare(strict_types=1);` to new application PHP files.
- Use final classes unless extension is an intentional contract.
- Controllers, commands, listeners, and jobs must remain thin.
- Domain decisions belong in domain objects or application use cases.
- Validate all external input at the application boundary.
- Do not log secrets, credentials, access tokens, cookies, or complete request
  bodies.
- Format with Laravel Pint.
- Analyze application code with Larastan/PHPStan.

## TypeScript and React

- TypeScript strict mode is mandatory.
- Avoid `any`; use `unknown` and validate it.
- Keep server-authoritative business rules in Laravel.
- Components should not reproduce authorization or workflow policies.
- Use React Testing Library for behavior, not implementation details.
- Use Playwright for critical browser flows.

## Database migrations

- Use expand-and-contract migrations.
- Never combine irreversible destructive schema changes with dependent
  application behavior in one deployment.
- Add indexes for foreign keys and documented query paths.
- Document table ownership by module.
- Migrations must support rollback when technically safe.
- Data migrations must be restartable and idempotent.

## Testing

- Every defect fix requires a regression test.
- Domain rules require unit tests.
- Persistence and external boundaries require integration tests.
- Critical user journeys require Playwright coverage.
- Tests must not depend on execution order.
- Simulated results must be labeled and must not be asserted as verified
  engineering evidence.

## Git

Use Conventional Commits:

- `feat:`
- `fix:`
- `refactor:`
- `test:`
- `docs:`
- `build:`
- `ci:`
- `chore:`

Automated implementation branches target `develop`, never `main`.

## Pull requests

Each pull request must include:

- Ticket identifiers
- Objective
- Included and excluded scope
- Architecture impact
- Security impact
- Database impact
- Validation commands and results
- Rollback or recovery notes
- Remaining risks
