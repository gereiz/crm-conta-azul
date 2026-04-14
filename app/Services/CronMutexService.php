<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class CronMutexService
{
    public static function run(string $key, int $waitSeconds, int $holdSeconds, Closure $callback)
    {
        $lock = Cache::lock($key, $holdSeconds);
        $acquired = $waitSeconds > 0
            ? $lock->block($waitSeconds)
            : $lock->get();

        if (! $acquired) {
            return null;
        }

        try {
            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
            }
        }
    }
}
