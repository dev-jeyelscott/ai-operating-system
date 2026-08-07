import { useSyncExternalStore } from 'react';

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

/**
 * Return whether the operating system requests reduced motion.
 *
 * The browser media query is treated as an external store so React can read a
 * stable snapshot and subscribe without mirroring browser state through an
 * effect.
 */
export function usePrefersReducedMotion(): boolean {
    return useSyncExternalStore(
        subscribeToReducedMotionPreference,
        getReducedMotionPreference,
        getServerReducedMotionPreference,
    );
}

/**
 * Subscribe to changes in the browser's reduced-motion preference.
 */
function subscribeToReducedMotionPreference(
    onPreferenceChange: () => void,
): () => void {
    if (
        typeof window === 'undefined' ||
        typeof window.matchMedia !== 'function'
    ) {
        return () => undefined;
    }

    const mediaQuery = window.matchMedia(REDUCED_MOTION_QUERY);

    /**
     * Notify React that the external preference snapshot may have changed.
     */
    function handleChange(): void {
        onPreferenceChange();
    }

    mediaQuery.addEventListener('change', handleChange);

    return () => {
        mediaQuery.removeEventListener('change', handleChange);
    };
}

/**
 * Read the current browser preference snapshot.
 */
function getReducedMotionPreference(): boolean {
    if (
        typeof window === 'undefined' ||
        typeof window.matchMedia !== 'function'
    ) {
        return false;
    }

    return window.matchMedia(REDUCED_MOTION_QUERY).matches;
}

/**
 * Return a deterministic server snapshot for SSR and hydration.
 */
function getServerReducedMotionPreference(): boolean {
    return false;
}
