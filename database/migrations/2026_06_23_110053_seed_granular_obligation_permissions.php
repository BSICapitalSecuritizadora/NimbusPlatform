<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = [
        'obligations.generate',
        'obligations.view_dashboard',
        'obligations.submit_for_review',
        'obligations.complete',
        'obligations.mark_not_applicable',
        'obligations.reopen',
        'obligations.upload_evidence',
        'obligations.view_evidence',
        'obligations.download_evidence',
        'obligations.delete_evidence',
        'obligations.view_history',
        'obligations.send_notifications',
        'obligations.export',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'super-admin'])->givePermissionTo(self::PERMISSIONS);
        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo(self::PERMISSIONS);
        Role::firstOrCreate(['name' => 'editor'])->givePermissionTo([
            'obligations.generate',
            'obligations.view_dashboard',
            'obligations.submit_for_review',
            'obligations.complete',
            'obligations.mark_not_applicable',
            'obligations.reopen',
            'obligations.upload_evidence',
            'obligations.view_evidence',
            'obligations.download_evidence',
            'obligations.view_history',
        ]);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', self::PERMISSIONS)
            ->delete();
    }
};
