import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

type OfficeProjectionUpdatedMessage = {
    event_id: string;
    event_name: string;
    organization_id: number;
    project_id: number | null;
    execution_id: string | null;
    data: {
        source_event_name: string;
        projection_sequence: number;
        projection_fingerprint: string;
        projected_at: string;
    };
};

type Props = {
    organizationId: number;
    projectId: number;
    lastEventSequence: number;
    onNewerProjection: () => void;
};

/**
 * Listen for office invalidations while keeping the persisted projection
 * authoritative.
 *
 * Live events never mutate workflow or provider state in React. A newer durable
 * sequence causes an Inertia projection reload. The page's existing poll remains
 * the recovery path for dropped delivery and reconnect.
 */
export function useOfficeProjectionStream({
    organizationId,
    projectId,
    lastEventSequence,
    onNewerProjection,
}: Props) {
    const sequenceRef = useRef(lastEventSequence);
    const seenEventIds = useRef(new Set<string>());

    /**
     * Reconcile local duplicate suppression with the latest server projection.
     */
    useEffect(() => {
        sequenceRef.current = Math.max(
            sequenceRef.current,
            lastEventSequence,
        );
    }, [lastEventSequence]);

    useEcho<OfficeProjectionUpdatedMessage>(
        `organizations.${organizationId}.projects.${projectId}.stream`,
        '.office.projection_updated',
        (message) => {
            if (
                message.organization_id !== organizationId ||
                message.project_id !== projectId
            ) {
                return;
            }

            if (seenEventIds.current.has(message.event_id)) {
                return;
            }

            const incomingSequence =
                message.data.projection_sequence;

            if (incomingSequence <= sequenceRef.current) {
                seenEventIds.current.add(message.event_id);

                return;
            }

            if (seenEventIds.current.size >= 100) {
                seenEventIds.current.clear();
            }

            seenEventIds.current.add(message.event_id);
            sequenceRef.current = incomingSequence;

            onNewerProjection();
        },
    );
}
