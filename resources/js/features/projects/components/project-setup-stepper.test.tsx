import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ProjectSetupStepper } from './project-setup-stepper';

const steps = [
    {
        value: 'details',
        label: 'Technology stack',
        description: 'Configure stack.',
        completed: true,
        active: false,
        canVisit: true,
        href: '/setup/details',
    },
    {
        value: 'repository',
        label: 'Repository',
        description: 'Configure repository.',
        completed: false,
        active: true,
        canVisit: true,
        href: '/setup/repository',
    },
    {
        value: 'commands',
        label: 'Validation commands',
        description: 'Configure commands.',
        completed: false,
        active: false,
        canVisit: false,
        href: '/setup/commands',
    },
];

describe('ProjectSetupStepper', () => {
    it('marks the current step for assistive technology', () => {
        render(<ProjectSetupStepper steps={steps} activeStep="repository" />);

        expect(
            screen.getByRole('link', {
                name: /repository/i,
                current: 'step',
            }),
        ).toHaveAttribute('aria-current', 'step');
    });

    it('does not render unavailable future steps as links', () => {
        render(<ProjectSetupStepper steps={steps} activeStep="repository" />);

        expect(
            screen.queryByRole('link', {
                name: /validation commands/i,
            }),
        ).not.toBeInTheDocument();

        expect(
            screen
                .getByText('Validation commands')
                .closest('[aria-disabled="true"]'),
        ).not.toBeNull();
    });
});
