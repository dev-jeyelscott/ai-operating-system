\set ON_ERROR_STOP on
\pset pager off
\timing on

\echo 'AIOS-144: latest approved roadmap'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT
    id,
    project_context_snapshot_id,
    revision,
    approved_at
FROM roadmaps
WHERE project_id = :project_id
  AND status = 'approved'
  AND approved_at IS NOT NULL
ORDER BY revision DESC
LIMIT 1;

\echo 'AIOS-144: workable ticket candidates'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM roadmap_tasks
WHERE roadmap_id = :roadmap_id
  AND status IN ('ready', 'changes_requested');

\echo 'AIOS-144: active project leases'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT
    roadmap_task_id,
    execution_id,
    acquired_at
FROM ticket_execution_leases
WHERE project_id = :project_id
  AND released_at IS NULL
ORDER BY acquired_at, id;

\echo 'AIOS-144: project audit timeline'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM audit_events
WHERE organization_id = :organization_id
  AND project_id = :project_id
ORDER BY sequence DESC, occurred_at DESC
LIMIT 51;

\echo 'AIOS-144: unread notification count'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT COUNT(*)
FROM notification_recipients
WHERE organization_id = :organization_id
  AND recipient_user_id = :recipient_user_id
  AND read_at IS NULL;

\echo 'AIOS-144: notification inbox'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT
    recipients.id,
    recipients.notification_event_id,
    recipients.delivered_at,
    recipients.read_at,
    recipients.created_at,
    events.project_id,
    events.event_name,
    events.title,
    events.message,
    events.occurred_at
FROM notification_recipients AS recipients
JOIN notification_events AS events
  ON events.id = recipients.notification_event_id
WHERE recipients.organization_id = :organization_id
  AND recipients.recipient_user_id = :recipient_user_id
ORDER BY recipients.created_at DESC, recipients.id DESC
LIMIT 50;

\echo 'AIOS-144: existing office projection'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM office_projections
WHERE organization_id = :organization_id
  AND project_id = :project_id
LIMIT 1;

\echo 'AIOS-144: office projection outbox checkpoint'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT
    sequence,
    event_id
FROM outbox_messages
WHERE organization_id = :organization_id
  AND project_id = :project_id
ORDER BY sequence DESC
LIMIT 1;

\echo 'AIOS-144: office projection roadmap tasks'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM roadmap_tasks
WHERE roadmap_id = :roadmap_id
ORDER BY position, stable_id;

\echo 'AIOS-144: office projection executions'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM executions
WHERE project_id = :project_id
  AND (
      status IN (
          'queued',
          'running',
          'waiting_for_approval',
          'waiting_for_evidence',
          'blocked',
          'retry_scheduled'
      )
      OR created_at >= CURRENT_TIMESTAMP - INTERVAL '7 days'
  )
ORDER BY created_at DESC
LIMIT 100;

\echo 'AIOS-144: latest execution attempts'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM execution_attempts
WHERE execution_id IN (
    SELECT id
    FROM executions
    WHERE project_id = :project_id
    ORDER BY created_at DESC
    LIMIT 100
)
ORDER BY execution_id, attempt_number DESC;

\echo 'AIOS-144: pending approvals'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM approvals
WHERE project_id = :project_id
  AND status = 'pending'
ORDER BY requested_at, id;

\echo 'AIOS-144: recent QA assessments'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM qa_assessments
WHERE project_id = :project_id
ORDER BY created_at DESC
LIMIT 25;

\echo 'AIOS-144: recent merge decisions'
EXPLAIN (
    ANALYZE,
    BUFFERS,
    SETTINGS,
    FORMAT JSON
)
SELECT *
FROM merge_decisions
WHERE project_id = :project_id
ORDER BY decided_at DESC
LIMIT 25;
