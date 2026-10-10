<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::select('id', 'username', 'role', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'items' => $users,
            'total' => $users->count()
        ]);
    }

    public function store(Request $request)
    {
        $role = $request->input('role');
        if ($role === 'admin') {
            throw new BusinessException('VALIDATION_ERROR', 'No se puede crear un usuario con rol admin a través de la API.', 422, [
                ['field' => 'role', 'issue' => 'el rol admin no se puede otorgar por la API']
            ]);
        }

        if ($role !== 'librarian') {
            throw new BusinessException('VALIDATION_ERROR', 'El rol debe ser únicamente librarian.', 422, [
                ['field' => 'role', 'issue' => 'rol no válido']
            ]);
        }

        $request->validate([
            'username' => 'required|string|min:2',
            'password' => 'required|string|min:4',
        ]);

        $username = strtolower(trim($request->input('username')));

        if (User::where('username', $username)->exists()) {
            throw new BusinessException('USERNAME_TAKEN', "El nombre de usuario '{$username}' ya está en uso.", 409, [
                ['field' => 'username', 'issue' => 'nombre de usuario repetido']
            ]);
        }

        $user = User::create([
            'username' => $username,
            'password_hash' => Hash::make($request->input('password')),
            'role' => 'librarian',
        ]);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], 201);
    }
}
