<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
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
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required|string'
    ]);

    // AUTH3-01 : authentification navigateur par session (Sanctum SPA).
    // Sanctum ne démarre une session que pour un domaine « stateful » ;
    // sans session, aucune connexion n'est possible (et aucun jeton n'est émis).
    if (! $request->hasSession()) {
        return response()->json(['message' => 'Session authentication required'], 403);
    }

    if (! Auth::guard('web')->attempt($credentials)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    // Nouvel identifiant de session après authentification (anti-fixation).
    $request->session()->regenerate();

    $user = Auth::guard('web')->user();

    // 🔥 AJOUTEZ CETTE LIGNE - Initialiser last_activity
    $user->last_activity = now();
    $user->save();

    return response()->json([
        'message' => 'Logged in',
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
    // Client non navigateur authentifié par jeton personnel : révoquer ce jeton.
    $token = $request->user()->currentAccessToken();
    if ($token instanceof PersonalAccessToken) {
        $token->delete();
    }

    // Navigateur (session Sanctum) : fermer la session et renouveler le jeton CSRF.
    if ($request->hasSession()) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    return response()->json(['message' => 'Logged out successfully']);
}

    // USER
    public function user(Request $request)
    {
        return $request->user();
    }
}
