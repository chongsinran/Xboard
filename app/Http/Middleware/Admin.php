<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Closure;
use App\Models\User;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        /** @var User|null $user */
        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            $authorization = (string) ($request->input('auth_data') ?? $request->header('authorization') ?? '');
            $authorization = trim($authorization);

            if ($authorization !== '') {
                if (stripos($authorization, 'Bearer ') !== 0) {
                    $authorization = 'Bearer ' . $authorization;
                }

                $user = AuthService::findUserByBearerToken($authorization);
            }
        }
        
        if (!$user || !$user->is_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return $next($request);
    }
}
