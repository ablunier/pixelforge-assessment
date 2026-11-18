<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Pixelforge's `evt_` id. Unique, so a redelivered event is never processed twice.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            // The raw body exactly as signed and received.
            $table->text('payload');
            $table->unsignedInteger('delivery_attempt')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->index('generation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
