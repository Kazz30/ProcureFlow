<?php

namespace Tests\Feature\Integration;

use App\Support\IntegrationResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A4 Tests: API key (401) at /health.
 */
class HealthAndApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integration.api_key' => self::KEY, 'integration.system_code' => 'PROC']);

        // Pansamantalang route para lang sa test na ito (may integration.key).
        Route::middleware('integration.key')
            ->get('/api/integration/v1/_test-protected', fn () => IntegrationResponse::ok(['hello' => 'world']));
    }

    public function test_health_works_without_api_key(): void
    {
        $response = $this->getJson('/api/integration/v1/health');

        $response->assertOk()
            ->assertJson(['success' => true, 'system' => 'PROC', 'version' => 'v1'])
            ->assertJsonStructure(['success', 'system', 'version', 'time']);

        // ISO 8601 na may +08:00 (Asia/Manila).
        $this->assertStringEndsWith('+08:00', $response->json('time'));
    }

    public function test_missing_api_key_returns_401(): void
    {
        $this->getJson('/api/integration/v1/_test-protected')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Invalid or missing API key']);
    }

    public function test_wrong_api_key_returns_401(): void
    {
        $this->getJson('/api/integration/v1/_test-protected', ['X-API-KEY' => 'mali'])
            ->assertStatus(401);
    }

    public function test_correct_api_key_passes_and_is_logged(): void
    {
        $this->getJson('/api/integration/v1/_test-protected', ['X-API-KEY' => self::KEY])
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['hello' => 'world']]);

        $this->assertDatabaseHas('integration_logs', [
            'direction' => 'in',
            'method' => 'GET',
            'status_code' => 200,
        ]);
    }

    public function test_empty_configured_key_rejects_everyone(): void
    {
        config(['integration.api_key' => '']);

        $this->getJson('/api/integration/v1/_test-protected', ['X-API-KEY' => ''])
            ->assertStatus(401);
    }

    public function test_unknown_integration_route_returns_404_in_b5_format(): void
    {
        $this->getJson('/api/integration/v1/wala-ito', ['X-API-KEY' => self::KEY])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Not found']);
    }
}
