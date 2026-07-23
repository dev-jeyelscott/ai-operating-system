import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ValidationCommandFields } from './validation-command-fields';
import type { ValidationCommandFormData } from './validation-command-fields';

const data: ValidationCommandFormData = {
    build_command: '',
    test_command: '',
    lint_command: '',
    static_analysis_command: '',
    security_command: '',
};

describe('ValidationCommandFields', () => {
    it('renders all required validation command fields', () => {
        render(
            <ValidationCommandFields
                data={data}
                errors={{}}
                disabled={false}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByLabelText('Build command')).toBeInTheDocument();
        expect(screen.getByLabelText('Test command')).toBeInTheDocument();
        expect(screen.getByLabelText('Lint command')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Static-analysis command'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Security command')).toBeInTheDocument();
    });

    it('reports command changes to the parent form', () => {
        const onChange = vi.fn();

        render(
            <ValidationCommandFields
                data={data}
                errors={{}}
                disabled={false}
                onChange={onChange}
            />,
        );

        fireEvent.change(screen.getByLabelText('Build command'), {
            target: {
                value: 'pnpm build',
            },
        });

        expect(onChange).toHaveBeenCalledWith('build_command', 'pnpm build');
    });

    it('renders server validation errors', () => {
        render(
            <ValidationCommandFields
                data={data}
                errors={{
                    test_command: 'The test command is required.',
                }}
                disabled={false}
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByText('The test command is required.'),
        ).toBeInTheDocument();
    });
});
