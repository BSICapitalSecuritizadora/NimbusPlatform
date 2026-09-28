<?php

namespace Database\Seeders;

use App\Enums\AccessPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MeasurementFinancialRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([AccessPermission::MeasurementsFinancialRulesView, AccessPermission::MeasurementsFinancialRulesManage] as $access) {
            $permission = Permission::firstOrCreate(['name' => $access->value, 'guard_name' => 'web']);
            Role::query()->whereIn('name', ['admin', 'super-admin'])->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
