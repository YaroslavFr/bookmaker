<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('bettor_name');
            $table->decimal('amount_demo', 10, 2);
            $table->decimal('total_odds', 10, 2)->nullable();
            $table->boolean('is_win')->nullable();
            $table->decimal('payout_demo', 12, 2)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};