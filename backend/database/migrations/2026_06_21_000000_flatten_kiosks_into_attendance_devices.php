<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->json('ip_allowlist')->nullable();
            $table->string('credential_hash', 64)->nullable()->unique();
            $table->string('previous_credential_hash', 64)->nullable()->index();
            $table->dateTimeTz('previous_credential_expires_at')->nullable();
            $table->dateTimeTz('credential_rotated_at')->nullable();
            $table->dateTimeTz('credential_expires_at')->nullable();
            $table->string('fingerprint_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->json('screen')->nullable();
            $table->string('timezone')->nullable();
            $table->dateTimeTz('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTimeTz('last_seen_at')->nullable();
            $table->dateTimeTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_device_activation_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->dateTimeTz('expires_at');
            $table->dateTimeTz('used_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['attendance_device_id', 'expires_at'], 'device_codes_device_expires_idx');
        });

        $mapping = $this->copyLegacyDevices();
        $this->moveDeviceReferences($mapping);

        Schema::dropIfExists('kiosk_activation_codes');
        Schema::dropIfExists('kiosk_devices');
        Schema::dropIfExists('kiosks');
    }

    public function down(): void
    {
        throw new RuntimeException('Migrasi gawai tunggal tidak dapat dibatalkan tanpa memulihkan backup database.');
    }

    /** @return array<int, int> */
    private function copyLegacyDevices(): array
    {
        if (! Schema::hasTable('kiosks')) {
            return [];
        }

        $mapping = [];
        $kiosks = DB::table('kiosks')->orderBy('id')->get();
        foreach ($kiosks as $kiosk) {
            $devices = DB::table('kiosk_devices')->where('kiosk_id', $kiosk->id)->orderBy('id')->get();
            if ($devices->isEmpty()) {
                DB::table('attendance_devices')->insert([
                    'public_id' => $kiosk->public_id ?: (string) Str::ulid(),
                    'code' => $kiosk->code,
                    'name' => $kiosk->name,
                    'location' => $kiosk->location,
                    'status' => $kiosk->status === 'active' ? 'pending' : 'inactive',
                    'ip_allowlist' => $kiosk->ip_allowlist,
                    'created_at' => $kiosk->created_at,
                    'updated_at' => $kiosk->updated_at,
                ]);

                continue;
            }

            foreach ($devices as $index => $device) {
                $code = $index === 0 ? $kiosk->code : $kiosk->code.'-'.($index + 1);
                $newId = DB::table('attendance_devices')->insertGetId([
                    'public_id' => $device->public_id ?: (string) Str::ulid(),
                    'code' => $code,
                    'name' => $device->label ?: $kiosk->name,
                    'location' => $kiosk->location,
                    'status' => $device->status === 'active' && $kiosk->status === 'active' ? 'active' : $device->status,
                    'ip_allowlist' => $kiosk->ip_allowlist,
                    'credential_hash' => $device->credential_hash,
                    'previous_credential_hash' => $device->previous_credential_hash ?? null,
                    'previous_credential_expires_at' => $device->previous_credential_expires_at ?? null,
                    'credential_rotated_at' => $device->credential_rotated_at ?? null,
                    'credential_expires_at' => $device->credential_expires_at ?? null,
                    'fingerprint_hash' => $device->fingerprint_hash,
                    'user_agent' => $device->user_agent,
                    'last_ip' => $device->last_ip,
                    'screen' => $device->screen,
                    'timezone' => $device->timezone,
                    'activated_at' => $device->activated_at,
                    'activated_by' => $device->activated_by,
                    'last_seen_at' => $device->last_seen_at,
                    'revoked_at' => $device->revoked_at,
                    'revoked_by' => $device->revoked_by,
                    'created_at' => $device->created_at,
                    'updated_at' => $device->updated_at,
                ]);
                $mapping[(int) $device->id] = (int) $newId;
            }
        }

        return $mapping;
    }

    /** @param array<int, int> $mapping */
    private function moveDeviceReferences(array $mapping): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->foreignId('new_check_in_device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->foreignId('new_check_out_device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
        });
        Schema::table('attendance_scans', function (Blueprint $table): void {
            $table->foreignId('new_attendance_device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
        });

        foreach ($mapping as $oldId => $newId) {
            DB::table('attendances')->where('check_in_device_id', $oldId)->update(['new_check_in_device_id' => $newId]);
            DB::table('attendances')->where('check_out_device_id', $oldId)->update(['new_check_out_device_id' => $newId]);
            DB::table('attendance_scans')->where('kiosk_device_id', $oldId)->update(['new_attendance_device_id' => $newId]);
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('alter table attendances drop constraint if exists daily_attendances_check_in_device_id_foreign');
            DB::statement('alter table attendances drop constraint if exists daily_attendances_check_out_device_id_foreign');
            DB::statement('alter table attendances drop constraint if exists attendances_check_in_device_id_foreign');
            DB::statement('alter table attendances drop constraint if exists attendances_check_out_device_id_foreign');
            DB::statement('alter table attendance_scans drop constraint if exists daily_attendance_scans_kiosk_device_id_foreign');
            DB::statement('alter table attendance_scans drop constraint if exists attendance_scans_kiosk_device_id_foreign');
            Schema::table('attendances', function (Blueprint $table): void {
                $table->dropColumn(['check_in_device_id', 'check_out_device_id']);
            });
            Schema::table('attendance_scans', fn (Blueprint $table) => $table->dropColumn('kiosk_device_id'));
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->dropMysqlForeignKey('attendances', 'check_in_device_id');
            $this->dropMysqlForeignKey('attendances', 'check_out_device_id');
            $this->dropMysqlForeignKey('attendance_scans', 'kiosk_device_id');
            Schema::table('attendances', function (Blueprint $table): void {
                $table->dropColumn(['check_in_device_id', 'check_out_device_id']);
            });
            Schema::table('attendance_scans', fn (Blueprint $table) => $table->dropColumn('kiosk_device_id'));
        } else {
            Schema::table('attendances', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('check_in_device_id');
                $table->dropConstrainedForeignId('check_out_device_id');
            });
            Schema::table('attendance_scans', fn (Blueprint $table) => $table->dropConstrainedForeignId('kiosk_device_id'));
        }
        Schema::table('attendances', function (Blueprint $table): void {
            $table->renameColumn('new_check_in_device_id', 'check_in_device_id');
            $table->renameColumn('new_check_out_device_id', 'check_out_device_id');
        });
        Schema::table('attendance_scans', function (Blueprint $table): void {
            $table->renameColumn('new_attendance_device_id', 'attendance_device_id');
        });
    }

    private function dropMysqlForeignKey(string $table, string $column): void
    {
        $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->value('CONSTRAINT_NAME');

        if ($constraint) {
            DB::statement("alter table `{$table}` drop foreign key `{$constraint}`");
        }
    }
};
