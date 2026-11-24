<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hakbang 11 (F1) – Login at HR status.
 */
class LoginStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
    }

    private function login(string $email = 'grace.ramos@example.com', string $password = 'password')
    {
        return $this->postJson('/api/login', compact('email', 'password'));
    }

    /** Ginagaya ang HR: binago ang kopya ng employee, tapos nag-sync. */
    private function hrSetsStatus(string $employeeNo, string $status): void
    {
        DB::table('employees')->where('employee_no', $employeeNo)->update([
            'employment_status' => $status,
            'can_login' => in_array($status, ['Active', 'On Leave'], true),
        ]);
        User::syncLoginFromEmployees();
    }

    public function test_user_with_can_login_false_cannot_log_in(): void
    {
        $this->hrSetsStatus('EMP-0008', 'Resigned');

        $this->login()
            ->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Resigned'));
    }

    public function test_wrong_password_still_says_wrong_password_even_if_inactive(): void
    {
        $this->hrSetsStatus('EMP-0008', 'Resigned');

        $this->login(password: 'mali')->assertStatus(422);   // hindi nalalaman ang status nang walang tamang password
    }

    public function test_on_leave_can_still_log_in(): void
    {
        $this->hrSetsStatus('EMP-0008', 'On Leave');

        $this->login()->assertOk();
    }

    public function test_tokens_are_deleted_when_can_login_becomes_false(): void
    {
        $token = $this->login()->assertOk()->json('token');
        $this->login()->assertOk();                          // 2 device
        $user = User::where('employee_no', 'EMP-0008')->first();
        $this->assertSame(2, $user->tokens()->count());

        $this->hrSetsStatus('EMP-0008', 'Terminated');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('Terminated', $user->fresh()->employment_status);
        $this->assertFalse($user->fresh()->can_login);

        // Hindi na gumagana ang lumang token.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_tokens_of_other_users_are_kept(): void
    {
        $this->login('maria.santos@example.com')->assertOk();
        $this->hrSetsStatus('EMP-0008', 'Suspended');

        $this->assertSame(1, User::where('employee_no', 'EMP-0001')->first()->tokens()->count());
    }

    public function test_leftover_token_is_rejected_by_the_second_guard(): void
    {
        $token = $this->login()->json('token');
        // Binago nang direkta sa database (lampas sa model hook) – dapat tanggihan pa rin.
        DB::table('users')->where('employee_no', 'EMP-0008')->update(['can_login' => false]);

        $this->withToken($token)->getJson('/api/suppliers')->assertStatus(401);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_seeder_rerun_applies_hr_status_but_keeps_role_and_password(): void
    {
        User::where('employee_no', 'EMP-0008')->first()->update(['role' => 'Manager', 'password' => 'bago-na-password']);
        DB::table('employees')->where('employee_no', 'EMP-0008')->update(['employment_status' => 'Resigned', 'can_login' => false]);

        // (Ang seeder ay nagbabasa ulit ng CSV, kaya ibabalik nito sa Active; tingnan lang na hindi nagalaw ang role/password.)
        $this->seed(MasterDataSeeder::class);

        $user = User::where('employee_no', 'EMP-0008')->first();
        $this->assertSame('Manager', $user->role);
        $this->assertTrue(\Hash::check('bago-na-password', $user->password));
        $this->assertTrue($user->can_login);
    }

    // ---------- Users page (Admin) ----------

    private function asAdmin(): static
    {
        return $this->actingAs(User::where('employee_no', 'EMP-0001')->first(), 'sanctum');
    }

    public function test_admin_creates_account_from_employee_copy(): void
    {
        $this->asAdmin()->getJson('/api/users/available-employees')
            ->assertOk()->assertJsonCount(6, 'data');          // 9 employees − 3 may account na

        $this->asAdmin()->postJson('/api/users', [
            'employee_no' => 'EMP-0007', 'email' => 'ramon.villanueva@example.com',
            'role' => 'Purchasing Officer', 'password' => 'secret123',
        ])->assertCreated()->assertJsonPath('data.name', 'Ramon Villanueva')->assertJsonPath('data.can_login', true);

        $this->postJson('/api/login', ['email' => 'ramon.villanueva@example.com', 'password' => 'secret123'])->assertOk();
    }

    public function test_cannot_create_two_accounts_for_one_employee_or_unknown_employee(): void
    {
        $this->asAdmin()->postJson('/api/users', [
            'employee_no' => 'EMP-0008', 'email' => 'x@example.com', 'role' => 'Manager', 'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_no');

        $this->asAdmin()->postJson('/api/users', [
            'employee_no' => 'EMP-9999', 'email' => 'y@example.com', 'role' => 'Manager', 'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_no');
    }

    public function test_only_admin_can_manage_users(): void
    {
        $this->actingAs(User::where('employee_no', 'EMP-0002')->first(), 'sanctum')
            ->getJson('/api/users')->assertForbidden();
    }

    public function test_admin_cannot_remove_own_admin_role(): void
    {
        $admin = User::where('employee_no', 'EMP-0001')->first();

        $this->asAdmin()->putJson("/api/users/{$admin->id}", ['role' => 'Manager', 'email' => $admin->email])
            ->assertStatus(422);
    }
}
