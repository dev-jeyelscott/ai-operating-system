BEGIN;

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

SELECT *
FROM roadmap_tasks
WHERE roadmap_id = :roadmap_id
  AND status IN ('ready', 'changes_requested');

SELECT
    roadmap_task_id
FROM ticket_execution_leases
WHERE project_id = :project_id
  AND released_at IS NULL;

SELECT
    dependencies.roadmap_task_id,
    dependencies.depends_on_task_id,
    dependency_tasks.status
FROM task_dependencies AS dependencies
JOIN roadmap_tasks AS dependency_tasks
  ON dependency_tasks.id = dependencies.depends_on_task_id
WHERE dependencies.roadmap_task_id IN (
    SELECT id
    FROM roadmap_tasks
    WHERE roadmap_id = :roadmap_id
      AND status IN ('ready', 'changes_requested')
);

COMMIT;
