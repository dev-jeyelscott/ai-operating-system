import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

/**
 * Provide a minimal component proving that Vitest and React Testing Library
 * are configured and usable by future feature tests.
 */
function TestHarness() {
    return <h1>AI Operating System</h1>;
}

describe('frontend test harness', () => {
    it('renders a React component', () => {
        render(<TestHarness />);

        expect(
            screen.getByRole('heading', {
                name: 'AI Operating System',
            }),
        ).toBeInTheDocument();
    });
});
