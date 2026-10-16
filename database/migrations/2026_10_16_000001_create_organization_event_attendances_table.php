<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_event_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_event_id')->constrained('organization_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('rsvp_at')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_event_id', 'user_id'], 'org_event_user_unique');
            $table->index(['organization_event_id', 'attended_at'], 'org_event_attended_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_event_attendances');
    }
};
