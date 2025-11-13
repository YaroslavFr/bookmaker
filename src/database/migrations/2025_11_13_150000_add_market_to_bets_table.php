<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            if (!Schema::hasColumn('bets', 'market')) {
                $table->string('market')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            if (Schema::hasColumn('bets', 'market')) {
                $table->dropColumn('market');
            }
        });
    }
};