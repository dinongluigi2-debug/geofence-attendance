<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

class PasswordResetLinkController extends Controller
{
    public function store(SendPasswordResetLinkRequest $request): Responsable|RedirectResponse
    {
        try {
            if (config('fortify.lowercase_usernames') && $request->has(Fortify::email())) {
                $request->merge([
                    Fortify::email() => Str::lower($request->{Fortify::email()}),
                ]);
            }

            $status = Password::broker(config('fortify.passwords'))
                ->sendResetLink($request->only(Fortify::email()));

            return $status === Password::RESET_LINK_SENT
                ? app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status])
                : app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'email' => 'Password reset email is temporarily unavailable. Please try again later or contact support.',
            ]);
        }
    }
}
