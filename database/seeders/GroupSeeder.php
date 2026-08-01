<?php

namespace Database\Seeders;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Seeder;

class GroupSeeder extends Seeder
{
    /**
     * Seed demo groups with realistic membership for the Groups page and sharing workflows.
     */
    public function run(): void
    {
        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();

        // ── Équipe Finance ──────────────────────────────────────────────────
        $financeGroup = Group::query()->firstOrCreate(
            ['name' => 'Équipe Finance'],
            ['owner_id' => $admin->id, 'description' => 'Contrôleurs et analystes financiers.'],
        );

        $financeGroup->members()->syncWithoutDetaching([
            $admin->id => ['role' => GroupRole::OWNER->value],
            $finance->id => ['role' => GroupRole::MANAGER->value],
            $analyst->id => ['role' => GroupRole::MEMBER->value],
        ]);

        // ── Analystes Oracle ────────────────────────────────────────────────
        $analyticsGroup = Group::query()->firstOrCreate(
            ['name' => 'Analystes Oracle'],
            ['owner_id' => $analyst->id, 'description' => 'Experts des API Fusion pour les analyses transversales.'],
        );

        $analyticsGroup->members()->syncWithoutDetaching([
            $analyst->id => ['role' => GroupRole::OWNER->value],
            $admin->id => ['role' => GroupRole::MANAGER->value],
        ]);

        // ── Administrateurs plateforme ──────────────────────────────────────
        $adminGroup = Group::query()->firstOrCreate(
            ['name' => 'Administrateurs plateforme'],
            ['owner_id' => $admin->id, 'description' => 'Super-admins responsables de la configuration globale.'],
        );

        $adminGroup->members()->syncWithoutDetaching([
            $admin->id => ['role' => GroupRole::OWNER->value],
        ]);
    }
}
