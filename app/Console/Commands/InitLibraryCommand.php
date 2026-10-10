<?php

namespace App\Console\Commands;

use App\Services\AuthService;
use Illuminate\Console\Command;

class InitLibraryCommand extends Command
{
    protected $signature = 'library:init';
    protected $description = 'Bootstrap initial admin user from environment variables';

    public function handle(AuthService $authService): int
    {
        $admin = $authService->ensureAdminExists();
        if ($admin) {
            $this->info("Admin user '{$admin->username}' is ready.");
        } else {
            $this->warn("No ADMIN_USERNAME/ADMIN_PASSWORD configured in environment.");
        }
        return 0;
    }
}
