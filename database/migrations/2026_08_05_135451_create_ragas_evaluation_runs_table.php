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
        Schema::create('ragas_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->json('summary')->nullable();
            $table->string('csv_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ragas_evaluation_runs');
    }
};
