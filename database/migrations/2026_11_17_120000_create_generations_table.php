<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->string('status');
            $table->bigInteger('max_price');

            // Pixelforge's rates, locked when it accepted the Generation: nanodollars per token.
            $table->bigInteger('input_text_token_rate')->nullable();
            $table->bigInteger('input_image_token_rate')->nullable();
            $table->bigInteger('output_token_rate')->nullable();
            $table->bigInteger('reasoning_token_rate')->nullable();

            // Token usage, once succeeded.
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();

            $table->bigInteger('provider_cost')->nullable();
            $table->bigInteger('price')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->text('provider_image_url')->nullable();
            $table->timestamp('provider_image_url_expires_at')->nullable();

            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        // Only a succeeded Generation has a Provider Cost and a Price, and it always has both.
        DB::statement(<<<'SQL'
            ALTER TABLE generations ADD CONSTRAINT generations_priced_only_when_succeeded
            CHECK ((status = 'succeeded') = (provider_cost IS NOT NULL AND price IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
