# Authorization Matrix

## Purpose

This document is the canonical authorization reference for organization- and
project-scoped behavior.

Production policies and domain permission code remain executable truth. This
matrix documents those decisions so policy code, tests, request handling, and
frontend visibility rules use the same vocabulary.

## Decision semantics

| Decision | Meaning |
|---|---|
| Allow | The authenticated actor may perform the operation. |
| 403 | The actor belongs to the target organization but the assigned role does not grant the requested operation. Policies return an ordinary `Response::deny(...)`. |
| 404 | The actor does not belong to the target organization, the resource belongs to another organization, or scoped route binding rejects the parent-child relationship. Policies use `Response::denyAsNotFound()` when the resource has already been resolved. |
| Not applicable | The operation is not evaluated against a target organization role. |

A `404` denial is a resource-hiding control. It must not be used for an
insufficient role inside an organization the actor is already permitted to
know exists.

## Organization roles

| Role | Intent |
|---|---|
| `owner` | Full organization control, including destructive and ownership-transfer operations. |
| `administrator` | Operational administration without organization deletion or ownership transfer. |
| `member` | Contributor access to organization and project work. |
| `viewer` | Read-only access. |
| Non-member | An authenticated user without a membership in the target organization. |

Roles are organization-scoped. A user may hold different roles in different
organizations.

## Account-level organization abilities

These abilities do not target an existing organization role.

| Operation | Policy ability | Allowed | Denied |
|---|---|---|---|
| Create an organization | `OrganizationPolicy::create` | User with a verified email address | User without a verified email address |
| List accessible organizations | `OrganizationPolicy::viewAny` | User with at least one organization membership | User with no organization memberships |

## Organization-scoped abilities

| Operation | Policy ability | Owner | Administrator | Member | Viewer | Non-member |
|---|---|---:|---:|---:|---:|---:|
| View organization | `view` | Allow | Allow | Allow | Allow | 404 |
| Update organization metadata | `update` | Allow | Allow | 403 | 403 | 404 |
| Delete organization | `delete` | Allow | 403 | 403 | 403 | 404 |
| Manage memberships | `manageMembers` | Allow | Allow | 403 | 403 | 404 |
| Transfer ownership | `transferOwnership` | Allow | 403 | 403 | 403 | 404 |
| Create a project | `createProject` | Allow | Allow | Allow | 403 | 404 |

Membership invitation, role-transition, removal, and ownership-transfer use
cases must continue to enforce any additional final-owner or lifecycle
invariants when those use cases are exposed.

## Project permission matrix

Project permissions are derived from the actor's role in the project's owning
organization.

| Operation | Permission / policy ability | Owner | Administrator | Member | Viewer | Non-member |
|---|---|---:|---:|---:|---:|---:|
| View project | `ProjectPermission::View` / `ProjectPolicy::view` | Allow | Allow | Allow | Allow | 404 |
| Create project | `ProjectPermission::Create` / `OrganizationPolicy::createProject` | Allow | Allow | Allow | 403 | 404 |
| Update project | `ProjectPermission::Update` / `ProjectPolicy::update` | Allow | Allow | Allow | 403 | 404 |
| Archive project | `ProjectPermission::Archive` / `ProjectPolicy::archive` | Allow | Allow | 403 | 403 | 404 |
| Restore project | `ProjectPermission::Restore` / `ProjectPolicy::restore` | Allow | Allow | 403 | 403 | 404 |

Project deletion is not currently an implemented permission and is therefore
not part of this matrix.

## Resource-hiding contract

Authorization uses defense in depth:

1. Organization policies return `404` for actors without membership in the
   target organization.
2. Project policies return `404` for actors without membership in the
   project's owning organization.
3. Scoped route bindings return `404` when a project does not belong to the
   organization in the route, even if the actor belongs to both organizations.
4. Tenant-qualified repositories require an organization identifier and must
   not return or mutate a project from another organization.
5. Session state such as `current_organization_id` is a navigation preference
   and never grants authorization.

A same-tenant actor with an insufficient role receives an ordinary policy
denial, which becomes `403` at the HTTP boundary. This distinction prevents
role failures from being confused with tenant-boundary failures.

## Enforcement locations

| Concern | Enforcement |
|---|---|
| Organization role vocabulary | `App\Domain\Identity\OrganizationRole` |
| Project permission vocabulary | `App\Domain\Projects\ProjectPermission` |
| Role-to-project-permission decisions | `App\Domain\Projects\ProjectPermissionMatrix` |
| Organization authorization | `App\Policies\OrganizationPolicy` |
| Existing-project authorization | `App\Policies\ProjectPolicy` |
| Parent-child resource ownership | Laravel scoped route bindings |
| Persistence tenant qualification | Project repository methods and `Project::forOrganization()` |
| Request-level tenant isolation | `tests/Feature/Security/OrganizationIsolationTest.php` |

Frontend components may hide unavailable actions for usability, but frontend
visibility is never an authorization control.

## Required test coverage

- `tests/Unit/Policies/ProjectPermissionMatrixTest.php` covers every
  `OrganizationRole` and `ProjectPermission` pair without booting Laravel or
  accessing the database.
- `tests/Feature/Policies/OrganizationPolicyTest.php` covers verified and
  unverified creation, membership-based listing, every scoped role decision,
  ordinary denials, and non-member `404` responses using database-backed
  memberships.
- `tests/Feature/Security/OrganizationIsolationTest.php` covers request-level
  cross-tenant access, scoped binding, mutation side effects, and resource
  hiding.
- Repository hygiene rejects zero-byte security documentation and policy or
  security test files.

## Change-control rules

Any change to an organization role, project permission, or policy decision must
update all applicable items in the same change:

1. Domain enums and permission-matrix code.
2. Organization or project policies.
3. This authorization matrix.
4. Unit and feature datasets covering the changed decision.
5. Request-level security tests when HTTP status, binding, or side effects
   change.
6. User-interface visibility rules, without moving authorization to the
   frontend.

Never weaken or remove authorization coverage only to make CI pass. A mismatch
between approved documentation and production behavior requires an explicit
security decision before policy behavior changes.