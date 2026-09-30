<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Реєстрація нового користувача
     */
    public function register(Request $request)
    {
        if (! config('features.registration_enabled')) {
            return response()->json([
                'message' => 'Реєстрація тимчасово вимкнена.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
        ]);

        // Everyone starts with a workspace of their own, so there is always
        // somewhere to put the first site.
        $user->defaultWorkspace();

        // Створення API токену
        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Вхід користувача
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Невірні облікові дані.',
            ], 401);
        }

        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 200);
    }

    /**
     * Вихід користувача
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json(['message' => 'Ви успішно вийшли.'], 200);
        } catch (\Exception $e) {
            Log::error('Error during logout: '.$e->getMessage());

            return response()->json(['message' => 'Помилка при виході.'], 500);
        }
    }

    /**
     * Перевірка автентифікації
     */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }
}
