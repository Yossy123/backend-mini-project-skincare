<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class PasswordController extends Controller
{
    /**
     * Email a password reset link. The reply never reveals whether the email is registered.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            // A mail outage must not turn into an error that exposes which emails exist.
            report($exception);
        }

        return response()->json([
            'message' => 'Jika email terdaftar, tautan untuk mengatur ulang password sudah dikirim. Periksa kotak masuk dan folder spam.',
        ], 200);
    }

    /**
     * Set a new password using the emailed token and sign the account out everywhere.
     *
     * @throws ValidationException
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Tautan reset password tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.'],
            ]);
        }

        return response()->json([
            'message' => 'Password berhasil diatur ulang. Silakan login dengan password baru.',
        ], 200);
    }

    /**
     * Change the signed-in user's password and sign every other device out.
     *
     * @throws ValidationException
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            // A validation error (422), not 401, so the client does not mistake it for an expired session.
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak sesuai.'],
            ]);
        }

        $user->forceFill(['password' => $request->input('password')])->save();

        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken instanceof PersonalAccessToken, fn ($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();

        return response()->json([
            'message' => 'Password berhasil diubah. Perangkat lain telah dikeluarkan dari akun ini.',
        ], 200);
    }
}
