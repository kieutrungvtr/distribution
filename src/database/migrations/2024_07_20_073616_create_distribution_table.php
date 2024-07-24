<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('distributions', function (Blueprint $table) {
            $table->bigIncrements('distribution_id');
            $table->unsignedInteger('distribution_request_id');
            $table->longText('distribution_payload');
            $table->text('distribution_job_name');
            $table->tinyInteger('distribution_tries')->default(0);
            $table->unsignedTinyInteger('distribution_priority')->default(0);
            $table->unsignedInteger('distribution_created_by')->default(0);
            $table->timestamp('distribution_created_at');
            $table->timestamp('distribution_updated_at')->useCurrent();
        });

        Schema::create('distribution_states', function (Blueprint $table) {
            $table->bigIncrements('distribution_state_id');
            $table->unsignedBigInteger('fk_distribution_id');
            $table->enum('distribution_state_value', ['initial', 'pushed', 'processing', 'failed', 'completed']);
            $table->text('distribution_state_log')->nullable();
            $table->longText('distribution_state_exception')->nullable();
            $table->timestamp('distribution_state_created_at');
            $table->timestamp('distribution_state_updated_at')->useCurrent();

            $table->foreign('fk_distribution_id')->references('distribution_id')->on('distributions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributions');
        Schema::dropIfExists('distribution_states');
    }
};
