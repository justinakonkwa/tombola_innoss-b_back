<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Ferme les commandes non payées et libère les tickets réservés.
        $schedule->command('tombola:expire-orders')->everyFiveMinutes()->withoutOverlapping();

        // Filet de sécurité si un webhook de paiement a été perdu.
        $schedule->command('tombola:reconcile-payments')->everyTenMinutes()->withoutOverlapping();

        // Surveillance de l'intégrité du journal d'audit.
        $schedule->command('tombola:verify-audit-chain')->dailyAt('03:15');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
