<?php

declare(strict_types=1);

use App\Broadcasting\OrganizationEventStreamChannel;
use App\Broadcasting\ProjectEventStreamChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'organizations.{organizationId}.stream',
    OrganizationEventStreamChannel::class,
);

Broadcast::channel(
    'organizations.{organizationId}.projects.{projectId}.stream',
    ProjectEventStreamChannel::class,
);

Broadcast::channel(
    'App.Models.User.{id}',
    static fn (User $user, int|string $id): bool => $user->id === (int) $id,
);
