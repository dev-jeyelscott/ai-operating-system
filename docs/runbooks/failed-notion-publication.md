# Failed Notion publication

## Purpose

Recover a failed Notion ticket publication without creating duplicate pages.

## Trigger conditions and severity guidance

Use for failed, conflicted, rate-limited, or circuit-open publication. Treat an approved-scope conflict or unknown page ownership as high severity.

## Required authorization and safety constraints

An authorized integration operator must act. Do not delete successfully created Notion pages or expose credentials, and do not mark external state verified without reconciliation.

## Preconditions and diagnosis

Capture organization, project, roadmap, task, execution, request, and correlation IDs. Distinguish provider outage from one task failure; inspect circuit state, rate-limit response, approved database, required properties, and existing external mappings.

## Containment and recovery procedure

Stop unnecessary retries. Correct credential, schema, connectivity, or provider fault; retry only failed tasks through the application; then run the supported reconciliation flow. Preserve all created pages and stable external keys.

## Verification

Confirm one stable key maps to one Notion page, no duplicate was created, and record created, updated, skipped, failed, and conflicted counts.

## Rollback or abort conditions

Abort if the database is not approved, properties are incompatible, page ownership is unknown, or a conflict affects approved scope.

## Evidence to retain, escalation, and follow-up actions

Retain identifiers, safe provider category, mapping result, and operator decision. Escalate unresolved provider or scope conflicts; record corrective work before resuming normal publication.
