<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class CronMutexService
{
    public static function run(string $key, int $waitSeconds, int $holdSeconds, Closure $callback)
    {
        $lock = Cache::lock($key, $holdSeconds);
        $lock->block($waitSeconds);
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

