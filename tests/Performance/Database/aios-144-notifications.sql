BEGIN;

SELECT COUNT(*)
FROM notification_recipients
WHERE organization_id = :organization_id
  AND recipient_user_id = :recipient_user_id
  AND read_at IS NULL;

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

COMMIT;
