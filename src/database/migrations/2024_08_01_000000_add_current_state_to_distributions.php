<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributions', function (Blueprint $table) {
            $table->enum('distribution_current_state', ['initial', 'pushed', 'processing', 'failed', 'completed'])
                  ->default('initial')
                  ->after('distribution_priority');
        });

        // Backfill from latest state (cross-DB compatible)
        DB::table('distributions')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('distribution_states')
                    ->whereColumn('distribution_states.fk_distribution_id', 'distributions.distribution_id');
            })
            ->update([
                'distribution_current_state' => DB::raw('(
                    SELECT ds.distribution_state_value FROM distribution_states ds
                    WHERE ds.fk_distribution_id = distributions.distribution_id
                    ORDER BY ds.distribution_state_id DESC LIMIT 1
                )'),
            ]);

        // Composite index — MySQL needs prefix length for TEXT column, SQLite does not
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('CREATE INDEX idx_dist_current_state_job ON distributions(distribution_current_state, distribution_job_name)');
        } else {
            DB::statement('CREATE INDEX idx_dist_current_state_job ON distributions(distribution_current_state, distribution_job_name(255))');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS idx_dist_current_state_job');
        } else {
            DB::statement('DROP INDEX idx_dist_current_state_job ON distributions');
        }
        Schema::table('distributions', function (Blueprint $table) {
            $table->dropColumn('distribution_current_state');
        });
    }
};
