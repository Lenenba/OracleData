<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:grant-super-admin {email : Email address of the existing user}')]
#[Description('Grant unrestricted platform administration access to an existing user')]
class GrantSuperAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun utilisateur trouvé pour [{$email}].");

            return self::FAILURE;
        }

        if ($user->isSuperAdmin()) {
            $this->info("{$user->email} est déjà super-administrateur.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_super_admin' => true])->save();

        $this->info("Accès super-administrateur accordé à {$user->email}.");

        return self::SUCCESS;
    }
}
