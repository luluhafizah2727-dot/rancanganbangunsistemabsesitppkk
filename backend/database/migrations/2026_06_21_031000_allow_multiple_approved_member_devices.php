<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_devices')) {
            return;
        }

        $this->dropApprovedUniqueIndex();
    }

    public function down(): void
    {
        if (! Schema::hasTable('member_devices')) {
            return;
        }

        try {
            Schema::table('member_devices', function (Blueprint $table): void {
                $table->unique(['member_id', 'approved_key'], 'member_devices_one_approved_unique');
            });
        } catch (Throwable) {
            //
        }
    }

    private function dropApprovedUniqueIndex(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('alter table member_devices drop constraint if exists member_devices_one_approved_unique');
                DB::statement('drop index if exists member_devices_one_approved_unique');

                return;
            }

            Schema::table('member_devices', function (Blueprint $table): void {
                $table->dropUnique('member_devices_one_approved_unique');
            });
        } catch (Throwable) {
            //
        }
    }
};
