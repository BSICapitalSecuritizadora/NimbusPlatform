<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $row) {
            $row->id();
            $row->string('title');
            $row->string('slug')->unique();
            $row->string('department')->nullable();
            $row->string('location')->default('São Paulo, SP');
            $row->string('type')->default('CLT'); // CLT, PJ, Estágio
            $row->text('description');
            $row->text('requirements')->nullable();
            $row->text('benefits')->nullable();
            $row->boolean('is_active')->default(true);
            $row->timestamps();
        });

        Schema::create('job_applications', function (Blueprint $row) {
            $row->id();
            $row->foreignId('vacancy_id')->constrained('vacancies')->onDelete('cascade');
            $row->string('name');
            $row->string('email');
            $row->string('phone');
            $row->string('linkedin_url')->nullable();
            $row->string('resume_path');
            $row->text('message')->nullable();
            $row->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('vacancies');
    }
};
