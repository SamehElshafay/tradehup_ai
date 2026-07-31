<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;
use App\Jobs\ScanMarketOpportunitiesJob;
use App\Jobs\EvaluateActiveTradesJob;
use App\Jobs\UpdatePaperTradesJob;

$settingsFile = storage_path('app/ai_settings.json');
$scanInterval = '4h';
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    if (isset($settings['scan_interval'])) {
        $scanInterval = $settings['scan_interval'];
    } elseif (isset($settings['scan_interval_hours'])) {
        $scanInterval = $settings['scan_interval_hours'] . 'h';
    }
}

// Run the market scanner based on configured interval
$scannerJob = Schedule::job(new ScanMarketOpportunitiesJob);
switch ($scanInterval) {
    case '10m': $scannerJob->everyTenMinutes(); break;
    case '15m': $scannerJob->everyFifteenMinutes(); break;
    case '20m': $scannerJob->cron('*/20 * * * *'); break;
    case '30m': $scannerJob->everyThirtyMinutes(); break;
    case '45m': $scannerJob->cron('*/45 * * * *'); break;
    case '1h':  $scannerJob->hourly(); break;
    case '2h':  $scannerJob->everyTwoHours(); break;
    case '4h':  $scannerJob->everyFourHours(); break;
    case '8h':  $scannerJob->cron('0 */8 * * *'); break;
    case '12h': $scannerJob->cron('0 */12 * * *'); break;
    case '24h': $scannerJob->daily(); break;
    default:    $scannerJob->everyFourHours(); break;
}

// Evaluate active trades accurately via 1m klines every 5 minutes
Schedule::job(new EvaluateActiveTradesJob)->everyFiveMinutes();

// Evaluate paper trades (Trade Tracker) every minute
Schedule::job(new UpdatePaperTradesJob)->everyMinute();
