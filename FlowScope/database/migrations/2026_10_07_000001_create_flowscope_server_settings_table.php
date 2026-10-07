<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flowscope_server_settings', function (Blueprint $table) {
            $table->id();
            $table->string('server_type', 32);
            $table->unsignedBigInteger('server_id');
            $table->boolean('enabled')->default(false)->index();
            $table->string('display_name', 120)->nullable();
            $table->unsignedBigInteger('quota_bytes');
            $table->string('traffic_mode', 8)->default('outbound');
            $table->unsignedTinyInteger('cycle_start_day')->default(1);
            $table->timestamps();
            $table->unique(['server_type', 'server_id'], 'flowscope_server_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowscope_server_settings');
    }
};
