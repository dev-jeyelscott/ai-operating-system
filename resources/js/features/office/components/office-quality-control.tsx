import { useId } from 'react';
import { Button } from '@/components/ui/button';
import {
    OFFICE_QUALITY_PRESET_KEYS,
    officeQualityPreset,
} from '@/features/office/quality-presets';
import type { OfficeQualityPresetKey } from '@/features/office/quality-presets';

type Props = {
    value: OfficeQualityPresetKey;
    disabled?: boolean;
    onChange: (value: OfficeQualityPresetKey) => void;
};

/**
 * Render keyboard-accessible controls for the presentation-only quality level.
 */
export function OfficeQualityControl({
    value,
    disabled = false,
    onChange,
}: Props) {
    const descriptionId = useId();
    const currentPreset = officeQualityPreset(value);

    return (
        <fieldset className="space-y-2" disabled={disabled}>
            <legend className="text-sm font-medium">Rendering quality</legend>

            <div
                role="group"
                aria-label="Rendering quality presets"
                className="flex flex-wrap gap-2"
            >
                {OFFICE_QUALITY_PRESET_KEYS.map((presetKey) => {
                    const preset = officeQualityPreset(presetKey);
                    const selected = value === presetKey;

                    return (
                        <Button
                            key={presetKey}
                            type="button"
                            size="sm"
                            variant={selected ? 'default' : 'outline'}
                            aria-pressed={selected}
                            aria-describedby={descriptionId}
                            disabled={disabled}
                            onClick={() => onChange(presetKey)}
                        >
                            {preset.label}
                        </Button>
                    );
                })}
            </div>

            <p
                id={descriptionId}
                aria-live="polite"
                className="max-w-2xl text-xs text-muted-foreground"
            >
                {currentPreset.description}
            </p>
        </fieldset>
    );
}
