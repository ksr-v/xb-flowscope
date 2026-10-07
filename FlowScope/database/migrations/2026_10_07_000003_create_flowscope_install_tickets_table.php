<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flowscope_install_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id')->index();
            $table->char('ticket_hash', 64)->unique();
            $table->char('machine_token_hash', 64);
            $table->string('panel_url', 512);
            $table->unsignedBigInteger('expires_at')->index();
            $table->unsignedBigInteger('used_at')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('flowscope_install_tickets'); }
};
