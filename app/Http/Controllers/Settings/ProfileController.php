<?php

namespace App\Http\Controllers\Settings;

use App\Application\Identity\DeleteUserAccount;
use App\Application\Identity\Exceptions\AccountDeletionBlocked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the authenticated account while preserving organization invariants.
     */
    public function destroy(
        ProfileDeleteRequest $request,
        DeleteUserAccount $deleteUserAccount,
    ): RedirectResponse {
        $user = $request->user();

        /*
        * The route is authenticated, but explicitly narrow the model type for
        * static analysis and protect the action from invalid invocation.
        */
        if (! $user instanceof User) {
            abort(401);
        }

        $correlationId = $request->attributes->get('request_id');

        try {
            /*
            * Perform ownership validation, audit recording, membership cleanup,
            * and user deletion inside one database transaction.
            */
            $deleteUserAccount->handle(
                actorUserId: $user->id,
                correlationId: is_string($correlationId)
                    ? $correlationId
                    : null,
            );
        } catch (AccountDeletionBlocked $exception) {
            /*
            * Business-rule failures are safe user-facing validation errors.
            * Authentication and session state remain unchanged.
            */
            return to_route('profile.edit')->withErrors([
                'account' => $exception->getMessage(),
            ]);
        }

        /*
        * Authentication must only be destroyed after the database transaction
        * successfully commits. Failed infrastructure operations must preserve
        * the authenticated session.
        */
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('home');
    }
}
