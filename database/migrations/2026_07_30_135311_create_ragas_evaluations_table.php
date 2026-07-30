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
        Schema::create('ragas_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained()->cascadeOnDelete();
            $table->float('context_precision')->nullable();
            $table->float('context_recall')->nullable();
            $table->float('faithfulness')->nullable();
            $table->float('answer_relevance')->nullable();
            $table->string('judge_model')->nullable();
            $table->json('raw_judge_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ragas_evaluations');
    }
};
