<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['node', 'collector'] as $source) {
            if (!Schema::hasColumn('flowscope_machine_addresses', $source.'_ipv4')) {
                Schema::table('flowscope_machine_addresses', function (Blueprint $table) use ($source): void {
                    $table->string($source.'_ipv4', 15)->nullable();
                    $table->unsignedBigInteger($source.'_updated_at')->nullable();
                });
            }
        }
        DB::table('flowscope_machine_addresses')->whereNull('collector_ipv4')->whereNull('node_ipv4')->update([
            'collector_ipv4' => DB::raw('ipv4'), 'collector_updated_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        // Preserve selected ipv4/updated_at for a source-only rollback to 2.3.0.
        Schema::table('flowscope_machine_addresses', fn (Blueprint $table) => $table->dropColumn([
            'node_ipv4', 'node_updated_at', 'collector_ipv4', 'collector_updated_at',
        ]));
    }
};
