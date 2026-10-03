<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('admin:create {email : Login e-mail} {--name= : Display name} {--password= : Password (generated when omitted)}')]
#[Description('Create a super-admin account (the only way accounts are created)')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (AdminUser::where('email', $email)->exists()) {
            $this->error("An admin with e-mail {$email} already exists.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(20);

        AdminUser::create([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Admin {$email} created.");

        if (! $this->option('password')) {
            $this->line("Generated password: {$password}");
        }

        $this->line('Two-factor authentication is set up on first login.');

        return self::SUCCESS;
    }
}
