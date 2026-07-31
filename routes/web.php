<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


use App\Events\OpportunityCreated;
use App\Models\Recommendation;
Route::get('/test-notification', function () {
    $recommendation = Recommendation::with('coin')->latest()->first();
    
    if (!$recommendation) {
        // If no recommendation exists, create a dummy one for testing
        $recommendation = new Recommendation([
            'id' => 999,
            'action' => 'BUY',
            'entry_price' => 50000,
            'sl' => 49000,
            'tp1' => 52000,
            'confidence' => 85,
            'status' => 'active'
        ]);
        
        $coin = new \App\Models\Coin(['symbol' => 'BTCUSDT', 'name' => 'Bitcoin']);
        $recommendation->setRelation('coin', $coin);
    }
    
    broadcast(new OpportunityCreated($recommendation));
    return 'Notification Event Fired Successfully! Check the Vue Frontend.';
});
