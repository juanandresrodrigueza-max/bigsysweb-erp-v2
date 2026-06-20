<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'required|email|unique:users',
            'password'         => 'required|string|min:8|confirmed',
            'business_name'    => 'required|string|max:255',
            'business_email'   => 'required|email|unique:businesses,email',
            'business_phone'   => 'nullable|string|max:30',
        ]);

        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
                'status'   => 'active',
            ]);

            $business = Business::create([
                'name'     => $data['business_name'],
                'slug'     => Str::slug($data['business_name']) . '-' . Str::random(5),
                'email'    => $data['business_email'],
                'phone'    => $data['business_phone'] ?? null,
                'owner_id' => $user->id,
                'currency' => 'ARS',
                'country'  => 'AR',
            ]);

            $user->update(['business_id' => $business->id]);

            $business->locations()->create([
                'name'       => 'Sucursal Principal',
                'is_default' => true,
                'is_active'  => true,
            ]);

            $freePlan = Plan::where('is_free', true)->first();
            if ($freePlan) {
                Subscription::create([
                    'business_id'   => $business->id,
                    'plan_id'       => $freePlan->id,
                    'status'        => 'active',
                    'billing_cycle' => 'monthly',
                    'amount'        => 0,
                    'starts_at'     => now(),
                    'trial_ends_at' => now()->addDays(14),
                ]);
            }

            $token = $user->createToken('api')->plainTextToken;

            return response()->json([
                'token'    => $token,
                'user'     => $user,
                'business' => $business,
            ], 201);
        });
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está inactiva.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token'    => $token,
            'user'     => $user->load('business'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('business.activeSubscription.plan'));
    }
}
