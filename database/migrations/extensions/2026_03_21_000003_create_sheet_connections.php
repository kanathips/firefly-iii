<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the Google Sheets extension tables:
 *   extension_sheet_connections  – one row per user/sheet OAuth2 connection
 *   extension_sheet_sync_logs    – one row per sync run
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('extension_sheet_connections', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('google_token');             // JSON, encrypted
            $table->string('sheet_id', 255)->default('');
            $table->text('field_map');                // JSON column mapping
            $table->string('direction', 32)->default('import'); // import | export | bidirectional
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'is_active'], 'idx_sheet_connections_user_active');
        });

        Schema::create('extension_sheet_sync_logs', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sheet_connection_id');
            $table->string('status', 32)->default('in_progress'); // in_progress | completed | failed
            $table->unsignedInteger('records_in')->default(0);
            $table->unsignedInteger('records_out')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('sheet_connection_id')
                ->references('id')
                ->on('extension_sheet_connections')
                ->onDelete('cascade');

            $table->index(['sheet_connection_id', 'status'], 'idx_sheet_sync_logs_conn_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_sheet_sync_logs');
        Schema::dropIfExists('extension_sheet_connections');
    }
};
