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
        Schema::table('ragas_evaluation_runs', function (Blueprint $table) {
            $table->string('batch_id')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ragas_evaluation_runs', function (Blueprint $table) {
            $table->dropColumn('batch_id');
        });
    }
};
