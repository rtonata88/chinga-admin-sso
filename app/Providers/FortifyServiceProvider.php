<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\Auth\AccountLockoutService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perHour(20)->by($request->input(Fortify::username())),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perHour(3)->by($request->input('email'));
        });
    }

    /**
     * Configure custom authentication logic.
     */
    private function configureAuthentication(): void
    {
        // Custom authentication callback with lockout check and tenant scoping
        Fortify::authenticateUsing(function (Request $request) {
            $lockoutService = app(AccountLockoutService::class);
            $tenantId = app('current_tenant')?->id;

            $input = $request->input(Fortify::username());
            $user = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('email', $input)->orWhere('username', $input))
                ->first();

            if (!$user) {
                $lockoutService->recordFailedAttempt(
                    $request->input(Fortify::username()),
                    $request->ip(),
                    $request->userAgent(),
                    'user_not_found'
                );
                return null;
            }

            // Check if account is locked
            if ($lockoutService->isLockedOut($user)) {
                $remainingSeconds = $lockoutService->getRemainingLockoutTime($user);
                $remainingMinutes = ceil($remainingSeconds / 60);

                throw ValidationException::withMessages([
                    Fortify::username() => [
                        "Your account has been locked due to too many failed login attempts. Please try again in {$remainingMinutes} minutes.",
                    ],
                ]);
            }

            // Check if account is active
            if (!$user->isActive()) {
                throw ValidationException::withMessages([
                    Fortify::username() => [
                        'Your account has been suspended. Please contact support.',
                    ],
                ]);
            }

            // Validate password
            if (!Hash::check($request->input('password'), $user->password)) {
                $lockoutService->recordFailedAttempt(
                    $request->input(Fortify::username()),
                    $request->ip(),
                    $request->userAgent(),
                    'invalid_password'
                );
                return null;
            }

            // Successful authentication - record it
            $lockoutService->recordSuccessfulLogin($user, $request->ip(), $request->userAgent());

            return $user;
        });
    }

}
