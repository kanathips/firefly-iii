<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the two extension investment tables:
 *   investment_positions  – one position per user per security (linked to an asset Account)
 *   investment_snapshots  – price history snapshots for each position
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('investment_positions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->string('symbol', 20);
            $table->decimal('quantity', 18, 8)->default('0.00000000');
            $table->decimal('avg_cost', 18, 8)->default('0.00000000');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['user_id', 'symbol'], 'idx_investment_positions_user_symbol');
        });

        Schema::create('investment_snapshots', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('position_id');
            $table->decimal('current_price', 18, 8)->default('0.00000000');
            $table->date('snapshot_date');
            $table->timestamps();

            $table->foreign('position_id')->references('id')->on('investment_positions')->onDelete('cascade');
            $table->index(['position_id', 'snapshot_date'], 'idx_investment_snapshots_position_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_snapshots');
        Schema::dropIfExists('investment_positions');
    }
};
