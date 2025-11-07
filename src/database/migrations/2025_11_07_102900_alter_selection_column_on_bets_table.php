<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Меняем ENUM('home','draw','away') на строку для поддержки доп. рынков (например, "Home -2").
        DB::statement("ALTER TABLE `bets` MODIFY `selection` VARCHAR(64) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Возврат к исходному ENUM, если необходимо откатить изменения.
        DB::statement("ALTER TABLE `bets` MODIFY `selection` ENUM('home','draw','away') NOT NULL");
    }
};