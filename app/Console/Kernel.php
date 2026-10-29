<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->call('App\Http\Controllers\CommissionHistoryController@store');
        // products:sync-badges intentionally NOT scheduled: it wiped and rebuilt the
        // trending_products / bestselling_products tables from order data every hour,
        // erasing admin's manual Trending/Bestselling selections. Featured was unaffected,
        // which made it look like only Trending/Bestselling were broken. Run it manually
        // via `php artisan products:sync-badges` if auto-population is ever wanted.
        $schedule->command('broadcasts:dispatch-due')->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
