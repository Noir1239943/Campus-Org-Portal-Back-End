<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            // Seminar | Workshop | Meeting | Assembly | Outreach | Competition | Social
            $table->string('type', 30)->default('Seminar');
            $table->text('description')->nullable();
            $table->string('venue');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('capacity')->nullable();
            // open = any student can join, members = organization members only
            $table->string('visibility', 20)->default('open');
            // Mandatory events count toward organization clearance.
            $table->boolean('is_mandatory')->default(false);
            $table->decimal('service_hours', 4, 1)->default(0);
            $table->string('speaker')->nullable();
            // draft | pending | published | rejected | cancelled
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('check_in_secret', 64)->nullable();
            $table->timestamp('check_in_opened_at')->nullable();
            $table->timestamp('check_in_closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // present | late | excused | absent
            $table->string('status', 20)->default('present');
            // qr | code | manual | walk_in | excuse
            $table->string('method', 20)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('excuse_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // illness | class_conflict | family | emergency | work | other
            $table->string('reason', 30);
            $table->text('details');
            // pending | approved | rejected
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excuse_requests');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
