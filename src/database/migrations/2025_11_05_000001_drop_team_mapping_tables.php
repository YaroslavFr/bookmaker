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
        // Удаляем таблицы, связанные с сопоставлением названий команд, если существуют
        Schema::dropIfExists('team_aliases');
        Schema::dropIfExists('teams');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем минимальные таблицы для отката (пустые структуры)
        if (!Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('team_aliases')) {
            Schema::create('team_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('alias');
                $table->string('team_name')->nullable();
                $table->timestamps();
            });
        }
    }
};