import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { usePrefersReducedMotion } from '@/features/office/hooks/use-prefers-reduced-motion';

type ChangeListener = (event: MediaQueryListEvent) => void;

/**
 * Install a controllable matchMedia implementation for one test.
 */
function installMatchMedia(initialMatches: boolean) {
    let matches = initialMatches;
    const listeners = new Set<ChangeListener>();

    const mediaQuery = {
        get matches() {
            return matches;
        },
        media: '(prefers-reduced-motion: reduce)',
        onchange: null,
        addEventListener: vi.fn((_type: string, listener: ChangeListener) => {
            listeners.add(listener);
        }),
        removeEventListener: vi.fn(
            (_type: string, listener: ChangeListener) => {
                listeners.delete(listener);
            },
        ),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    } as unknown as MediaQueryList;

    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => mediaQuery),
    );

    return {
        mediaQuery,
        setMatches(nextMatches: boolean) {
            matches = nextMatches;

            const event = {
                matches: nextMatches,
                media: mediaQuery.media,
            } as MediaQueryListEvent;

            for (const listener of listeners) {
                listener(event);
            }
        },
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('usePrefersReducedMotion', () => {
    it('returns the initial operating-system preference', () => {
        installMatchMedia(true);

        const { result } = renderHook(() => usePrefersReducedMotion());

        expect(result.current).toBe(true);
    });

    it('updates when the preference changes', () => {
        const matchMedia = installMatchMedia(false);
        const { result } = renderHook(() => usePrefersReducedMotion());

        act(() => {
            matchMedia.setMatches(true);
        });

        expect(result.current).toBe(true);
    });

    it('removes the media-query listener on unmount', () => {
        const matchMedia = installMatchMedia(false);
        const { unmount } = renderHook(() => usePrefersReducedMotion());

        unmount();

        expect(matchMedia.mediaQuery.removeEventListener).toHaveBeenCalledWith(
            'change',
            expect.any(Function),
        );
    });
});
