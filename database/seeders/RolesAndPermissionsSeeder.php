<?php

namespace Database\Seeders;

use App\Enums\AccessPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = AccessPermission::values();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $editor = Role::firstOrCreate(['name' => 'editor']);
        $commercialRepresentative = Role::firstOrCreate(['name' => 'commercial-representative']);

        $superAdmin->syncPermissions($permissions);
        $admin->syncPermissions($permissions);

        $editor->syncPermissions([
            'investors.view', 'investors.create', 'investors.update',
            'emissions.view', 'emissions.create', 'emissions.update',
            'expenses.view', 'expenses.create', 'expenses.update',
            'funds.view', 'funds.create', 'funds.update',
            'obligations.view', 'obligations.create', 'obligations.update',
            'obligations.generate', 'obligations.review_suggestions',
            'obligations.approve_suggestion', 'obligations.reject_suggestion',
            'obligations.view_dashboard',
            'obligations.submit_for_review', 'obligations.complete',
            'obligations.mark_not_applicable', 'obligations.reopen',
            'obligations.upload_evidence', 'obligations.view_evidence',
            'obligations.download_evidence',
            'obligations.approve_evidence', 'obligations.reject_evidence',
            'obligations.view_history', 'obligations.view_comments',
            'obligations.create_comment', 'obligations.update_comment',
            'obligations.delete_comment',
            'documents.view', 'documents.create', 'documents.update',
            'constructions.view', 'constructions.create', 'constructions.update',
            'sales-boards.view', 'sales-boards.create', 'sales-boards.update',
            'receivables.view', 'receivables.create', 'receivables.update',
            'negotiations.view', 'negotiations.create', 'negotiations.update',
            'operations.view', 'operations.create', 'operations.update',
            'measurements.view', 'measurements.create', 'measurements.update',
            'measurements.review', 'measurements.pay', 'measurements.finalize',
            'pu.curve.view', 'pu.parameters.configure', 'pu.curve.generate',
            'pu.curve.validate', 'pu.curve.export', 'pu.curve.reprocess',
            'pu.dashboard.view', 'pu.index.import', 'pu.index.sync',
        ]);

        $commercialRepresentative->syncPermissions([
            'proposals.view',
            'proposals.update',
        ]);
    }
}
