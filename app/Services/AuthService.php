<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthService
{
    private string $jwtSecret;
    private int $expiresIn = 28800; // 8 hours in seconds

    public function __construct()
    {
        $this->jwtSecret = env('JWT_SECRET', 'c2VjcmV0LWtleS1tYW5hZ2VyLWxpYnJhcnktYWRzby0zNDEzOTc0LXNlbmEtMjAyNg==');
    }

    /**
     * Ensure the initial admin user exists from environment variables.
     */
    public function ensureAdminExists(): ?User
    {
        $adminUsername = env('ADMIN_USERNAME', 'admin');
        $adminPassword = env('ADMIN_PASSWORD', 'admin1234');

        if (empty($adminUsername) || empty($adminPassword)) {
            return null;
        }

        $normalizedUsername = strtolower(trim($adminUsername));
        $admin = User::where('username', $normalizedUsername)->first();

        if (!$admin) {
            $admin = User::create([
                'username' => $normalizedUsername,
                'password_hash' => Hash::make($adminPassword),
                'role' => 'admin',
            ]);
        }

        return $admin;
    }

    /**
     * Authenticate user credentials and return JWT token response.
     * Complies with CA-01.2: never reveals whether username or password was wrong.
     */
    public function login(string $username, string $password): array
    {
        $this->ensureAdminExists();

        $normalizedUsername = strtolower(trim($username));
        $user = User::where('username', $normalizedUsername)->first();

        if (!$user || !Hash::check($password, $user->password_hash)) {
            throw new BusinessException('INVALID_CREDENTIALS', 'Credenciales inválidas.', 401);
        }

        return $this->createTokenResponse($user);
    }

    public function createTokenResponse(User $user): array
    {
        $now = time();
        $payload = [
            'iss' => env('APP_URL', 'http://localhost'),
            'sub' => (string) $user->id,
            'role' => $user->role,
            'iat' => $now,
            'exp' => $now + $this->expiresIn,
        ];

        $token = JWT::encode($payload, $this->jwtSecret, 'HS256');

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
            'role' => $user->role,
        ];
    }

    /**
     * Decode and validate token.
     */
    public function verifyToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        } catch (Throwable $e) {
            throw new BusinessException('INVALID_CREDENTIALS', 'Token inválido o expirado.', 401);
        }
    }
}
