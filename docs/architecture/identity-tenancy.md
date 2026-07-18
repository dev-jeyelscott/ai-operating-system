# Identity and Organization Tenancy

## Ownership

The Identity module owns:

- users as authentication identities;
- organizations;
- organization memberships;
- organization-scoped roles.

## Organization membership model

A user belongs to an organization through an explicit
`organization_memberships` record.

A user may belong to multiple organizations and may have a different role in
each organization.

The supported roles are:

- `owner`
- `administrator`
- `member`
- `viewer`

The permission matrix is not defined by the enum. Server-side organization and
project policies are introduced by AIOS-013.

## Invariants

1. Organization creation and initial owner membership commit atomically.
2. Every newly created organization begins with one owner.
3. A user may have only one membership in a given organization.
4. Membership roles must match the supported organization role vocabulary.
5. Organization deletion cascades to its membership records.
6. User deletion is restricted while organization memberships remain.
7. Organization ownership is represented by membership role, not by a separate
   `owner_id` field on the organization.
8. Users do not contain a single `organization_id` because membership is
   many-to-many.

## Deferred behavior

The following behavior is implemented in later tickets:

- AIOS-013: organization and project authorization policies;
- AIOS-014: current organization selection and scoped navigation;
- AIOS-018: append-only audit events;
- AIOS-020: complete cross-organization IDOR security tests.

Membership invitations, role transitions, member removal, and ownership
transfer must not be exposed until authorization and final-owner invariants are
implemented.

## Current organization context

The authenticated user's selected organization is stored in the session as a
navigation preference under `current_organization_id`.

Organization-scoped routes use the organization's unique slug. The organization
present in the route is the authoritative resource context for the request.

Session state does not grant organization access. Every organization-scoped
route must continue to execute server-side policy authorization.

When a session preference is stale, deleted, or no longer accessible, the
application falls back to the first organization returned by the authenticated
user's membership query.

Users without organizations remain on the unscoped dashboard onboarding state.

AIOS-017 remains responsible for enforcing mandatory organization filtering in
project repositories and database queries.

## Tenant-safe project persistence

Project persistence uses explicit organization qualification rather than ambient
session state.

Every project repository operation requires an `organizationId`. Reads,
updates, archive operations, restore operations, and lifecycle transitions begin
with the `Project::forOrganization()` query scope.

A project identifier from another organization therefore produces a not-found
result rather than returning or modifying the foreign project.

Organization-scoped HTTP routes continue to use Laravel scoped bindings, and
project policies continue to authorize the authenticated user's role. Route
binding, policies, and repository scoping are independent defense-in-depth
controls.

A global Eloquent tenant scope is intentionally not used. Console commands,
queue workers, Horizon workers, tests, and future workflow execution may not
have an HTTP session. These callers must pass the organization identifier
explicitly.
