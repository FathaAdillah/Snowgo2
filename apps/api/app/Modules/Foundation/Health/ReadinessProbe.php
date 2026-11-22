<?php

namespace App\Modules\Foundation\Health;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ReadinessProbe
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * @return list<array{component: string, status: string}>
     */
    public function run(): array
    {
        return [
            $this->check('database', fn () => $this->database()),
            $this->check('redis', fn () => Redis::connection()->ping()),
            $this->check('storage', fn () => Storage::disk('private')->exists('.readiness')),
        ];
    }

    private function database(): void
    {
        DB::connection()->select('select 1');

        $repository = $this->migrator->getRepository();
        if (! $repository->repositoryExists()) {
            throw new RuntimeException('Migration repository missing.');
        }

        $files = $this->migrator->getMigrationFiles($this->migrator->paths());
        $pending = array_diff(array_keys($files), $repository->getRan());
        if ($pending !== []) {
            throw new RuntimeException(count($pending).' pending migrations.');
        }
    }

    /**
     * @return array{component: string, status: string}
     */
    private function check(string $component, callable $probe): array
    {
        try {
            $probe();

            return ['component' => $component, 'status' => 'ok'];
        } catch (Throwable $e) {
            Log::warning('Readiness check failed.', ['component' => $component, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return ['component' => $component, 'status' => 'fail'];
        }
    }
}
