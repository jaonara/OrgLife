<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('school_year', 9);
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->timestamps();
            $table->unique(['organization_id', 'school_year']);
        });

        Schema::create('dues_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_due_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index(['organization_due_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dues_payments');
        Schema::dropIfExists('organization_dues');
    }
};
