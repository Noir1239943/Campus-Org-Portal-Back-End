<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('acronym', 12);
            // Academic | Governance | Arts & Culture | Service | Sports | Media
            $table->string('category', 40)->index();
            $table->string('tagline')->nullable();
            $table->text('description');
            $table->text('about')->nullable();
            $table->string('color', 9)->default('#1d4ed8');
            $table->string('adviser_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('meeting_schedule')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('accepting_members')->default(true);
            $table->unsignedInteger('capacity')->nullable();
            $table->date('application_deadline')->nullable();
            $table->string('application_question')->nullable();
            // e.g. the student government: every enrolled student is a member.
            $table->boolean('requires_all_students')->default(false);
            // active | probation | inactive   (set by the Office of Student Affairs)
            $table->string('status', 20)->default('active');
            $table->date('recognized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('position', 60)->default('Member');
            $table->boolean('is_officer')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'organization_id']);
        });

        Schema::create('membership_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // pending | approved | rejected | withdrawn
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->text('experience')->nullable();
            $table->string('availability')->nullable();
            $table->text('answer')->nullable();
            $table->timestamp('eligibility_verified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('saved_organizations', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_organizations');
        Schema::dropIfExists('membership_applications');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('organizations');
    }
};
