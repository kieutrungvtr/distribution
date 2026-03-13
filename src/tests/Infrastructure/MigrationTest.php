<?php

namespace PLSys\DistrbutionQueue\Tests\Infrastructure;

use PLSys\DistrbutionQueue\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationTest extends TestCase
{
    public function test_migration_creates_distributions_table()
    {
        $this->assertTrue(Schema::hasTable('distributions'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_id'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_request_id'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_payload'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_job_name'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_tries'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_priority'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_current_state'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_created_by'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_created_at'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_updated_at'));
        $this->assertTrue(Schema::hasColumn('distributions', 'distribution_deleted_at'));
    }

    public function test_migration_creates_distribution_states_table()
    {
        $this->assertTrue(Schema::hasTable('distribution_states'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'distribution_state_id'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'fk_distribution_id'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'distribution_state_value'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'distribution_state_log'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'distribution_state_exception'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'distribution_state_created_at'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'distribution_state_updated_at'));
        $this->assertTrue(Schema::hasColumn('distribution_states', 'distribution_state_deleted_at'));
    }

    public function test_migration_down_drops_correct_order()
    {
        // The migration file should drop distribution_states before distributions
        $migrationFile = file_get_contents(__DIR__ . '/../../database/migrations/2024_07_20_073616_create_distribution_table.php');

        $statesPos = strpos($migrationFile, "dropIfExists('distribution_states')");
        $distPos = strpos($migrationFile, "dropIfExists('distributions')");

        $this->assertNotFalse($statesPos);
        $this->assertNotFalse($distPos);
        // distribution_states should be dropped before distributions
        $this->assertLessThan($distPos, $statesPos);
    }
}
