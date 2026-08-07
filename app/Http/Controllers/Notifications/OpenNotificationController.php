<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Application\Notifications\OpenNotification;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens one actionable notification for its authenticated recipient.
 */
final class OpenNotificationController extends Controller
{
    /**
     * Mark the notification read and redirect to its trusted application route.
     */
    public function __invoke(
        Request $request,
        Organization $organization,
        string $notificationRecipient,
        OpenNotification $openNotification,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless(
            $user instanceof User,
            Response::HTTP_UNAUTHORIZED,
        );

        $destination = $openNotification->handle(
            organizationId: $organization->id,
            recipientUserId: $user->id,
            notificationRecipientId: $notificationRecipient,
        );

        /*
         * A 303 response prevents the browser from replaying the POST against
         * the destination URL.
         */
        return redirect()->to(
            $destination,
            Response::HTTP_SEE_OTHER,
        );
    }
}
