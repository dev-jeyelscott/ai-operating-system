<?php

declare(strict_types=1);

use App\Domain\Integrations\NotionDatabaseId;

test('a dashed Notion database UUID is accepted', function (): void {
    $databaseId = NotionDatabaseId::from(
        'd9824bdc-8445-4327-be8b-5b47500af6ce',
    );

    expect($databaseId->value())
        ->toBe('d9824bdc-8445-4327-be8b-5b47500af6ce');
});

test('a compact Notion database UUID is normalized', function (): void {
    $databaseId = NotionDatabaseId::from(
        'd9824bdc84454327be8b5b47500af6ce',
    );

    expect($databaseId->value())
        ->toBe('d9824bdc-8445-4327-be8b-5b47500af6ce');
});

test('a Notion database URL is normalized', function (): void {
    $databaseId = NotionDatabaseId::from(
        'https://www.notion.so/Project-Tickets-d9824bdc84454327be8b5b47500af6ce?v=123',
    );

    expect($databaseId->value())
        ->toBe('d9824bdc-8445-4327-be8b-5b47500af6ce');
});

test('an arbitrary external URL is rejected', function (): void {
    expect(
        fn (): NotionDatabaseId => NotionDatabaseId::from(
            'https://example.com/d9824bdc84454327be8b5b47500af6ce',
        ),
    )->toThrow(InvalidArgumentException::class);
});

test('a malformed identifier is rejected', function (): void {
    expect(
        fn (): NotionDatabaseId => NotionDatabaseId::from(
            'not-a-database-id',
        ),
    )->toThrow(InvalidArgumentException::class);
});
