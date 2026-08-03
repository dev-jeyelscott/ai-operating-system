# Operational runbooks

| Runbook | Primary command | Consequential action |
|---|---|---|
| [Failed Notion publication](failed-notion-publication.md) | Retry and reconciliation application action | External ticket update |
| [Stuck ticket lease](stuck-ticket-lease.md) | `executions:recover` | Lease recovery |
| [Dead-letter recovery](dead-letter-recovery.md) | `dead-letters:list`, `dead-letters:replay` | Re-dispatch processing |
| [Office projection rebuild](office-projection-rebuild.md) | `office:projections:rebuild` | Replace derived state |
| [Integration outage](integration-outage.md) | Circuit-breaker and reconciliation controls | External synchronization |
| [Backup and restore](backup-and-restore.md) | Restore scripts | Data recovery |

Use the smallest safe scope, preserve audit evidence, and obtain the required operator authorization before a consequential action.
