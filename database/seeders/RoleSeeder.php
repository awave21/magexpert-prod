<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'description' => 'Администратор системы с полным доступом',
            ],
            [
                'name' => 'editor',
                'description' => 'Редактор с правами на создание и редактирование контента',
            ],
            [
                'name' => 'manager',
                'description' => 'Менеджер с правами на управление пользователями',
            ],
            [
                'name' => 'user',
                'description' => 'Обычный пользователь с базовыми правами',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
