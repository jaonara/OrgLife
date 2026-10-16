<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json([
            'users' => User::query()
                ->orderBy('id')
                ->get(['id', 'name', 'email', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user->only(['id', 'name', 'email', 'created_at']),
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->is($user), 403, 'You may only update your own account.');

        $required = $request->isMethod('put') ? 'required' : 'sometimes';
        $validated = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'unique:users,email,'.$user->id],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
        ]);

        if (array_key_exists('password', $validated)) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user->only(['id', 'name', 'email', 'created_at']),
        ]);
    }
}
