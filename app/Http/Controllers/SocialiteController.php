<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Models\User;
use App\Models\Usr;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

class SocialiteController extends Controller
{
    /** Set while the work order mailbox account is connecting its Gmail (see redirectToGoogleMailbox). */
    private const MAILBOX_CONNECT_SESSION_KEY = 'google_mailbox_connect';

    /**
     * Normal sign-in: only the name, email and photo (Socialite's default openid, profile and email scopes), so
     * Google asks for consent once and does not show its permission screens on every login.
     */
    public function redirectToGoogle()
    {
        session()->forget(self::MAILBOX_CONNECT_SESSION_KEY);

        /** @var GoogleProvider $googleProvider */
        $googleProvider = Socialite::driver('google');

        return $googleProvider->redirect();
    }

    /**
     * One-time link for the work order mailbox account (WORK_ORDER_GMAIL_ACCOUNT): signs in and grants EMS offline
     * access to its Gmail, so work-orders:sync-google-replies can read the replies. Only that account's token is kept.
     */
    public function redirectToGoogleMailbox()
    {
        session()->put(self::MAILBOX_CONNECT_SESSION_KEY, true);

        /** @var GoogleProvider $googleProvider */
        $googleProvider = Socialite::driver('google');

        return $googleProvider
            ->scopes(config('work_orders.google_scopes'))
            ->with(array_filter([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
                'login_hint' => config('work_orders.gmail_account_email'),
            ]))
            ->redirect();
    }

    // public function handleGoogleCallback()
    // {
    //     $key = 'google-login-attempt:' . request()->ip();

    //     // 1. Check if they are locked out
    //     if (RateLimiter::tooManyAttempts($key, 3)) {
    //         $seconds = RateLimiter::availableIn($key);

    //         Notification::make()
    //             ->title('Locked Out')
    //             ->body("Too many failed attempts. Please wait {$seconds}s.")
    //             ->danger()
    //             ->persistent()
    //             ->send();

    //         return redirect('/login');
    //     }

    //     try {
    //         $googleUser = Socialite::driver('google')->user();
    //         $email = $googleUser->getEmail();

    //         // Step 1: Verify if user exists in Usr model
    //         $usrUser = Usr::where('email', $email)->first();

    //         if (!$usrUser) {
    //             $currentAttempts = RateLimiter::hit($key, 60);

    //             if (RateLimiter::attempts($key) >= 3) {
    //                 RateLimiter::clear($key);
    //                 RateLimiter::hit($key, 60);
    //                 RateLimiter::hit($key, 60);
    //                 RateLimiter::hit($key, 60);

    //                 Notification::make()
    //                     ->title('Too many failures')
    //                     ->body('You are now locked out for a full 60 seconds.')
    //                     ->danger()
    //                     ->send();
    //             } else {
    //                 Notification::make()
    //                     ->title('Invalid Login')
    //                     ->body('User not found. Attempts remaining: ' . (3 - RateLimiter::attempts($key)))
    //                     ->warning()
    //                     ->send();
    //             }
    //             return redirect('/login');
    //         }

    //         // Step 2: Check if email exists in AppUser
    //         $appUser = AppUser::where('user_email', $email)->first();

    //         if (!$appUser) {
    //             $currentAttempts = RateLimiter::hit($key, 60);

    //             if (RateLimiter::attempts($key) >= 3) {
    //                 RateLimiter::clear($key);
    //                 RateLimiter::hit($key, 60);
    //                 RateLimiter::hit($key, 60);
    //                 RateLimiter::hit($key, 60);

    //                 Notification::make()
    //                     ->title('Too many failures')
    //                     ->body('You are now locked out for a full 60 seconds.')
    //                     ->danger()
    //                     ->send();
    //             } else {
    //                 Notification::make()
    //                     ->title('Invalid Login')
    //                     ->body('User account not found. Attempts remaining: ' . (3 - RateLimiter::attempts($key)))
    //                     ->warning()
    //                     ->send();
    //             }
    //             return redirect('/login');
    //         }

    //         RateLimiter::clear($key);
    //         Auth::login($appUser);
    //         Filament::auth()->login($appUser);

    //         // Regenerate CSRF token only (not full session)
    //         request()->session()->regenerateToken();

    //         Notification::make()
    //             ->title('Welcome back!')
    //             ->body("Hello {$appUser->getFilamentName()}")
    //             ->success()
    //             ->send();

    //         // return redirect(Filament::getPanel('admin')->getUrl());
    //         return redirect()->intended(Filament::getPanel('admin')->getUrl());
    //         // return redirect('/login');
    //     } catch (\Exception $e) {
    //         Log::error('Google login failed: ' . $e->getMessage());
    //         RateLimiter::hit($key, 60);
    //         Notification::make()
    //             ->title('Error')
    //             ->body('Something went wrong during Google sign-in.')
    //             ->danger()
    //             ->send();

    //         return redirect('/login')->withErrors([
    //             'error' => 'Failed to authenticate with Google.'
    //         ]);
    //     }
    // }

    public function handleGoogleCallback()
    {
        $key = 'google-login-attempt:'.request()->ip();
        $maxAttempts = 3;
        $decaySeconds = 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            Notification::make()
                ->title('Locked Out')
                ->body("Too many failed attempts. Please wait {$seconds}s.")
                ->danger()
                ->persistent()
                ->send();

            return redirect('/login');
        }

        $connectingMailbox = (bool) session()->pull(self::MAILBOX_CONNECT_SESSION_KEY, false);

        try {
            $googleUser = Socialite::driver('google')->user();
            $email = $googleUser->getEmail();

            $usrUser = Usr::query()->where('email', $email)->first();
            $appUser = AppUser::query()->where('user_email', $email)->first();

            if (! $usrUser || ! $appUser) {
                RateLimiter::hit($key, $decaySeconds);

                $remaining = $maxAttempts - RateLimiter::attempts($key);
                $lockedOut = RateLimiter::tooManyAttempts($key, $maxAttempts);

                Notification::make()
                    ->title($lockedOut ? 'Too Many Failures' : 'Invalid Login')
                    ->body(
                        $lockedOut
                            ? "You are now locked out for {$decaySeconds} seconds."
                            : "User not found. Attempts remaining: {$remaining}"
                    )
                    ->when($lockedOut, fn ($n) => $n->danger())
                    ->when(! $lockedOut, fn ($n) => $n->warning())
                    ->send();

                return redirect('/login');
            }

            // Check if user is active in Usr model
            if ($usrUser->isActive != 1 || ! $appUser->is_active) {
                Notification::make()
                    ->title('Account Inactive')
                    ->body('Your account is currently inactive. Please contact your administrator.')
                    ->danger()
                    ->send();

                return redirect('/login');
            }

            // Gmail tokens are kept only for the work order mailbox, and only when it signed in through the mailbox
            // link. A normal sign-in carries no Gmail access, so it must not overwrite the mailbox's working token.
            $mailboxEmail = strtolower(trim((string) config('work_orders.gmail_account_email')));
            $mailboxConnected = $connectingMailbox && $mailboxEmail !== '' && strtolower((string) $email) === $mailboxEmail;

            if ($mailboxConnected) {
                $tokenData = [
                    'google_access_token' => $googleUser->token,
                    'google_token_expires_at' => $googleUser->expiresIn
                        ? now()->addSeconds((int) $googleUser->expiresIn)
                        : null,
                ];

                if ($googleUser->refreshToken) {
                    $tokenData['google_refresh_token'] = $googleUser->refreshToken;
                }

                $appUser->forceFill($tokenData)->save();
            }

            // Save/update avatar from Google
            if ($googleUser->getAvatar() && $appUser->user_avatar !== $googleUser->getAvatar()) {
                $appUser->update([
                    'user_avatar' => $googleUser->getAvatar(),
                ]);
            }

            RateLimiter::clear($key);

            // Single login via Filament (handles Auth guard internally)
            Filament::auth()->login($appUser);

            // Full session regeneration to prevent session fixation
            request()->session()->regenerate();

            Notification::make()
                ->title('Welcome back!')
                ->body("Hello {$appUser->getFilamentName()}")
                ->success()
                ->send();

            if ($mailboxConnected) {
                Notification::make()
                    ->title('Gmail connected')
                    ->body('EMS can now read the work order replies in this mailbox.')
                    ->success()
                    ->send();
            } elseif ($connectingMailbox) {
                Notification::make()
                    ->title('Gmail was not connected')
                    ->body('Only the work order mailbox account ('.($mailboxEmail ?: 'not set').') can connect its Gmail.')
                    ->warning()
                    ->persistent()
                    ->send();
            }

            return redirect()->intended(Filament::getPanel('admin')->getUrl());
        } catch (\Exception $e) {
            Log::error('Google login failed', [
                'message' => $e->getMessage(),
                'ip' => request()->ip(),
            ]);

            RateLimiter::hit($key, $decaySeconds);

            Notification::make()
                ->title('Error')
                ->body('Something went wrong during Google sign-in.')
                ->danger()
                ->send();

            return redirect('/login');
        }
    }
}
