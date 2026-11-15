<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        if (! Auth::guard('web')->attempt([...$request->validated(), 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'El correo o la contraseña no son correctos.']);
        }
        $request->session()->regenerate();

        return response()->json(['data' => $request->user()->only('id', 'name', 'email')]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $email = strtolower($data['email']);
        if (User::where('email', $email)->where('is_active', true)->exists()) {
            Password::sendResetLink(['email' => $email, 'is_active' => true]);
        }

        return response()->json(['message' => 'Si existe una cuenta activa, recibirás las instrucciones para recuperar el acceso.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);
        $status = Password::reset([...$data, 'is_active' => true], function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'El enlace no es válido o ha caducado.']);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
