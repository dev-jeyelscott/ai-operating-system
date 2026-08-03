import type { MouseEvent } from 'react';

/**
 * Provide a keyboard-visible mechanism for bypassing repeated application
 * navigation and moving focus directly to the primary content landmark.
 */
export function SkipLink() {
    /**
     * Focus and reveal the main content target without adding it to the normal
     * sequential tab order.
     */
    function focusMainContent(event: MouseEvent<HTMLAnchorElement>) {
        const mainContent = document.getElementById('main-content');

        if (!mainContent) {
            return;
        }

        event.preventDefault();

        mainContent.focus({
            preventScroll: true,
        });

        mainContent.scrollIntoView({
            behavior: 'auto',
            block: 'start',
        });
    }

    return (
        <a
            href="#main-content"
            onClick={focusMainContent}
            className="fixed top-4 left-4 z-[100] -translate-y-24 rounded-md bg-background px-4 py-2 text-sm font-semibold text-foreground shadow-lg ring-2 ring-foreground transition-transform focus:translate-y-0 focus:outline-none motion-reduce:transition-none"
        >
            Skip to main content
        </a>
    );
}
