import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type ValidationCommandFormData = {
    build_command: string;
    test_command: string;
    lint_command: string;
    static_analysis_command: string;
    security_command: string;
};

export type ValidationCommandField = keyof ValidationCommandFormData;

type Props = {
    data: ValidationCommandFormData;
    errors: Partial<Record<ValidationCommandField, string>>;
    disabled: boolean;
    onChange: (field: ValidationCommandField, value: string) => void;
};

type FieldDefinition = {
    name: ValidationCommandField;
    label: string;
    description: string;
    placeholder: string;
};

const fields: readonly FieldDefinition[] = [
    {
        name: 'build_command',
        label: 'Build command',
        description:
            'Produces the application’s deployable frontend or server artifacts.',
        placeholder: 'pnpm build',
    },
    {
        name: 'test_command',
        label: 'Test command',
        description:
            'Runs the project’s automated backend and frontend test suites.',
        placeholder: 'composer test && pnpm test:unit',
    },
    {
        name: 'lint_command',
        label: 'Lint command',
        description: 'Checks code formatting, style, and linting requirements.',
        placeholder: 'composer lint:check && pnpm lint:check',
    },
    {
        name: 'static_analysis_command',
        label: 'Static-analysis command',
        description:
            'Runs type checking and static analysis without executing application behavior.',
        placeholder: 'composer types:check && pnpm types:check',
    },
    {
        name: 'security_command',
        label: 'Security command',
        description:
            'Runs dependency or source-code security checks configured by the project.',
        placeholder: 'composer audit && pnpm audit',
    },
];

/**
 * Render the five required project validation-command fields.
 *
 * The component collects configuration only. It never executes commands in the
 * browser or attempts to verify executables.
 */
export function ValidationCommandFields({
    data,
    errors,
    disabled,
    onChange,
}: Props) {
    return (
        <div className="space-y-6">
            <div className="space-y-2">
                <h2 className="text-lg font-semibold">Validation commands</h2>

                <p className="text-sm text-muted-foreground">
                    Configure the commands used to build and validate this
                    project. Enter one single-line command per field.
                </p>

                <p className="text-sm text-muted-foreground">
                    Do not paste tokens, passwords, or credentials into command
                    values. Reference environment variables or the project’s
                    integration credentials instead.
                </p>
            </div>

            {fields.map((field) => {
                const helpId = `${field.name}-help`;

                return (
                    <div key={field.name} className="grid gap-2">
                        <Label htmlFor={field.name}>{field.label}</Label>

                        <Textarea
                            id={field.name}
                            name={field.name}
                            value={data[field.name]}
                            rows={2}
                            disabled={disabled}
                            placeholder={field.placeholder}
                            autoComplete="off"
                            spellCheck={false}
                            aria-invalid={Boolean(errors[field.name])}
                            aria-describedby={helpId}
                            onChange={(event) =>
                                onChange(field.name, event.target.value)
                            }
                        />

                        <p
                            id={helpId}
                            className="text-sm text-muted-foreground"
                        >
                            {field.description}
                        </p>

                        <InputError message={errors[field.name]} />
                    </div>
                );
            })}
        </div>
    );
}
