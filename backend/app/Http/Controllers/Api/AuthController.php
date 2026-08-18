<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Грешен имейл или парола.'],
            ]);
        }

        $plain = Str::random(64);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'name' => 'spa',
            'expires_at' => now()->addDays(60),
        ]);

        return response()->json([
            'token' => $plain,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->attributes->get('apiToken');
        if ($token instanceof ApiToken) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'name' => $user?->name,
            'email' => $user?->email,
        ]);
    }
}
