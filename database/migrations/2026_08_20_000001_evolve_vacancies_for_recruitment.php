<?php

use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->string('status')->default('draft')->after('is_active');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->timestamp('expires_at')->nullable()->after('published_at');
            $table->timestamp('closed_at')->nullable()->after('expires_at');
            $table->unsignedInteger('positions')->default(1)->after('closed_at');
            $table->foreignId('hiring_manager_id')->nullable()->after('positions')->constrained('users')->nullOnDelete();
            $table->string('work_model')->nullable()->after('hiring_manager_id');
            $table->unsignedInteger('salary_min')->nullable()->after('work_model');
            $table->unsignedInteger('salary_max')->nullable()->after('salary_min');
            $table->boolean('salary_visible')->default(false)->after('salary_max');
            $table->text('internal_notes')->nullable()->after('benefits');
        });

        // Backfill status from legacy is_active
        DB::table('vacancies')->where('is_active', true)->update(['status' => 'published']);
        DB::table('vacancies')->where('is_active', false)->update(['status' => 'paused']);

        // Normalize legacy type/department to canonical enum values where possible (case-insensitive)
        // Department normalization
        $departments = DB::table('vacancies')->select('id', 'department')->get();
        foreach ($departments as $row) {
            if (blank($row->department)) {
                continue;
            }

            $normalized = VacancyDepartment::normalize($row->department);
            if ($normalized && $normalized->value !== $row->department) {
                DB::table('vacancies')->where('id', $row->id)->update(['department' => $normalized->value]);
            }
        }

        // Employment type normalization
        $vacancies = DB::table('vacancies')->select('id', 'type')->get();
        foreach ($vacancies as $row) {
            if (blank($row->type)) {
                continue;
            }

            $normalized = VacancyEmploymentType::normalize($row->type);
            if ($normalized && $normalized->value !== $row->type) {
                DB::table('vacancies')->where('id', $row->id)->update(['type' => $normalized->value]);
            }
        }

        // Backfill published_at for previously active vacancies
        DB::table('vacancies')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('created_at')]);

        Schema::table('vacancies', function (Blueprint $table): void {
            $table->index('status', 'vacancies_status_index');
            $table->index('department', 'vacancies_department_index');
            $table->index('type', 'vacancies_type_index');
            $table->index('work_model', 'vacancies_work_model_index');
            $table->index('expires_at', 'vacancies_expires_at_index');
            $table->index('hiring_manager_id', 'vacancies_hiring_manager_id_index');
        });

        Schema::table('job_applications', function (Blueprint $table): void {
            $table->index('vacancy_id', 'job_applications_vacancy_id_index');
            $table->index('status', 'job_applications_status_index');
            $table->index('created_at', 'job_applications_created_at_index');
            $table->index('email', 'job_applications_email_index');
            $table->index(['vacancy_id', 'status'], 'job_applications_vacancy_status_index');
            $table->index(['vacancy_id', 'email', 'created_at'], 'job_applications_vacancy_email_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropIndex('job_applications_vacancy_id_index');
            $table->dropIndex('job_applications_status_index');
            $table->dropIndex('job_applications_created_at_index');
            $table->dropIndex('job_applications_email_index');
            $table->dropIndex('job_applications_vacancy_status_index');
            $table->dropIndex('job_applications_vacancy_email_created_index');
        });

        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropIndex('vacancies_status_index');
            $table->dropIndex('vacancies_department_index');
            $table->dropIndex('vacancies_type_index');
            $table->dropIndex('vacancies_work_model_index');
            $table->dropIndex('vacancies_expires_at_index');
            $table->dropIndex('vacancies_hiring_manager_id_index');

            $table->dropConstrainedForeignId('hiring_manager_id');
            $table->dropColumn([
                'status',
                'published_at',
                'expires_at',
                'closed_at',
                'positions',
                'work_model',
                'salary_min',
                'salary_max',
                'salary_visible',
                'internal_notes',
            ]);
        });
    }
};
