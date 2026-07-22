<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AvatarUpdateRequest;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

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
     * Upload or replace the authenticated user's profile photo.
     */
    public function updateAvatar(AvatarUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $previousAvatarPath = $user->avatar_path;
        $avatar = $request->file('avatar');

        if (! $avatar instanceof UploadedFile) {
            throw new RuntimeException('The profile photo upload is missing.');
        }

        $newAvatarPath = $avatar->store('avatars', 'public');

        if ($newAvatarPath === false) {
            throw new RuntimeException('The profile photo could not be stored.');
        }

        try {
            $user->avatar_path = $newAvatarPath;
            $user->save();
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newAvatarPath);

            throw $exception;
        }

        if (
            $previousAvatarPath !== null
            && $previousAvatarPath !== $newAvatarPath
        ) {
            Storage::disk('public')->delete($previousAvatarPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Remove the authenticated user's profile photo.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $avatarPath = $user->avatar_path;

        if ($avatarPath !== null) {
            $user->avatar_path = null;
            $user->save();

            Storage::disk('public')->delete($avatarPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo removed.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();
        $avatarPath = $user->avatar_path;

        Auth::logout();

        $user->delete();

        if ($avatarPath !== null) {
            Storage::disk('public')->delete($avatarPath);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
