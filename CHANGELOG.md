# Changelog

All notable changes to `plsys/distrbution-queue` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [1.0.0] - 2026-03-13

### Added

- **Denormalized state column** (`distribution_current_state`) on `distributions` table — eliminates all correlated subqueries, indexed for fast lookups.
- **Write-through pattern** — every state transition updates both `distribution_states` (audit) and `distributions.distribution_current_state` (fast query) simultaneously.
- **Redis cache layer** (`DistributionCache`) with automatic DB fallback when Redis is unavailable.
  - `dist:active_jobs` — cached active job names (30s TTL).
  - `dist:pushed_count:{job}` — atomic quota counter via Lua scripts.
  - `dist:workers` — sorted set for worker heartbeats.
  - Drift detection and `syncPushedCount()` auto-recovery.
  - Fully opt-in: `DISTRIBUTION_CACHE_ENABLED=true`.
- **Supervisor** (`distribution:supervisor`) — single command spawns N worker processes, auto-restarts dead workers, graceful shutdown via SIGTERM/SIGKILL, PID lock prevents duplicates.
- **Worker coordinator** (`WorkerCoordinator`) — consistent hashing assigns disjoint job sets to workers, heartbeat-based rebalancing, no leader election needed.
- **`distribution:work`** daemon — auto-discovers job types, continuous push cycles, backlog retry, memory limit restart, `--once` mode for testing.
- **`distribution:monitor`** — real-time dashboard with in-place terminal updates (`--watch`), per-request breakdown (`--detail`), failure listing (`--failures`), JSON output (`--json`), Redis drift warnings, active worker display.
- **`distribution:archive`** — archive intermediate states (`--days`), full purge (`--purge`), dry-run mode, chunk-based processing.
- **`distribution:stress-test`** — configurable load testing with concurrent job simulation.
- **`distribution:pre-release-test`** — 16 automated checks: lifecycle, async push, retry, Throwable handling, fair distribution, SKIP LOCKED, atomic quota (Lua), drift sync, double-decrement, Redis fallback, PID lock, work --once, HTTP API, archive, migration schema, memory limit.
- **`distribution:scenario-test`** — 5 real-world scenarios using live Horizon + RabbitMQ: multi-tenant rush, failure + retry, quota pressure with multiple job types, Redis outage recovery, burst-drain-archive lifecycle.
- **Fair distribution algorithm** — quota divided proportionally across `request_id` groups with priority ordering, preventing starvation.
- **Atomic quota reservation** — Lua scripts for `reserveQuota` / `releaseQuota` prevent race conditions between workers.
- **`SELECT ... FOR UPDATE SKIP LOCKED`** — lock-free concurrency for multi-worker item selection.
- **Monitoring HTTP API** — `GET /stats`, `GET /stats/{jobName}`, `GET /failures` (opt-in via config).
- **Configurable retry** — `tries`, `range` (cooldown hours), automatic backlog processing per cycle.

### Changed

- **Query optimization** — replaced `whereHas('latestState')` correlated subqueries with indexed `distribution_current_state` column. N=100 job types: 401+ queries/cycle reduced to ~101.
- **Lock scope narrowed** — SELECT candidates without lock, then `FOR UPDATE` only on the selected batch rows.
- **Batch tries update** — single `INCREMENT` query replaces N individual updates.

## [0.1.0] - 2024-07-24

### Added

- Initial release.
- Core distribution model with `distributions` and `distribution_states` tables.
- State machine: `initial` → `pushed` → `processing` → `completed` / `failed`.
- `distribution:pushing` — one-shot push for a specific job type.
- `distribution:clean-up` — one-shot retry for failed items.
- `distribution:create-job` — scaffolding command to generate job + artisan command boilerplate.
- Priority-based ordering and `request_id` grouping.
- Soft deletes on both tables.
- Publishable config, migrations, and views.
- Laravel auto-discovery via `DistributionServiceProvider`.
- Horizon + RabbitMQ integration.

## [0.0.1] - 2024-07-24

- Project init.
