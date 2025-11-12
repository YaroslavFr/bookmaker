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
        Schema::table('bets', function (Blueprint $table) {
            if (!Schema::hasColumn('bets', 'placed_odds')) {
                $table->decimal('placed_odds', 10, 2)->nullable()->after('selection');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            if (Schema::hasColumn('bets', 'placed_odds')) {
                $table->dropColumn('placed_odds');
            }
        });
    }
};