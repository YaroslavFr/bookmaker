<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'external_id')) {
                $table->string('external_id')->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'external_id')) {
                // Some DBs require dropping index first; try both where applicable
                try { $table->dropUnique(['external_id']); } catch (\Throwable $e) {}
                try { $table->dropColumn('external_id'); } catch (\Throwable $e) {}
            }
        });
    }
};