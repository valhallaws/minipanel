<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'minipanel:admin {name} {email} {--password=}';

    protected $description = 'Create or update the local MiniPanel administrator';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password');
        User::updateOrCreate(['email' => $this->argument('email')], [
            'name' => $this->argument('name'), 'password' => Hash::make($password),
        ]);
        $this->info('Administrator ready.');

        return self::SUCCESS;
    }
}
