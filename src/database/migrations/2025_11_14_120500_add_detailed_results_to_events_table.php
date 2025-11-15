<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'home_result')) {
                $table->unsignedInteger('home_result')->nullable();
            }
            if (!Schema::hasColumn('events', 'away_result')) {
                $table->unsignedInteger('away_result')->nullable();
            }
            if (!Schema::hasColumn('events', 'home_ht_result')) {
                $table->unsignedInteger('home_ht_result')->nullable();
            }
            if (!Schema::hasColumn('events', 'away_ht_result')) {
                $table->unsignedInteger('away_ht_result')->nullable();
            }
            if (!Schema::hasColumn('events', 'home_st2_result')) {
                $table->unsignedInteger('home_st2_result')->nullable();
            }
            if (!Schema::hasColumn('events', 'away_st2_result')) {
                $table->unsignedInteger('away_st2_result')->nullable();
            }
            if (!Schema::hasColumn('events', 'result_text')) {
                $table->string('result_text')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            foreach ([
                'home_result','away_result','home_ht_result','away_ht_result','home_st2_result','away_st2_result','result_text'
            ] as $col) {
                if (Schema::hasColumn('events', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};