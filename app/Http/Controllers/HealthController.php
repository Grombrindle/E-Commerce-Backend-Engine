<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{

    public function live(): JsonResponse
    {
        return response()->json([
            'status'    => 'alive',
            'timestamp' => now()->toIso8601String(),
            'hostname'  => gethostname(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'app'      => $this->checkApplication(),
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
            'queue'    => $this->checkQueue(),
            'storage'  => $this->checkStorage(),
        ];

        $allHealthy = collect($checks)->every(fn($c) => $c['healthy']);

        return response()->json([
            'status'    => $allHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'hostname'  => gethostname(),
            'checks'    => $checks,
        ], $allHealthy ? 200 : 503);
    }

    public function startup(): JsonResponse
    {
        return response()->json([
            'status'    => 'starting',
            'timestamp' => now()->toIso8601String(),
            'hostname'  => gethostname(),
        ]);
    }

    private function checkApplication(): array
    {
        return ['healthy' => true, 'message' => 'Application running'];
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('SELECT 1');
            return ['healthy' => true, 'message' => 'Connected'];
        } catch (\Throwable $e) {
            Log::error("Health check — Database failed: {$e->getMessage()}");
            return ['healthy' => false, 'message' => 'Cannot connect to database'];
        }
    }

    private function checkRedis(): array
    {
        try {
            Redis::ping();
            return ['healthy' => true, 'message' => 'Connected'];
        } catch (\Throwable $e) {
            Log::error("Health check — Redis failed: {$e->getMessage()}");
            return ['healthy' => false, 'message' => 'Cannot connect to Redis'];
        }
    }

    private function checkQueue(): array
    {
        try {
            $queueSize = Cache::get('queue:monitor:size', 0);
            $healthy = $queueSize < 1000;
            return [
                'healthy' => $healthy,
                'message' => $healthy ? 'Queue healthy' : "High queue depth: {$queueSize}",
            ];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'message' => 'Queue check failed'];
        }
    }

    private function checkStorage(): array
    {
        try {
            $freeSpace = disk_free_space(storage_path());
            $healthy = $freeSpace > 500 * 1024 * 1024; 
            return [
                'healthy' => $healthy,
                'message' => $healthy ? 'Storage OK' : 'Low disk space',
            ];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'message' => 'Storage check failed'];
        }
    }
}
