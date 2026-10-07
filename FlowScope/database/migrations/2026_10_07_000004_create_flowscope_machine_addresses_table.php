<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flowscope_machine_addresses', function (Blueprint $table): void {
            $table->unsignedBigInteger('machine_id')->primary();
            $table->string('ipv4', 15);
            $table->unsignedBigInteger('updated_at');
        });
    }
    public function down(): void { Schema::dropIfExists('flowscope_machine_addresses'); }
};
