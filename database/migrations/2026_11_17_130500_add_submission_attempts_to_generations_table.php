<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Create requests counted against the retry budget (see App\Jobs\SubmitGenerationToPixelforge).
            $table->unsignedSmallInteger('submission_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('submission_attempts');
        });
    }
};
