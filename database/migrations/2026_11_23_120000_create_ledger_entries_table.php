<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The append-only ledger of Top-ups, Holds, Hold releases and Charges
     * (ADR-0002). Rows are never updated or deleted; the Balance is derived
     * from them.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            // Nanodollars (ADR-0001), always positive: the kind says which way it moves.
            $table->bigInteger('amount');
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index('generation_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_known
            CHECK (kind IN ('top_up', 'hold', 'hold_release', 'charge'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_positive
            CHECK (amount > 0)
            SQL);

        // A Top-up belongs to no Generation; every other entry belongs to one.
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_generation_unless_top_up
            CHECK ((kind = 'top_up') = (generation_id IS NULL))
            SQL);

        // At most one Charge and one Hold release per Generation: the database
        // refuses to record money twice, whatever the application does.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_entries_one_charge_or_release_per_generation
            ON ledger_entries (generation_id, kind)
            WHERE kind IN ('charge', 'hold_release')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
