<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting an account soft-deletes the user, keeping their Generations
     * and ledger (ADR-0002). The database refuses a hard delete of a user or
     * Generation that still has Generations or ledger entries, so nothing can
     * cascade away the ledger.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['generation_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('generation_id')->references('id')->on('generations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['generation_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('generation_id')->references('id')->on('generations')->cascadeOnDelete();
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
