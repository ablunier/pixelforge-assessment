<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // A create request got no definite answer (lost response or unexplained 5xx),
            // so Pixelforge may hold this Generation (see ADR-0005).
            $table->boolean('submission_outcome_unknown')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('submission_outcome_unknown');
        });
    }
};
