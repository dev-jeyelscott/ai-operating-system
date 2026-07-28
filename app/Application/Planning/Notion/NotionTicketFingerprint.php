<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

/** Normalizes the tracked Notion projection before drift comparisons. */
final class NotionTicketFingerprint
{
    /** @param array<string, mixed> $properties */
    public function from(array $properties, string $body): string
    {
        $normalized = [];
        foreach (['Ticket ID', 'Dependencies', 'Evidence Requirements', 'Source References'] as $name) {
            $normalized[$name] = $this->richText($properties[$name]['rich_text'] ?? []);
        }
        $normalized['Name'] = $this->richText($properties['Name']['title'] ?? []);
        foreach (['Status', 'Type', 'Priority', 'Risk'] as $name) {
            $type = $name === 'Status' ? 'status' : 'select';
            $value = $properties[$name][$type] ?? null;
            $normalized[$name] = is_array($value) ? ($value['name'] ?? null) : $value;
        }
        $normalized['Complexity'] = $properties['Complexity']['number'] ?? null;
        $normalized['Requires Approval'] = $properties['Requires Approval']['checkbox'] ?? null;
        ksort($normalized);

        return hash('sha256', json_encode(['properties' => $normalized, 'body' => trim(str_replace("\r\n", "\n", $body))], JSON_THROW_ON_ERROR));
    }

    private function richText(mixed $fragments): string
    {
        if (! is_array($fragments)) {
            return '';
        }

        return collect($fragments)->map(fn (mixed $fragment): string => is_array($fragment) ? (string) ($fragment['plain_text'] ?? $fragment['text']['content'] ?? '') : '')->implode('');
    }
}
