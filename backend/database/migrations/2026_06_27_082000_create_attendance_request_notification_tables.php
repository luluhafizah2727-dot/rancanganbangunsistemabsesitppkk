<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION_TOKENS_REQUEST_USER_INDEX = 'ar_action_tokens_request_user_idx';

    public function up(): void
    {
        if (! Schema::hasTable('attendance_request_reviewers')) {
            Schema::create('attendance_request_reviewers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTimeTz('enabled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attendance_request_action_tokens')) {
            Schema::create('attendance_request_action_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->string('code_hash');
                $table->dateTimeTz('expires_at')->index();
                $table->dateTimeTz('used_at')->nullable();
                $table->string('used_action', 20)->nullable();
                $table->string('used_ip', 45)->nullable();
                $table->text('used_user_agent')->nullable();
                $table->timestamps();
                $table->index(['attendance_request_id', 'user_id'], self::ACTION_TOKENS_REQUEST_USER_INDEX);
            });
        } elseif (! $this->indexExists('attendance_request_action_tokens', self::ACTION_TOKENS_REQUEST_USER_INDEX)) {
            Schema::table('attendance_request_action_tokens', function (Blueprint $table): void {
                $table->index(['attendance_request_id', 'user_id'], self::ACTION_TOKENS_REQUEST_USER_INDEX);
            });
        }

        if (! Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attendance_request_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 80)->index();
                $table->string('channel', 30)->default('whatsapp')->index();
                $table->string('destination', 40)->nullable();
                $table->string('status', 20)->default('queued')->index();
                $table->json('payload')->nullable();
                $table->json('response')->nullable();
                $table->text('error')->nullable();
                $table->dateTimeTz('sent_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('attendance_request_action_tokens');
        Schema::dropIfExists('attendance_request_reviewers');
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? limit 1',
            [$table, $index],
        ) !== null;
    }
};
