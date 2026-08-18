<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (! is_string($plain) || $plain === '') {
            return response()->json(['message' => 'Неоторизиран достъп.'], 401);
        }

        $token = ApiToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if (! $token?->user) {
            return response()->json(['message' => 'Неоторизиран достъп.'], 401);
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            $token->delete();

            return response()->json(['message' => 'Сесията е изтекла. Влезте отново.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('apiToken', $token);

        return $next($request);
    }
}
