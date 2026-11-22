<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Services\Client\ClientAccessGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public const EMAIL_UNVERIFIED_MESSAGE = 'Veuillez vérifier votre adresse email avant de vous connecter.';

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

        // AUTH3-04 / REG-MAIL-ASYNC : Registered met l'email de vérification en file
        // « database » (QueueEmailVerification → SendVerificationEmailJob) ; aucun
        // SMTP dans la requête. Le compte est déjà créé : un échec de mise en file est
        // signalé côté serveur mais ne transforme pas l'inscription en erreur (pas
        // d'envoi de secours ; l'utilisateur peut redemander l'email ; un nouvel essai
        // d'inscription échouerait sur « email déjà pris »).
        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            report($e);
        }

        // Contrat inchangé : 200 avec l'utilisateur créé, sans connexion ni jeton.
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

    // AUTH3-04 : le contrôle d'email vérifié n'intervient qu'une fois le mot de
    // passe prouvé (callback appelé uniquement pour des identifiants valides),
    // et AVANT l'ouverture de session : un compte non vérifié n'est jamais connecté.
    $unverified = false;
    $authenticated = Auth::guard('web')->attemptWhen($credentials, function (User $user) use (&$unverified) {
        // BE-03 (A1, Q5) : compte suspendu ⇒ même 401 générique qu'un mauvais
        // mot de passe, testé AVANT la vérification d'email pour ne révéler
        // ni le statut ni l'état de vérification.
        if (! $user->isAdministrativelyActive()) {
            return false;
        }

        $unverified = ! $user->hasVerifiedEmail();

        return ! $unverified;
    });

    if ($unverified) {
        return response()->json([
            'message' => self::EMAIL_UNVERIFIED_MESSAGE,
            'code' => 'EMAIL_UNVERIFIED',
        ], 403);
    }

    if (! $authenticated) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    // Nouvel identifiant de session après authentification (anti-fixation).
    $request->session()->regenerate();

    $user = Auth::guard('web')->user();

    // BE-03 (Q2, A4) : décision finale sous le verrou de la ligne users, dans
    // le même ordre que la transition admin. Une suspension validée entre le
    // contrôle du mot de passe et ce point annule le login (401 générique) ;
    // sinon la session est liée à la version d'accès relue sous verrou.
    $version = DB::transaction(function () use ($user) {
        $row = DB::table('users')->where('id', $user->getKey())->lockForUpdate()
            ->first(['administrative_status', 'auth_version']);
        if ($row === null || $row->administrative_status !== User::STATUS_ACTIVE) {
            return null;
        }

        // 🔥 AJOUTEZ CETTE LIGNE - Initialiser last_activity
        $user->last_activity = now();
        $user->save();

        return (int) $row->auth_version;
    });

    if ($version === null) {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $request->session()->put(ClientAccessGate::SESSION_VERSION_KEY, $version);

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
