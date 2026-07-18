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
- AIOS-017: tenant-safe repository and query scoping;
- AIOS-018: append-only audit events;
- AIOS-020: complete cross-organization IDOR security tests.

Membership invitations, role transitions, member removal, and ownership
transfer must not be exposed until authorization and final-owner invariants are
implemented.
