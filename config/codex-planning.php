<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Production gate
    |--------------------------------------------------------------------------
    |
    | Keep false until provider event persistence, approval ingestion,
    | project-scoped credential materialization, immutable repository checkout,
    | and deterministic cleanup have all passed their contract suites.
    |
    */
    'enabled' => filter_var(
        env('CODEX_PLANNING_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),

    'assembler_version' => 'codex-planning-context.v1',
    'template_version' => 'codex-planning-instructions.v1',
    'manifest_schema_version' => 1,
    'redaction_version' => 'v1',

    'maximum_context_bytes' => (int) env(
        'CODEX_PLANNING_MAX_CONTEXT_BYTES',
        524_288,
    ),
    'maximum_estimated_tokens' => (int) env(
        'CODEX_PLANNING_MAX_ESTIMATED_TOKENS',
        174_763,
    ),
    'maximum_source_bytes' => (int) env(
        'CODEX_PLANNING_MAX_SOURCE_BYTES',
        131_072,
    ),
    'maximum_repository_instruction_files' => 128,
    'maximum_output_bytes' => 1_048_576,
    'maximum_output_json_depth' => 32,
    'maximum_output_list_items' => 2_000,
    'maximum_output_string_bytes' => 32_768,

    'required_document_classes' => [
        'specification',
        'roadmap',
    ],
];
