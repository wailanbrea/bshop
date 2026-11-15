<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AcceptInvitation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class BusinessInvitationController extends Controller
{
    public function show(Request $request, AcceptInvitation $service)
    {
        return response()->json(['data' => $service->inspect($request->route('token'))])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, AcceptInvitation $service)
    {
        $user = Auth::guard('web')->user();
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:190'], 'name' => [Rule::requiredIf(! $user), 'nullable', 'string', 'max:160'], 'password' => [Rule::requiredIf(! $user), 'nullable', 'confirmed', Password::min(12)->letters()->numbers()]]);
        $result = $service->accept($request->route('token'), $data, $user);
        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['business' => $result['business']]])->header('Cache-Control', 'no-store');
    }
}
