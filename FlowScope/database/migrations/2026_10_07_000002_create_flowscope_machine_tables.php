<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Deliberately independent of legacy node settings and native tables.
        Schema::create('flowscope_machine_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id')->unique();
            $table->boolean('enabled')->default(false)->index();
            $table->string('display_name', 120)->nullable();
            $table->unsignedBigInteger('quota_bytes');
            $table->string('traffic_mode', 8)->default('outbound');
            $table->unsignedTinyInteger('cycle_start_day')->default(1);
            $table->timestamps();
        });
        Schema::create('flowscope_collector_cursors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id')->unique();
            $table->string('collector_id', 32);
            $table->string('sequence', 19);
            $table->string('last_digest', 64);
            $table->unsignedBigInteger('first_sample_at');
            $table->unsignedBigInteger('last_sample_at');
            $table->unsignedBigInteger('last_report_at');
            $table->string('boot_id', 36);
            $table->json('interfaces');
            $table->unsignedBigInteger('resets')->default(0);
            $table->unsignedBigInteger('boot_changes')->default(0);
            $table->timestamps();
        });
        Schema::create('flowscope_machine_daily', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('record_at');
            $table->string('timezone', 64);
            $table->string('rx_bytes', 40)->default('0');
            $table->string('tx_bytes', 40)->default('0');
            $table->timestamps();
            $table->unique(['machine_id', 'record_at'], 'flowscope_machine_daily_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowscope_machine_daily');
        Schema::dropIfExists('flowscope_collector_cursors');
        Schema::dropIfExists('flowscope_machine_settings');
    }
};
