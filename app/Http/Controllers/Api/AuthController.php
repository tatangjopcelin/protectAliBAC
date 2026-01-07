<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\PendingRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Password;
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
                'store_id' => $user->store_id,
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
                'registration_type' => 'required|string|in:create_store,join_store',
                
                // Champs utilisateur
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255',
                'password' => 'required|string|min:8|confirmed',
                'role' => 'nullable|string|in:admin,chef,cook,storekeeper,accountant,butcher,server,director',
                'zone_id' => 'nullable|integer|exists:zones,id',
                
                // Champs pour création d'établissement
                'store_name' => 'required_if:registration_type,create_store|string|max:255',
                'store_address' => 'nullable|string|max:500',
                'store_phone' => 'nullable|string|max:20',
                
                // Code pour rejoindre un établissement
                'establishment_code' => 'required_if:registration_type,join_store|string|size:4|exists:stores,establishment_code',
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

            // Si join_store, vérifier que le code existe
            if ($validated['registration_type'] === 'join_store') {
                $store = \App\Models\Store::where('establishment_code', $validated['establishment_code'])->first();
                if (!$store) {
                    return response()->json([
                        'message' => 'Erreur de validation',
                        'errors' => [
                            'establishment_code' => ['Code d\'établissement invalide.']
                        ]
                    ], 422);
                }
            }

            // Générer code de vérification email
            $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Déterminer le rôle
            $role = $validated['registration_type'] === 'create_store' 
                ? 'admin' 
                : ($validated['role'] ?? 'cook');
            
            // Stocker dans pending_registrations
            $pendingRegistration = PendingRegistration::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $role,
                'zone_id' => $validated['zone_id'] ?? null,
                'email_verification_code' => $verificationCode,
                'email_verification_code_expires_at' => Carbon::now()->addMinutes(15),
                'registration_type' => $validated['registration_type'],
                'store_name' => $validated['store_name'] ?? null,
                'store_address' => $validated['store_address'] ?? null,
                'store_phone' => $validated['store_phone'] ?? null,
                'establishment_code' => $validated['establishment_code'] ?? null,
            ]);

            // Envoyer email de vérification
            $tempUser = new User();
            $tempUser->name = $pendingRegistration->name;
            $tempUser->email = $pendingRegistration->email;
            $tempUser->email_verification_code = $pendingRegistration->email_verification_code;
            $tempUser->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Un code de vérification a été envoyé à votre email.',
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

            // Créer l'utilisateur et l'établissement si nécessaire
            try {
                $user = null;
                
                if ($pendingRegistration->registration_type === 'create_store') {
                    // Créer l'établissement
                    $establishmentCode = \App\Models\Store::generateEstablishmentCode();
                    
                    $store = \App\Models\Store::create([
                        'name' => $pendingRegistration->store_name,
                        'address' => $pendingRegistration->store_address,
                        'phone' => $pendingRegistration->store_phone,
                        'establishment_code' => $establishmentCode,
                        'is_active' => true,
                    ]);
                    
                    // Créer l'utilisateur admin
                    $user = User::create([
                        'name' => $pendingRegistration->name,
                        'email' => $pendingRegistration->email,
                        'password' => $pendingRegistration->password,
                        'role' => 'admin',
                        'store_id' => $store->id,
                        'email_verified_at' => now(),
                    ]);
                    
                    // Mettre à jour created_by de l'établissement
                    $store->created_by = $user->id;
                    $store->save();
                    
                    // Envoyer le code d'établissement par email
                    $user->notify(new \App\Notifications\EstablishmentCodeNotification(
                        $store->name,
                        $establishmentCode
                    ));
                    
                } else {
                    // Rejoindre un établissement existant
                    $store = \App\Models\Store::where('establishment_code', $pendingRegistration->establishment_code)->first();
                    
                    if (!$store) {
                        return response()->json([
                            'message' => 'Code d\'établissement invalide',
                            'verified' => false,
                        ], 400);
                    }
                    
                    // Créer l'utilisateur employé
                    $user = User::create([
                        'name' => $pendingRegistration->name,
                        'email' => $pendingRegistration->email,
                        'password' => $pendingRegistration->password,
                        'role' => $pendingRegistration->role ?? 'cook',
                        'store_id' => $store->id,
                        'zone_id' => $pendingRegistration->zone_id,
                        'email_verified_at' => now(),
                    ]);
                }

                Log::info('Compte créé après vérification', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'store_id' => $user->store_id,
                    'registration_type' => $pendingRegistration->registration_type,
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
                'message' => 'Email vérifié avec succès. Votre compte a été créé.',
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
        $user = $request->user()->load('store');
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'zone_id' => $user->zone_id,
            'store_id' => $user->store_id,
            'email_verified' => $user->hasVerifiedEmail(),
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'establishment_code' => $user->store->establishment_code,
            ] : null,
        ]);
    }

    /**
     * Demander la réinitialisation du mot de passe (mot de passe oublié)
     */
    public function forgotPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
            ]);

            // Vérifier que l'utilisateur existe et a un email vérifié
            $user = User::where('email', $validated['email'])->first();

            if (!$user) {
                // Pour des raisons de sécurité, on ne révèle pas si l'email existe ou non
                return response()->json([
                    'message' => 'Si cet email existe dans notre système, un lien de réinitialisation vous a été envoyé.',
                ], 200);
            }

            if (!$user->hasVerifiedEmail()) {
                return response()->json([
                    'message' => 'Votre adresse email n\'a pas été vérifiée. Veuillez d\'abord vérifier votre email.',
                ], 400);
            }

            // Envoyer la notification de réinitialisation personnalisée
            // Utiliser sendResetLink avec une callback pour utiliser notre notification personnalisée
            $status = Password::sendResetLink(
                ['email' => $validated['email']],
                function ($user, $token) {
                    // Créer une instance de notification avec le token fourni par Laravel
                    $user->notify(new \App\Notifications\ResetPasswordNotification($token));
                }
            );

            if ($status === Password::RESET_LINK_SENT) {
                return response()->json([
                    'message' => 'Un lien de réinitialisation a été envoyé à votre adresse email.',
                ], 200);
            }

            return response()->json([
                'message' => 'Erreur lors de l\'envoi du lien de réinitialisation.',
            ], 500);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la demande de réinitialisation: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la demande de réinitialisation',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Réinitialiser le mot de passe
     */
    public function resetPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'token' => 'required|string',
                'email' => 'required|email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $status = Password::reset(
                $validated,
                function ($user, $password) {
                    $user->password = Hash::make($password);
                    $user->save();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json([
                    'message' => 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.',
                ], 200);
            }

            if ($status === Password::INVALID_TOKEN) {
                return response()->json([
                    'message' => 'Le lien de réinitialisation est invalide ou a expiré. Veuillez demander un nouveau lien.',
                ], 400);
            }

            if ($status === Password::INVALID_USER) {
                return response()->json([
                    'message' => 'Aucun utilisateur trouvé avec cet email.',
                ], 404);
            }

            return response()->json([
                'message' => 'Erreur lors de la réinitialisation du mot de passe.',
            ], 500);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la réinitialisation: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la réinitialisation du mot de passe',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur est survenue',
            ], 500);
        }
    }
}
