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
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('chunking_strategy');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('token_count');
            $table->vector('embedding', dimensions: 384)->nullable()->index();
            $table->timestamps();

            $table->fullText('content')->language('english');
            $table->unique(['document_id', 'chunking_strategy', 'chunk_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
