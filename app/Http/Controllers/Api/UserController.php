<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * F1 – Mga user account (Admin lang).
 * Hindi gumagawa ng user nang awtomatiko: ang Admin ang gagawa, at pipili siya ng
 * employee_no mula sa kopya ng employees. Ang employment_status at can_login ay
 * galing sa employee (HR), hindi binabago dito.
 */
class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->with('employee:employee_no,first_name,last_name,branch_code,department_code,position,employment_status')
            ->withCount('tokens')
            ->orderBy('employee_no')
            ->get(['id', 'employee_no', 'name', 'email', 'role', 'employment_status', 'can_login', 'created_at']);

        return response()->json(['data' => $users]);
    }

    /** Mga employee na wala pang account (para sa dropdown). */
    public function availableEmployees()
    {
        $taken = User::whereNotNull('employee_no')->pluck('employee_no');

        return response()->json([
            'data' => Employee::whereNotIn('employee_no', $taken)
                ->orderBy('employee_no')
                ->get(['employee_no', 'first_name', 'last_name', 'email', 'branch_code', 'position', 'employment_status', 'can_login']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_no' => ['required', 'exists:employees,employee_no', 'unique:users,employee_no'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => ['required', 'string', Password::min(8)],
        ], ['employee_no.unique' => 'May account na ang employee na ito.']);

        $employee = Employee::where('employee_no', $data['employee_no'])->first();

        $user = User::create([
            'employee_no' => $employee->employee_no,
            'name' => $employee->full_name,
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],               // na-hash ng 'hashed' cast
            'employment_status' => $employee->employment_status,
            'can_login' => $employee->can_login,
        ]);

        return response()->json(['data' => $user], 201);
    }

    /** Palitan ang role at/o password. */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', Password::min(8)],
        ]);

        // Huwag hayaang matanggal ang sariling pagka-Admin (baka walang matirang Admin).
        if ($user->is($request->user()) && $data['role'] !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Hindi mo puwedeng tanggalin ang sarili mong pagka-Admin.'], 422);
        }

        $user->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return response()->json(['data' => $user->fresh()]);
    }
}
