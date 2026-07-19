<?php

declare(strict_types=1);

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
use Illuminate\Validation\ValidationException;
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
    public function update(
        ProfileUpdateRequest $request,
    ): RedirectResponse {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Profile updated.'),
        ]);

        return to_route('profile.edit');
    }

    /**
     * Delete the authenticated user's account.
     */
    public function destroy(
        ProfileDeleteRequest $request,
        DeleteUserAccount $deleteUserAccount,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $correlationId = $request->attributes->get(
            'request_id',
        );

        try {
            $deleteUserAccount->handle(
                actorUserId: $user->id,
                correlationId: is_string($correlationId)
                    ? $correlationId
                    : null,
            );
        } catch (AccountDeletionBlocked $exception) {
            throw ValidationException::withMessages([
                'account' => $exception->getMessage(),
            ]);
        }

        /*
        * The account has already been deleted. Clear the token only on the
        * in-memory model so Laravel does not try to rotate and persist a new
        * remember token during logout.
        */
        $user->setRememberToken('');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
