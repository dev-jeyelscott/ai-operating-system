BEGIN;

SELECT *
FROM office_projections
WHERE organization_id = :organization_id
  AND project_id = :project_id
LIMIT 1;

SELECT
    sequence,
    event_id
FROM outbox_messages
WHERE organization_id = :organization_id
  AND project_id = :project_id
ORDER BY sequence DESC
LIMIT 1;

SELECT *
FROM roadmaps
WHERE project_id = :project_id
  AND status = 'approved'
  AND approved_at IS NOT NULL
ORDER BY revision DESC
LIMIT 1;

SELECT *
FROM roadmap_tasks
WHERE roadmap_id = :roadmap_id
ORDER BY position, stable_id;

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

SELECT *
FROM ticket_execution_leases
WHERE project_id = :project_id
  AND released_at IS NULL
ORDER BY acquired_at;

SELECT *
FROM approvals
WHERE project_id = :project_id
  AND status = 'pending'
ORDER BY requested_at, id;

SELECT *
FROM qa_assessments
WHERE project_id = :project_id
ORDER BY created_at DESC
LIMIT 25;

SELECT *
FROM merge_decisions
WHERE project_id = :project_id
ORDER BY decided_at DESC
LIMIT 25;

COMMIT;
