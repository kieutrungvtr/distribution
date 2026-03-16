<?php

namespace PLSys\DistrbutionQueue\App\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class DistributionCache
{
    private bool $enabled;
    private string $prefix;
    private ?string $connection;

    public function __construct()
    {
        $this->enabled = (bool) config('distribution.cache.enabled', false);
        $this->prefix = config('distribution.cache.prefix', 'dist');
        $this->connection = config('distribution.cache.connection', 'default');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    private function key(string $suffix): string
    {
        return $this->prefix . ':' . $suffix;
    }

    private function redis(): Connection
    {
        return Redis::connection($this->connection);
    }

    // ── Active Job Names (cached 30s) ──

    public function getActiveJobNames(callable $dbFallback): array
    {
        if (!$this->enabled) {
            return $dbFallback();
        }

        try {
            $cached = $this->redis()->get($this->key('active_jobs'));
            if ($cached !== null) {
                return json_decode($cached, true);
            }

            $names = $dbFallback();
            $this->redis()->setex($this->key('active_jobs'), 30, json_encode($names));
            return $names;
        } catch (\Exception $e) {
            return $dbFallback();
        }
    }

    public function invalidateActiveJobNames(): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis()->del($this->key('active_jobs'));
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    // ── Pushed Count (persistent key, no TTL — synced periodically) ──

    public function getPushedCount(string $jobName, callable $dbFallback): int
    {
        if (!$this->enabled) {
            return $dbFallback();
        }

        try {
            $key = $this->key("pushed_count:{$jobName}");
            $cached = $this->redis()->get($key);
            if ($cached !== null) {
                return max(0, (int) $cached);
            }

            // Key doesn't exist — initialize from DB
            $count = $dbFallback();
            $this->redis()->set($key, $count);
            return $count;
        } catch (\Exception $e) {
            return $dbFallback();
        }
    }

    /**
     * Get pushed count from Redis only (no DB fallback). Returns null if unavailable.
     */
    public function getRedisPushedCount(string $jobName): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $cached = $this->redis()->get($this->key("pushed_count:{$jobName}"));
            return $cached !== null ? max(0, (int) $cached) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Atomically reserve quota slots via Lua script.
     * Returns the number of slots actually reserved (0 if over quota).
     * When cache is disabled, returns $requested (DB handles quota).
     */
    public function reserveQuota(string $jobName, int $requested, int $quota): int
    {
        if (!$this->enabled || $requested <= 0) {
            return $requested;
        }

        try {
            $key = $this->key("pushed_count:{$jobName}");

            $script = <<<'LUA'
                if redis.call('exists', KEYS[1]) == 0 then
                    return -1
                end
                local current = tonumber(redis.call('get', KEYS[1]))
                local requested = tonumber(ARGV[1])
                local quota = tonumber(ARGV[2])
                local available = math.max(0, quota - current)
                local reserved = math.min(requested, available)
                if reserved > 0 then
                    redis.call('incrby', KEYS[1], reserved)
                end
                return reserved
            LUA;

            $reserved = $this->redis()->eval($script, 1, $key, $requested, $quota);
            return ($reserved !== null && $reserved >= 0) ? (int) $reserved : $requested;
        } catch (\Exception $e) {
            return $requested;
        }
    }

    /**
     * Release unused quota slots (when fewer items were actually locked).
     */
    public function releaseQuota(string $jobName, int $slots): void
    {
        if (!$this->enabled || $slots <= 0) {
            return;
        }

        try {
            $key = $this->key("pushed_count:{$jobName}");

            // Atomic decrement with floor at 0
            $script = <<<'LUA'
                if redis.call('exists', KEYS[1]) == 0 then
                    return 0
                end
                local current = tonumber(redis.call('get', KEYS[1]))
                local release = tonumber(ARGV[1])
                local newVal = math.max(0, current - release)
                redis.call('set', KEYS[1], newVal)
                return newVal
            LUA;

            $this->redis()->eval($script, 1, $key, $slots);
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    /**
     * Increment pushed count atomically.
     */
    public function incrementPushedCount(string $jobName, int $by = 1): void
    {
        if (!$this->enabled || $by <= 0) {
            return;
        }

        try {
            $key = $this->key("pushed_count:{$jobName}");
            if ($this->redis()->exists($key)) {
                $this->redis()->incrby($key, $by);
            }
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    /**
     * Decrement pushed count atomically (floor at 0).
     */
    public function decrementPushedCount(string $jobName, int $by = 1): void
    {
        if (!$this->enabled || $by <= 0) {
            return;
        }

        try {
            $key = $this->key("pushed_count:{$jobName}");

            $script = <<<'LUA'
                if redis.call('exists', KEYS[1]) == 0 then
                    return 0
                end
                local current = tonumber(redis.call('get', KEYS[1]))
                local dec = tonumber(ARGV[1])
                local newVal = math.max(0, current - dec)
                redis.call('set', KEYS[1], newVal)
                return newVal
            LUA;

            $this->redis()->eval($script, 1, $key, $by);
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    /**
     * Sync pushed count from DB value. Call periodically to correct drift.
     */
    public function syncPushedCount(string $jobName, int $dbCount): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $key = $this->key("pushed_count:{$jobName}");
            $this->redis()->set($key, max(0, $dbCount));
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    // ── Worker Registration (sorted set — O(log N) instead of KEYS O(N)) ──

    /**
     * Register worker heartbeat using sorted set (score = unix timestamp).
     */
    public function registerWorker(string $workerId, int $sleepSeconds = 5): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis()->zadd($this->key('workers'), time(), $workerId);
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    /**
     * Remove worker from sorted set.
     */
    public function unregisterWorker(string $workerId): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis()->zrem($this->key('workers'), $workerId);
        } catch (\Exception $e) {
            // Silently ignore
        }
    }

    /**
     * Get active workers via ZRANGE after cleaning stale entries.
     * O(log N + M) where M = stale entries, vs old KEYS which was O(total keyspace).
     *
     * @param int $ttl Seconds without heartbeat before a worker is considered stale
     */
    public function getActiveWorkers(int $ttl = 30): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $key = $this->key('workers');
            $cutoff = time() - $ttl;

            // Remove stale workers (score < cutoff)
            $this->redis()->zremrangebyscore($key, '-inf', $cutoff);

            // Get all remaining workers
            $workers = $this->redis()->zrange($key, 0, -1);

            $result = is_array($workers) ? $workers : [];
            sort($result);
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
}
