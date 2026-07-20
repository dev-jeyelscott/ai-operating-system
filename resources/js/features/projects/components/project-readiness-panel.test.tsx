import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ProjectReadinessPanel } from './project-readiness-panel';

describe('ProjectReadinessPanel', () => {
    it('renders the complete state without remediation links', () => {
        render(
            <ProjectReadinessPanel
                title="Project readiness"
                validation={{
                    complete: true,
                    missingConfiguration: [],
                }}
                setupUrl="/setup"
                setupStepUrls={{}}
            />,
        );

        expect(
            screen.getByText('All required project configuration is complete.'),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole('link', {
                name: 'Open configuration step',
            }),
        ).not.toBeInTheDocument();
    });

    it('renders exact remediation and its server-provided step URL', () => {
        render(
            <ProjectReadinessPanel
                title="Project readiness"
                validation={{
                    complete: false,
                    missingConfiguration: [
                        {
                            key: 'repository.integration_branch',
                            step: 'repository',
                            message:
                                'The automated integration branch cannot be main.',
                            remediation:
                                'Change the integration branch to develop.',
                        },
                    ],
                }}
                setupUrl="/setup"
                setupStepUrls={{
                    repository: '/setup/repository',
                }}
            />,
        );

        expect(
            screen.getByText(
                'The automated integration branch cannot be main.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: 'Open configuration step',
            }),
        ).toHaveAttribute('href', '/setup/repository');
    });
});
