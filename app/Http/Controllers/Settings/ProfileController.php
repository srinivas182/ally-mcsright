<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\AuthenticatedUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\EmailChange;

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
        $user = AuthenticatedUser::from($request);
        $data = $request->validated();
        $newEmail = $data['email'] ?? null;
        unset($data['email']);
        $user->fill($data)->save();

        // A new email only takes effect once confirmed from that address (4A-2).
        $message = __('Profile updated.');
        if (is_string($newEmail) && mb_strtolower($newEmail) !== mb_strtolower($user->email)) {
            app(EmailChange::class)->request($user, $newEmail);
            $message = 'Profile updated. Check '.$newEmail.' to confirm your new email.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
}
