<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->dateTimeTz('proposed_check_in_at')->nullable();
            $table->dateTimeTz('proposed_check_out_at')->nullable();
            $table->string('other_label', 100)->nullable();
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->dateTimeTz('approved_check_in_at')->nullable();
            $table->dateTimeTz('approved_check_out_at')->nullable();
            $table->dateTimeTz('reviewed_at')->nullable();
            $table->dateTimeTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'status', 'date_from', 'date_to']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->foreignId('attendance_request_id')->nullable()->after('attendance_day_id')->constrained()->nullOnDelete();
            $table->index(['attendance_request_id', 'attendance_day_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $table) => $table->dropConstrainedForeignId('attendance_request_id'));
        Schema::dropIfExists('attendance_requests');
    }
};
