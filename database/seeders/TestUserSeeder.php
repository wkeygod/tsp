<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create test users for different scenarios

        // 1. User with access to only Kiosk (should auto-redirect)
        $kioskUser = User::firstOrCreate(
            ['email' => 'kiosk.user@example.com'],
            [
                'first_name' => 'Kiosque',
                'last_name' => 'User',
                'name' => 'Kiosque User',
                'password' => bcrypt('password123'),
                'is_admin' => false,
            ]
        );

        $kioskRole = Role::create([
            'name' => 'custom_role_' . $kioskUser->id,
            'description' => 'Kiosk only access',
        ]);

        $kioskPermission = Permission::where('page_slug', 'kiosk.index')->first();
        if ($kioskPermission) {
            $kioskRole->permissions()->attach($kioskPermission);
            $kioskUser->roles()->attach($kioskRole);
        }

        echo "✓ Created kiosk-only user: kiosk.user@example.com (password: password123)\n";

        // 2. User with access to multiple pages
        $limitedUser = User::firstOrCreate(
            ['email' => 'limited.user@example.com'],
            [
                'first_name' => 'Limited',
                'last_name' => 'User',
                'name' => 'Limited User',
                'password' => bcrypt('password123'),
                'is_admin' => false,
            ]
        );

        $limitedRole = Role::create([
            'name' => 'custom_role_' . $limitedUser->id,
            'description' => 'Limited access',
        ]);

        // Grant access to dashboard, meal-logs, and employee-history
        $permissions = Permission::whereIn('page_slug', [
            'dashboard.index',
            'meal-logs.index',
            'employee-history.index',
        ])->pluck('id');

        $limitedRole->permissions()->attach($permissions);
        $limitedUser->roles()->attach($limitedRole);

        echo "✓ Created limited user: limited.user@example.com (password: password123)\n";
        echo "  Access to: Dashboard, Historique global repas, Historique employes\n";

        // 3. User with nearly full access (everything except kiosk)
        $powerUser = User::firstOrCreate(
            ['email' => 'power.user@example.com'],
            [
                'first_name' => 'Power',
                'last_name' => 'User',
                'name' => 'Power User',
                'password' => bcrypt('password123'),
                'is_admin' => false,
            ]
        );

        $powerRole = Role::create([
            'name' => 'custom_role_' . $powerUser->id,
            'description' => 'Power user access',
        ]);

        // Grant access to everything except kiosk
        $permissions = Permission::where('page_slug', '!=', 'kiosk.index')->pluck('id');
        $powerRole->permissions()->attach($permissions);
        $powerUser->roles()->attach($powerRole);

        echo "✓ Created power user: power.user@example.com (password: password123)\n";
        echo "  Access to: All pages except Kiosk\n";
    }
}
