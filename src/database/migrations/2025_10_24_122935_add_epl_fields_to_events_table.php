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
        Schema::table('events', function (Blueprint $table) {
            $table->string('home_team')->nullable()->after('title');
            $table->string('away_team')->nullable()->after('home_team');
            $table->decimal('home_odds', 8, 2)->nullable()->after('away_team');
            $table->decimal('draw_odds', 8, 2)->nullable()->after('home_odds');
            $table->decimal('away_odds', 8, 2)->nullable()->after('draw_odds');
            $table->index(['home_team', 'away_team']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['home_team', 'away_team']);
            $table->dropColumn(['home_team', 'away_team', 'home_odds', 'draw_odds', 'away_odds']);
        });
    }
};
