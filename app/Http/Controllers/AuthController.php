<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return response()->json($user);
    }

    // LOGIN


    public function login(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required'
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'message' => 'Logged in',
        'token' => $token,
        'user' => $user
    ]);
}



    // LOGOUT
    // public function logout(Request $request)
    // {
    //     Auth::logout();
    //     return response()->json(['message' => 'Logged out']);
    // }
    public function logout(Request $request)
{
    // Supprimer le token actuel de l'utilisateur
    $request->user()->currentAccessToken()->delete();

    return response()->json(['message' => 'Logged out successfully']);
}

    // USER
    public function user(Request $request)
    {
        return $request->user();
    }
}
