<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('gcash_number', 20)->nullable();
            $table->string('gcash_qr_path')->nullable();
        });

        Schema::table('dues_payments', function (Blueprint $table) {
            $table->string('status', 20)->default('approved');
            $table->string('reference_last_four', 4)->nullable();
            $table->string('proof_path')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->index(['organization_due_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('dues_payments', function (Blueprint $table) {
            $table->dropIndex(['organization_due_id', 'status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'status',
                'reference_last_four',
                'proof_path',
                'reviewed_at',
                'review_note',
            ]);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['gcash_number', 'gcash_qr_path']);
        });
    }
};
