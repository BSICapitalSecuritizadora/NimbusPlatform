<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AccessPermission::values() as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'super-admin'])
            ->syncPermissions(AccessPermission::values());

        Role::firstOrCreate(['name' => 'admin'])
            ->syncPermissions(AccessPermission::values());

        Role::firstOrCreate(['name' => 'editor'])
            ->syncPermissions([
                'investors.view',
                'investors.create',
                'investors.update',
                'emissions.view',
                'emissions.create',
                'emissions.update',
                'expenses.view',
                'expenses.create',
                'expenses.update',
                'funds.view',
                'funds.create',
                'funds.update',
                'documents.view',
                'documents.create',
                'documents.update',
                'constructions.view',
                'constructions.create',
                'constructions.update',
                'sales-boards.view',
                'sales-boards.create',
                'sales-boards.update',
            ]);

        Role::firstOrCreate(['name' => 'commercial-representative'])
            ->syncPermissions([
                'proposals.view',
                'proposals.update',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', array_diff(AccessPermission::values(), [
                'investors.view',
                'investors.create',
                'investors.update',
                'investors.delete',
                'emissions.view',
                'emissions.create',
                'emissions.update',
                'emissions.delete',
                'funds.view',
                'funds.create',
                'funds.update',
                'funds.delete',
                'documents.view',
                'documents.create',
                'documents.update',
                'documents.delete',
                'proposals.view',
                'proposals.update',
            ]))
            ->delete();
    }
};
