import type { Dpr } from '@react-three/fiber';

export const OFFICE_QUALITY_PRESET_KEYS = ['low', 'balanced', 'high'] as const;

export type OfficeQualityPresetKey =
    (typeof OFFICE_QUALITY_PRESET_KEYS)[number];

export type OfficeLabelDensity = 'selected' | 'focused' | 'all';

export type OfficeQualityPreset = {
    key: OfficeQualityPresetKey;
    label: string;
    description: string;
    dpr: Dpr;
    antialias: boolean;
    shadows: boolean;
    shadowMapSize: number;
    showGrid: boolean;
    roomLabels: Extract<OfficeLabelDensity, 'selected' | 'all'>;
    agentLabels: Extract<OfficeLabelDensity, 'focused' | 'all'>;
    geometry: {
        capsuleCapSegments: number;
        capsuleRadialSegments: number;
        sphereWidthSegments: number;
        sphereHeightSegments: number;
        ringThetaSegments: number;
    };
};

export const DEFAULT_OFFICE_QUALITY_PRESET: OfficeQualityPresetKey = 'balanced';

export const OFFICE_QUALITY_PRESETS = {
    low: {
        key: 'low',
        label: 'Low',
        description:
            'Lowest rendering cost. Disables shadows and the decorative grid, reduces pixel density, and limits labels.',
        dpr: 1,
        antialias: false,
        shadows: false,
        shadowMapSize: 512,
        showGrid: false,
        roomLabels: 'selected',
        agentLabels: 'focused',
        geometry: {
            capsuleCapSegments: 2,
            capsuleRadialSegments: 6,
            sphereWidthSegments: 8,
            sphereHeightSegments: 6,
            ringThetaSegments: 12,
        },
    },
    balanced: {
        key: 'balanced',
        label: 'Balanced',
        description:
            'Recommended default. Preserves the current office appearance while limiting pixel density and shadow cost.',
        dpr: [1, 1.5],
        antialias: true,
        shadows: true,
        shadowMapSize: 1024,
        showGrid: true,
        roomLabels: 'all',
        agentLabels: 'all',
        geometry: {
            capsuleCapSegments: 4,
            capsuleRadialSegments: 8,
            sphereWidthSegments: 16,
            sphereHeightSegments: 12,
            ringThetaSegments: 24,
        },
    },
    high: {
        key: 'high',
        label: 'High',
        description:
            'Highest visual quality. Uses increased pixel density, larger shadow maps, and smoother procedural geometry.',
        dpr: [1.5, 2],
        antialias: true,
        shadows: true,
        shadowMapSize: 2048,
        showGrid: true,
        roomLabels: 'all',
        agentLabels: 'all',
        geometry: {
            capsuleCapSegments: 6,
            capsuleRadialSegments: 12,
            sphereWidthSegments: 24,
            sphereHeightSegments: 16,
            ringThetaSegments: 32,
        },
    },
} satisfies Record<OfficeQualityPresetKey, OfficeQualityPreset>;

/**
 * Return whether an unknown value is a supported office quality preset key.
 */
export function isOfficeQualityPresetKey(
    value: string,
): value is OfficeQualityPresetKey {
    return OFFICE_QUALITY_PRESET_KEYS.some((key) => key === value);
}

/**
 * Resolve one preset and fail safely to the balanced rendering configuration.
 */
export function officeQualityPreset(value: string): OfficeQualityPreset {
    if (!isOfficeQualityPresetKey(value)) {
        return OFFICE_QUALITY_PRESETS[DEFAULT_OFFICE_QUALITY_PRESET];
    }

    return OFFICE_QUALITY_PRESETS[value];
}
