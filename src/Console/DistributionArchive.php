<?php

namespace PLSys\DistrbutionQueue\Console;

use Illuminate\Console\Command;
use PLSys\DistrbutionQueue\App\Models\Sql\Distributions;
use PLSys\DistrbutionQueue\App\Models\Sql\DistributionStates;
use Carbon\Carbon;

class DistributionArchive extends Command
{
    protected $signature = 'distribution:archive
        {--days=7 : Archive states older than N days}
        {--purge : Also delete completed distributions entirely}
        {--dry-run : Show counts without deleting}';

    protected $description = 'Archive old distribution states to free up disk space';

    public function handle()
    {
        $days = (int) $this->option('days');
        $purge = $this->option('purge');
        $dryRun = $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);
        $timestamp = Carbon::now()->toDateTimeString();

        $this->info("[$timestamp] Archiving distribution states older than $days days...");

        // Find completed/failed distributions older than cutoff
        $baseQuery = Distributions::whereIn(
                Distributions::COL_DISTRIBUTION_CURRENT_STATE,
                [DistributionStates::DISTRIBUTION_STATES_COMPLETED, DistributionStates::DISTRIBUTION_STATES_FAILED]
            )
            ->where(Distributions::COL_DISTRIBUTION_UPDATED_AT, '<', $cutoff);

        $totalDists = (clone $baseQuery)->count();
        $this->info("  Found $totalDists completed/failed distributions older than $days days.");

        if ($totalDists === 0) {
            $this->info('  Nothing to archive.');
            return 0;
        }

        if ($dryRun) {
            $distIds = (clone $baseQuery)->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();

            $latestStateIds = DistributionStates::whereIn(DistributionStates::COL_FK_DISTRIBUTION_ID, $distIds)
                ->selectRaw('MAX(' . DistributionStates::COL_DISTRIBUTION_STATE_ID . ') as id')
                ->groupBy(DistributionStates::COL_FK_DISTRIBUTION_ID)
                ->pluck('id')
                ->toArray();

            $intermediateCount = DistributionStates::whereIn(DistributionStates::COL_FK_DISTRIBUTION_ID, $distIds)
                ->whereNotIn(DistributionStates::COL_DISTRIBUTION_STATE_ID, $latestStateIds)
                ->count();

            $this->info("  [DRY-RUN] Would delete $intermediateCount intermediate state records.");
            if ($purge) {
                $totalStates = DistributionStates::whereIn(DistributionStates::COL_FK_DISTRIBUTION_ID, $distIds)->count();
                $this->info("  [DRY-RUN] Would delete $totalDists distributions and $totalStates total state records.");
            }
            return 0;
        }

        // Process in chunks to avoid memory issues
        $deletedStates = 0;
        $deletedDists = 0;

        (clone $baseQuery)->chunkById(500, function ($distributions) use ($purge, &$deletedStates, &$deletedDists) {
            $distIds = $distributions->pluck(Distributions::COL_DISTRIBUTION_ID)->toArray();

            if ($purge) {
                // Delete ALL states for these distributions, then the distributions
                $deletedStates += DistributionStates::whereIn(
                    DistributionStates::COL_FK_DISTRIBUTION_ID, $distIds
                )->delete();

                $deletedDists += Distributions::whereIn(
                    Distributions::COL_DISTRIBUTION_ID, $distIds
                )->delete();
            } else {
                // Keep latest state per distribution, delete intermediate ones
                $latestStateIds = DistributionStates::whereIn(
                        DistributionStates::COL_FK_DISTRIBUTION_ID, $distIds
                    )
                    ->selectRaw('MAX(' . DistributionStates::COL_DISTRIBUTION_STATE_ID . ') as id')
                    ->groupBy(DistributionStates::COL_FK_DISTRIBUTION_ID)
                    ->pluck('id')
                    ->toArray();

                $deletedStates += DistributionStates::whereIn(
                        DistributionStates::COL_FK_DISTRIBUTION_ID, $distIds
                    )
                    ->whereNotIn(DistributionStates::COL_DISTRIBUTION_STATE_ID, $latestStateIds)
                    ->delete();
            }
        }, Distributions::COL_DISTRIBUTION_ID);

        $timestamp = Carbon::now()->toDateTimeString();
        $this->info("  Deleted $deletedStates state records.");
        if ($purge) {
            $this->info("  Deleted $deletedDists distribution records.");
        }
        $this->info("[$timestamp] Archive complete.");

        return 0;
    }
}
