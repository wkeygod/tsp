<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'Tableau de bord',
                'page_slug' => 'dashboard.index',
                'description' => 'Accès au tableau de bord principal'
            ],
            [
                'name' => 'Gestion des employés',
                'page_slug' => 'employees.index',
                'description' => 'Accès à la gestion des employés'
            ],
            [
                'name' => 'Historique des employés',
                'page_slug' => 'employee-history.index',
                'description' => 'Accès à l\'historique des employés'
            ],
            [
                'name' => 'Historique global des repas',
                'page_slug' => 'meal-logs.index',
                'description' => 'Accès à l\'historique global des repas'
            ],
            [
                'name' => 'Gestion des règles de repas',
                'page_slug' => 'meal-rules.index',
                'description' => 'Accès à la gestion des règles de repas'
            ],
            [
                'name' => 'La Relève',
                'page_slug' => 'la-releve.index',
                'description' => 'Accès à la gestion de la relève'
            ],
            [
                'name' => 'Extra',
                'page_slug' => 'extra.index',
                'description' => 'Accès à la gestion des extras'
            ],
            [
                'name' => 'Rapports',
                'page_slug' => 'reports.index',
                'description' => 'Accès aux rapports'
            ],
            [
                'name' => 'Kiosque',
                'page_slug' => 'kiosk.index',
                'description' => 'Accès au kiosque'
            ],
            [
                'name' => 'Catalogue système',
                'page_slug' => 'system-catalog.index',
                'description' => 'Accès au catalogue système'
            ],
            [
                'name' => 'Départements',
                'page_slug' => 'departments.index',
                'description' => 'Accès à la gestion des départements'
            ],
            [
                'name' => 'Appareils',
                'page_slug' => 'devices.index',
                'description' => 'Accès à la gestion des appareils (bornes de pointage)'
            ],
            [
                'name' => 'Gestion des rôles',
                'page_slug' => 'roles.index',
                'description' => 'Accès à la gestion des rôles et utilisateurs'
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['page_slug' => $permission['page_slug']],
                $permission
            );
        }
    }
}
