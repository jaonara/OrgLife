<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_events', function (Blueprint $table) {
            $table->unsignedInteger('seat_limit')->nullable()->after('starts_at');
            $table->timestamp('cancelled_at')->nullable()->after('seat_limit');
        });

        Schema::create('organization_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('category', 100);
            $table->string('college', 32);
            $table->string('cause', 255);
            $table->text('mission');
            $table->string('status', 20)->default('draft');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_proposals');
        Schema::table('organization_events', function (Blueprint $table) {
            $table->dropColumn(['seat_limit', 'cancelled_at']);
        });
    }
};
