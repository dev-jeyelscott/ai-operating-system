<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Application\Notifications\MarkNotificationAsRead;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles an authenticated recipient's notification read acknowledgement.
 */
final class MarkNotificationAsReadController extends Controller
{
    /**
     * Mark the scoped notification as read and return to the current page.
     */
    public function __invoke(
        Request $request,
        Organization $organization,
        string $notificationRecipient,
        MarkNotificationAsRead $markNotificationAsRead,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $markNotificationAsRead->handle(
            organizationId: $organization->id,
            recipientUserId: $user->id,
            notificationRecipientId: $notificationRecipient,
        );

        /*
         * Inertia follows this redirect and reloads only the notification prop
         * requested by the frontend visit.
         */
        return redirect()->back(Response::HTTP_SEE_OTHER);
    }
}
