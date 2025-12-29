<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\PendingRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Auth\Events\Registered;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Connexion utilisateur
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        // Vérifier si l'email est vérifié
        if (!$user->hasVerifiedEmail()) {
            // Vérifier aussi dans pending_registrations au cas où
            $pendingRegistration = PendingRegistration::where('email', $request->email)->first();
            if ($pendingRegistration) {
                throw ValidationException::withMessages([
                    'email' => ['Votre compte est en attente de vérification. Veuillez vérifier votre boîte mail et entrer le code de vérification à 6 chiffres pour finaliser votre inscription.'],
                ]);
            }
            
            throw ValidationException::withMessages([
                'email' => ['Votre adresse email n\'a pas été vérifiée. Veuillez vérifier votre boîte mail et entrer le code de vérification à 6 chiffres.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'zone_id' => $user->zone_id,
            ],
            'token' => $token,
        ]);
    }

    /**
     * Déconnexion utilisateur
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnexion réussie']);
    }

    /**
     * Inscription d'un nouvel utilisateur (stockage temporaire jusqu'à vérification)
     */
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255',
                'password' => 'required|string|min:8|confirmed',
                'role' => 'nullable|string|in:admin,chef,cook,storekeeper,accountant,butcher,server,director',
                'zone_id' => 'nullable|integer|exists:zones,id',
            ]);

            // Vérifier que l'email n'existe pas dans users OU pending_registrations
            $emailExists = User::where('email', $validated['email'])->exists() 
                || PendingRegistration::where('email', $validated['email'])->exists();
            
            if ($emailExists) {
                return response()->json([
                    'message' => 'Erreur de validation',
                    'errors' => [
                        'email' => ['Cet email est déjà utilisé.']
                    ],
                ], 422);
            }

            // Générer un code de vérification à 6 chiffres
            $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Stocker temporairement dans pending_registrations (pas encore créé en base users)
            $pendingRegistration = PendingRegistration::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'], // Hashé automatiquement grâce au cast
                'role' => $validated['role'] ?? 'cook',
                'zone_id' => isset($validated['zone_id']) && $validated['zone_id'] > 0 ? $validated['zone_id'] : null,
                'email_verification_code' => $verificationCode,
                'email_verification_code_expires_at' => Carbon::now()->addMinutes(15),
            ]);

            // Envoyer l'email de vérification avec le code
            // Créer un objet User temporaire pour utiliser la notification
            $tempUser = new User();
            $tempUser->name = $pendingRegistration->name;
            $tempUser->email = $pendingRegistration->email;
            $tempUser->email_verification_code = $pendingRegistration->email_verification_code;
            $tempUser->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Un code de vérification a été envoyé à votre email. Veuillez le vérifier pour finaliser votre inscription.',
                'email_sent' => true,
                'email' => $pendingRegistration->email,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'inscription: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Erreur lors de l\'inscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Vérifier l'email avec un code de vérification et créer le compte
     */
    public function verifyEmail(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'code' => 'required|string|size:6',
            ]);

            // Chercher dans pending_registrations
            $pendingRegistration = PendingRegistration::where('email', $validated['email'])->first();

            if (!$pendingRegistration) {
                // Vérifier si c'est un compte existant (pour compatibilité)
                $existingUser = User::where('email', $validated['email'])->first();
                if ($existingUser) {
                    if ($existingUser->hasVerifiedEmail()) {
                        return response()->json([
                            'message' => 'Email déjà vérifié',
                            'verified' => true,
                        ], 200);
                    }
                    
                    // Vérifier le code pour un compte existant
                    if ($existingUser->email_verification_code === $validated['code']) {
                        if ($existingUser->email_verification_code_expires_at && Carbon::now()->gt($existingUser->email_verification_code_expires_at)) {
                            return response()->json([
                                'message' => 'Code de vérification expiré. Veuillez demander un nouveau code.',
                                'verified' => false,
                                'expired' => true,
                            ], 400);
                        }
                        
                        $existingUser->markEmailAsVerified();
                        $existingUser->email_verification_code = null;
                        $existingUser->email_verification_code_expires_at = null;
                        $existingUser->save();
                        
                        return response()->json([
                            'message' => 'Email vérifié avec succès. Vous pouvez maintenant vous connecter.',
                            'verified' => true,
                        ], 200);
                    }
                }
                
                return response()->json([
                    'message' => 'Aucune inscription en attente trouvée pour cet email',
                    'verified' => false,
                ], 404);
            }

            // Vérifier le code
            if ($pendingRegistration->email_verification_code !== $validated['code']) {
                return response()->json([
                    'message' => 'Code de vérification incorrect',
                    'verified' => false,
                ], 400);
            }

            // Vérifier si le code a expiré
            if ($pendingRegistration->isCodeExpired()) {
                return response()->json([
                    'message' => 'Code de vérification expiré. Veuillez demander un nouveau code.',
                    'verified' => false,
                    'expired' => true,
                ], 400);
            }

            // Créer l'utilisateur dans la table users (maintenant que l'email est vérifié)
            try {
                $user = User::create([
                    'name' => $pendingRegistration->name,
                    'email' => $pendingRegistration->email,
                    'password' => $pendingRegistration->password, // Déjà hashé
                    'role' => $pendingRegistration->role,
                    'zone_id' => $pendingRegistration->zone_id,
                    'email_verified_at' => now(), // Marquer comme vérifié immédiatement
                ]);

                Log::info('Compte créé après vérification', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ]);

                // Supprimer l'enregistrement temporaire
                $pendingRegistration->delete();
            } catch (\Exception $e) {
                Log::error('Erreur lors de la création du compte après vérification', [
                    'email' => $pendingRegistration->email,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            return response()->json([
                'message' => 'Email vérifié avec succès. Votre compte a été créé. Vous pouvez maintenant vous connecter.',
                'verified' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
                'verified' => false,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur vérification email: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Erreur lors de la vérification',
                'verified' => false,
            ], 500);
        }
    }

    /**
     * Renvoyer l'email de vérification avec un nouveau code
     */
    public function resendVerificationEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Chercher dans pending_registrations d'abord
        $pendingRegistration = PendingRegistration::where('email', $request->email)->first();
        
        if ($pendingRegistration) {
            // Générer un nouveau code
            $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pendingRegistration->email_verification_code = $verificationCode;
            $pendingRegistration->email_verification_code_expires_at = Carbon::now()->addMinutes(15);
            $pendingRegistration->save();

            // Envoyer l'email avec le nouveau code
            $tempUser = new User();
            $tempUser->name = $pendingRegistration->name;
            $tempUser->email = $pendingRegistration->email;
            $tempUser->email_verification_code = $pendingRegistration->email_verification_code;
            $tempUser->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Code de vérification renvoyé avec succès',
            ], 200);
        }

        // Vérifier si c'est un compte existant
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'message' => 'Aucune inscription en attente trouvée pour cet email',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email déjà vérifié',
            ], 400);
        }

        // Générer un nouveau code de vérification
        $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->email_verification_code = $verificationCode;
        $user->email_verification_code_expires_at = Carbon::now()->addMinutes(15);
        $user->save();

        // Envoyer l'email avec le nouveau code
        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Code de vérification renvoyé avec succès',
        ], 200);
    }

    /**
     * Obtenir l'utilisateur actuel
     */
    public function user(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'zone_id' => $user->zone_id,
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }
}
