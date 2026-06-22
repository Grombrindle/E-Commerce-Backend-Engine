<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class K6SetupUsers extends Command
{
    protected $signature = 'k6:setup-users
                          {--count=100 : Number of test users to create}
                          {--force : Re-create users even if they already exist}';

    protected $description = 'Create test users for k6 load testing (vu1@test.com to vu100@test.com)';

    public function handle(): void
    {
        $count = (int) $this->option('count');
        $force = (bool) $this->option('force');
        $created = 0;
        $skipped = 0;

        $this->output->writeln("<info>Setting up {$count} test users for k6...</info>");

        for ($i = 1; $i <= $count; $i++) {
            $email = "vu{$i}@test.com";

            $existing = User::where('email', $email)->first();

            if ($existing) {
                if ($force) {
                    $existing->update([
                        'password' => Hash::make('password123'),
                        'role' => 'customer',
                    ]);
                    $created++;
                } else {
                    $skipped++;
                }
                continue;
            }

            User::create([
                'name' => "LoadTest User {$i}",
                'email' => $email,
                'password' => Hash::make('password123'),
                'role' => 'customer',
                'phone' => '+1-555-' . str_pad($i, 4, '0', STR_PAD_LEFT),
            ]);

            $created++;
        }

        $this->output->writeln("<info>✅ Done: {$created} created, {$skipped} skipped (use --force to re-create)</info>");
    }
}
