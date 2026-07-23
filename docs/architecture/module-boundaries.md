# AI Operating System Module Boundaries

## Architecture style

The application is a Laravel modular monolith deployed as one application.
Modules are logical ownership boundaries rather than independently deployed
services.

## Layers

### Domain

`app/Domain` owns business rules, entities, value objects, domain services,
domain exceptions, repository contracts, and domain events.

Domain code must not depend on Laravel HTTP classes, concrete persistence,
external APIs, queue transports, or presentation concerns.

### Application

`app/Application` owns use cases and orchestration.

Application code may coordinate domain objects and depend on contracts, but it
must not depend directly on concrete external providers or transport-specific
classes.

### Infrastructure

`app/Infrastructure` owns implementations for persistence, Notion, repositories,
object storage, provider runtimes, and other external services.

Infrastructure implements contracts declared by the inner layers.

### Delivery

Controllers, console commands, queue jobs, and scheduled commands translate
external input into application calls and translate application results back
into transport responses.

## Module ownership

| Module | Owns |
|---|---|
| Identity | Users, authentication identity, memberships |
| Projects | Project aggregate, project configuration, lifecycle |
| Documents | Source documents, versions, approval, parsing |
| Requirements | Extracted and approved requirements |
| Roadmaps | Roadmaps, phases, milestones, traceability |
| Tasks | Implementation tickets, dependencies, blockers |
| Workflows | Workflow definitions, transitions, recovery |
| Agents | Logical engineering roles and profiles |
| Providers | Provider-neutral capability and routing contracts |
| Executions | Execution attempts, timings, status, costs |
| Approvals | Approval requests and decisions |
| Evidence | Assumptions, artifacts, evidence classifications |
| Workspaces | Execution workspaces and snapshots |
| Integrations | Integration metadata and synchronization contracts |
| Audit | Append-only application audit events and safe audit metadata |

## Dependency rules

1. Domain code may depend only on PHP, the same domain module, and approved
   shared-domain primitives.
2. Application code may depend on domain code and declared contracts.
3. Infrastructure may depend on application contracts and domain objects.
4. HTTP, console, and job entry points may depend on application services.
5. Domain and application layers must not use `DB::table`.
6. A module must not directly access tables owned by another module.
7. Cross-module operations require a public application service, contract, or
   domain event.
8. Provider names, model identifiers, CLI flags, and response formats must not
   appear in provider-neutral domain objects.

## Database ownership

Table ownership is documented with each migration. Foreign keys may reference
another module when required by the domain model, but write behavior must pass
through the owning module.

Raw cross-module table writes are prohibited.

## Exceptions

An exception requires an accepted Architecture Decision Record before the code
is merged.
