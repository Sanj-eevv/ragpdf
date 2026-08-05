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
        Schema::table('queries', function (Blueprint $table) {
            // Guards against a genuine race (two Horizon workers picking up
            // the same retried RunSingleRagasEvaluationJob concurrently)
            // creating two Query rows for the same evaluation unit. Doesn't
            // affect interactive chat queries — ragas_evaluation_run_id is
            // null there, and Postgres treats every null as distinct, so
            // they never collide with this constraint.
            $table->unique(
                ['ragas_evaluation_run_id', 'question', 'chunking_strategy', 'retrieval_algorithm', 'reranked'],
                'queries_ragas_unit_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropUnique('queries_ragas_unit_unique');
        });
    }
};
