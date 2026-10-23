<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index()
    {
        return response()->json([
            'users' => User::query()
                ->orderBy('id')
                ->get(['id', 'name', 'email', 'profile_picture_path', 'created_at']),
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
            'user' => $user->refresh(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'profile_picture' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=2048,max_height=2048'],
        ]);

        if ($request->hasFile('profile_picture')) {
            $newPath = $request->file('profile_picture')->store("profile-pictures/{$user->id}", 'public');
            if (! $newPath) {
                throw new \RuntimeException('The profile picture could not be saved.');
            }
        }

        $oldPath = $user->profile_picture_path;
        $user->name = $validated['name'];
        if (isset($newPath)) {
            $user->profile_picture_path = $newPath;
        }
        $user->save();

        if (isset($newPath) && $oldPath && Storage::disk('public')->exists($oldPath) && ! Storage::disk('public')->delete($oldPath)) {
            throw new \RuntimeException('The previous profile picture could not be removed.');
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user->refresh(),
        ]);
    }

    public function removeProfilePicture(Request $request)
    {
        $user = $request->user();
        $oldPath = $user->profile_picture_path;

        if ($oldPath && Storage::disk('public')->exists($oldPath) && ! Storage::disk('public')->delete($oldPath)) {
            throw new \RuntimeException('The profile picture could not be removed.');
        }

        $user->profile_picture_path = null;
        $user->save();

        return response()->json([
            'message' => 'Profile picture removed.',
            'user' => $user->refresh(),
        ]);
    }
}
