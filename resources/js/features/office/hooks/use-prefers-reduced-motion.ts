import { useEffect, useState } from 'react';

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

/**
 * Return whether the operating system requests reduced motion.
 *
 * The hook is SSR-safe and keeps the value synchronized when the user changes
 * the operating-system preference while the application remains open.
 */
export function usePrefersReducedMotion(): boolean {
    const [reducedMotion, setReducedMotion] = useState(() =>
        currentReducedMotionPreference(),
    );

    useEffect(() => {
        if (
            typeof window === 'undefined' ||
            typeof window.matchMedia !== 'function'
        ) {
            return undefined;
        }

        const mediaQuery = window.matchMedia(REDUCED_MOTION_QUERY);

        /**
         * Synchronize React state with the browser accessibility preference.
         */
        function handleChange(event: MediaQueryListEvent) {
            setReducedMotion(event.matches);
        }

        setReducedMotion(mediaQuery.matches);
        mediaQuery.addEventListener('change', handleChange);

        return () => {
            mediaQuery.removeEventListener('change', handleChange);
        };
    }, []);

    return reducedMotion;
}

/**
 * Read the current preference without assuming a browser environment.
 */
function currentReducedMotionPreference(): boolean {
    if (
        typeof window === 'undefined' ||
        typeof window.matchMedia !== 'function'
    ) {
        return false;
    }

    return window.matchMedia(REDUCED_MOTION_QUERY).matches;
}
