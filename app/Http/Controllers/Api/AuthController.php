<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login ng mga user ng PROC gamit ang Sanctum token.
 * F1: hindi makaka-login ang user na can_login = false.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Mali ang email o password.'],
            ]);
        }

        // F1: tanggihan kapag hindi na puwedeng mag-login ayon sa HR (hal. Resigned).
        // (Sinusuri pagkatapos ng password, para hindi malaman ng iba kung sino ang may account.)
        if (! $user->can_login) {
            return response()->json([
                'message' => 'Hindi na aktibo ang account mo'.($user->employment_status ? " ({$user->employment_status})" : '').'. Makipag-ugnayan sa HR.',
            ], 403);
        }

        $token = $user->createToken('proc-web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userData($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->userData($request->user())]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Naka-logout na.']);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_no' => $user->employee_no,
            'role' => $user->role,
        ];
    }
}
