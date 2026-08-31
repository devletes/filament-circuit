<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Filament\Pages\Showcase;
use Workbench\App\Models\User;
use Workbench\App\Models\Workflow;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Testbench's `workbench.user` already created this one; this only
        // pins the password the capture script signs in with.
        User::query()->updateOrCreate(
            ['email' => 'aria@example.com'],
            ['name' => 'Aria Bennett', 'password' => Hash::make('password')],
        );

        Workflow::query()->create([
            'name' => 'Expense approval',
            'description' => 'Anything over £5,000 goes to Finance as well.',
            'graph' => Showcase::conditionGraph(),
        ]);

        Workflow::query()->create([
            'name' => 'Onboarding',
            'description' => 'Runs the day a new starter accepts.',
            'graph' => Showcase::approvalGraph(),
        ]);
    }
}
