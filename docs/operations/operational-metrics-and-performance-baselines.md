# Operational metrics and performance baselines

## Purpose

Operational metrics make workflow health, recovery needs, and dashboard
performance visible without exposing tenant data or provider secrets.

## Metrics

Monitor queue latency, retry counts, dead-letter count and age, expired leases,
workflow duration and failure rate, pending approvals, evidence completeness,
office-projection lag, and privacy-safe 3D rendering telemetry. Always scope
project-facing views to the current organization and project.

## Baselines

Configured server budgets cover the operations dashboard, operational metrics,
and office projection endpoints. The performance feature test samples each
authenticated endpoint and asserts query-count, 95th-percentile latency, and
payload-size budgets from `config/performance.php`.

## Review

Review budgets after a material query, projection, payload, or topology change.
Record the workload and approved rationale before increasing a budget.
