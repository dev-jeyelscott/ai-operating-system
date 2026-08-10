import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { OfficeActivity } from '@/features/office/types';

type Props = {
    activities: OfficeActivity[];
};

/**
 * Render privacy-safe provider planning activity in durable sequence order.
 *
 * The log is an accessible equivalent of visual activity in the 3D office and
 * contains only backend-generated summaries.
 */
export function OfficeActivityFeed({ activities }: Props) {
    return (
        <Card>
            <CardHeader>
                <CardTitle id="office-provider-activity-heading">
                    Provider planning activity
                </CardTitle>
                <CardDescription>
                    Durable backend activity ordered by event sequence. Provider
                    payloads, prompts, commands, and document contents are not
                    exposed here.
                </CardDescription>
            </CardHeader>

            <CardContent>
                {activities.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                        No provider planning activity has been projected yet.
                    </p>
                ) : (
                    <ol
                        role="log"
                        aria-live="polite"
                        aria-relevant="additions"
                        aria-labelledby="office-provider-activity-heading"
                        className="space-y-3"
                    >
                        {activities.map((activity) => (
                            <li
                                key={activity.eventId}
                                className="rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline">
                                        {humanize(activity.state)}
                                    </Badge>

                                    {activity.provider && (
                                        <Badge variant="secondary">
                                            {activity.provider}
                                        </Badge>
                                    )}
                                </div>

                                <p className="mt-2 text-sm font-medium">
                                    {activity.summary}
                                </p>

                                <p className="mt-1 text-xs text-muted-foreground">
                                    Sequence {activity.sequence} ·{' '}
                                    {formatDate(activity.occurredAt)}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Convert enum-like values into readable labels.
 */
function humanize(value: string) {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * Format one durable provider activity timestamp in UTC.
 */
function formatDate(value: string) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Unknown time';
    }

    return `${date.toISOString().slice(0, 19).replace('T', ' ')} UTC`;
}
