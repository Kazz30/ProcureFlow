<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Services\Integration\IntegrationClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * B7 / A6 – php artisan integration:sync-masterdata
 * Kinukuha ang mga KOPYA mula sa may-ari, gamit ang updated_since, at nag-u-upsert gamit ang code.
 *   HR:  /branches, /departments, /employees  (→ users.can_login; burado ang tokens kapag false)
 *   INV: /warehouses, /products
 * Naka-schedule bawat 15 minuto. Gagana lang kapag INTEGRATION_ENABLED=true (Yugto 2).
 *
 * Kapag down ang isang system, tuloy pa rin ang iba; susubukan ulit sa susunod na takbo.
 */
class SyncMasterData extends Command
{
    protected $signature = 'integration:sync-masterdata
        {--only=* : Hal. --only=employees --only=products}
        {--full : Huwag gamitin ang updated_since; kunin ang lahat}';

    protected $description = 'Kunin ang mga kopya (employees, branches, departments, products, warehouses) mula sa HR at INV';

    /** Hindi babasahin nang higit dito ang mga page (proteksyon kung mali ang meta.last_page). */
    private const MAX_PAGES = 1000;

    public function handle(IntegrationClient $client): int
    {
        if (! config('integration.enabled')) {
            $this->info('INTEGRATION_ENABLED=false: walang kukunin (Yugto 1).');

            return self::SUCCESS;
        }

        $only = (array) $this->option('only');
        $failed = 0;

        foreach ($this->resources() as $name => $resource) {
            if ($only && ! in_array($name, $only, true)) {
                continue;
            }

            try {
                $changed = $this->sync($client, $name, $resource);
                $this->info(sprintf('  %-12s %s: %d record', $name, $resource['system'], count($changed)));

                if ($name === 'employees' && $changed) {
                    $deactivated = User::syncLoginFromEmployees($changed);
                    if ($deactivated) {
                        $this->warn("  $deactivated user ang hindi na makaka-login (binura ang tokens).");
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('  %-12s %s: FAILED – %s', $name, $resource['system'], $e->getMessage()));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Kinukuha ang lahat ng page ng isang resource at nag-u-upsert.
     *
     * @return array<int, string> ang mga code na nabago
     */
    private function sync(IntegrationClient $client, string $name, array $resource): array
    {
        $since = $this->option('full') ? null : DB::table($resource['table'])->max('source_updated_at');
        $query = ['per_page' => 100];
        if ($since) {
            $query['updated_since'] = Carbon::parse($since, config('app.timezone'))->toIso8601String();
        }

        $codes = [];
        $page = 1;

        do {
            $result = $client->get($resource['system'], $resource['endpoint'], $query + ['page' => $page]);

            if (! $result->successful()) {
                throw new RuntimeException($result->error ?? 'Walang sagot');
            }

            $items = data_get($result->body, 'data.items');
            $lastPage = (int) data_get($result->body, 'data.meta.last_page', 1);
            if (! is_array($items)) {
                throw new RuntimeException('Hindi B5 na listahan ang sagot (walang data.items).');
            }

            $rows = [];
            foreach ($items as $item) {
                $code = $item[$resource['key']] ?? null;
                if (! is_string($code) || $code === '') {
                    $this->warn("  $name: may record na walang {$resource['key']}; nilaktawan.");
                    continue;
                }
                $rows[] = $resource['map']($item) + [
                    $resource['key'] => $code,
                    'source_updated_at' => isset($item['updated_at'])
                        ? Carbon::parse($item['updated_at'])->setTimezone(config('app.timezone'))
                        : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $codes[] = $code;
            }

            if ($rows) {
                $update = array_values(array_diff(array_keys($rows[0]), [$resource['key'], 'created_at']));
                DB::table($resource['table'])->upsert($rows, [$resource['key']], $update);
            }

            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);

        return array_values(array_unique($codes));
    }

    /** Mga kopya ayon sa A3/A6 at B9. Pagkakasunod: branches at warehouses muna. */
    private function resources(): array
    {
        $status = fn (array $i) => $i['status'] ?? 'Active';

        return [
            'branches' => [
                'system' => 'HR', 'endpoint' => '/branches', 'table' => 'branches', 'key' => 'branch_code',
                'map' => fn ($i) => [
                    'name' => $i['name'] ?? $i['branch_code'],
                    'city' => $i['city'] ?? null,
                    'status' => $status($i),
                ],
            ],
            'departments' => [
                'system' => 'HR', 'endpoint' => '/departments', 'table' => 'departments', 'key' => 'department_code',
                'map' => fn ($i) => [
                    'name' => $i['name'] ?? $i['department_code'],
                    'status' => $status($i),
                ],
            ],
            'employees' => [
                'system' => 'HR', 'endpoint' => '/employees', 'table' => 'employees', 'key' => 'employee_no',
                'map' => function ($i) {
                    $employment = $i['employment_status'] ?? 'Active';

                    return [
                        'first_name' => $i['first_name'] ?? '',
                        'last_name' => $i['last_name'] ?? '',
                        'email' => $i['email'] ?? null,
                        'branch_code' => $i['branch_code'] ?? null,
                        'department_code' => $i['department_code'] ?? null,
                        'position' => $i['position'] ?? null,
                        'employment_status' => $employment,
                        // B7: true lang kung Active o On Leave. Kapag nagbigay ang HR, iyon ang sundin,
                        // pero hindi kailanman true kapag Suspended/Resigned/Terminated.
                        'can_login' => in_array($employment, Employee::LOGIN_STATUSES, true)
                            && (bool) ($i['can_login'] ?? true),
                    ];
                },
            ],
            'warehouses' => [
                'system' => 'INV', 'endpoint' => '/warehouses', 'table' => 'warehouses', 'key' => 'warehouse_code',
                'map' => fn ($i) => [
                    'name' => $i['name'] ?? $i['warehouse_code'],
                    'branch_code' => $i['branch_code'] ?? '',
                    'is_default' => (bool) ($i['is_default'] ?? false),
                    'status' => $status($i),
                ],
            ],
            'products' => [
                'system' => 'INV', 'endpoint' => '/products', 'table' => 'products', 'key' => 'sku',
                'map' => fn ($i) => [
                    'name' => $i['name'] ?? $i['sku'],
                    'category' => $i['category'] ?? null,
                    'unit' => $i['unit'] ?? null,
                    'price' => $i['price'] ?? 0,
                    'cost' => $i['cost'] ?? 0,
                    'reorder_level' => (int) ($i['reorder_level'] ?? 0),
                    'reorder_quantity' => (int) ($i['reorder_quantity'] ?? 0),
                    'status' => $status($i),
                ],
            ],
        ];
    }
}
