BEGIN;

SELECT *
FROM audit_events
WHERE organization_id = :organization_id
  AND project_id = :project_id
ORDER BY sequence DESC, occurred_at DESC
LIMIT 51;

COMMIT;
