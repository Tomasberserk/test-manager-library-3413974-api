<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;

class JwtAuthMiddleware
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function handle(Request $request, Closure $next, ...$roles)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            throw new BusinessException('INVALID_CREDENTIALS', 'Se requiere token de autenticación.', 401);
        }

        $token = $matches[1];
        $payload = $this->authService->verifyToken($token);

        $user = User::find($payload->sub);
        if (!$user) {
            throw new BusinessException('INVALID_CREDENTIALS', 'Usuario no encontrado o token inválido.', 401);
        }

        // Check roles if specified
        if (!empty($roles)) {
            $allowedRoles = array_map('trim', explode(',', implode(',', $roles)));
            if (!in_array($user->role, $allowedRoles, true)) {
                throw new BusinessException('FORBIDDEN_ROLE', 'Rol insuficiente para realizar esta acción.', 403);
            }
        }

        $request->setUserResolver(fn() => $user);
        $request->attributes->set('auth_user', $user);

        return $next($request);
    }
}
