<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Coin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed default user
        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'username'      => 'test_user',
                'name'          => 'Test User',
                'password'      => Hash::make('password'),
                'paper_balance' => 1000.00,
            ]
        );

        // Seed initial coins
        $initialCoins = [
            ['symbol' => 'BTCUSDT', 'name' => 'Bitcoin', 'base_asset' => 'BTC', 'current_price' => 65000.00],
            ['symbol' => 'ETHUSDT', 'name' => 'Ethereum', 'base_asset' => 'ETH', 'current_price' => 3400.00],
            ['symbol' => 'SOLUSDT', 'name' => 'Solana', 'base_asset' => 'SOL', 'current_price' => 150.00],
            ['symbol' => 'BNBUSDT', 'name' => 'Binance Coin', 'base_asset' => 'BNB', 'current_price' => 580.00],
            ['symbol' => 'ADAUSDT', 'name' => 'Cardano', 'base_asset' => 'ADA', 'current_price' => 0.45],
            ['symbol' => 'XRPUSDT', 'name' => 'Ripple', 'base_asset' => 'XRP', 'current_price' => 0.60],
            ['symbol' => 'DOGEUSDT', 'name' => 'Dogecoin', 'base_asset' => 'DOGE', 'current_price' => 0.12],
            ['symbol' => 'LINKUSDT', 'name' => 'Chainlink', 'base_asset' => 'LINK', 'current_price' => 14.50],
            ['symbol' => 'NEARUSDT', 'name' => 'Near Protocol', 'base_asset' => 'NEAR', 'current_price' => 5.20],
        ];

        foreach ($initialCoins as $coinData) {
            Coin::updateOrCreate(
                ['symbol' => $coinData['symbol']],
                [
                    'name'             => $coinData['name'],
                    'base_asset'       => $coinData['base_asset'],
                    'quote_asset'      => 'USDT',
                    'current_price'    => $coinData['current_price'],
                    'price_change_24h' => 0.0,
                    'is_active'        => true,
                    'last_synced_at'   => now(),
                ]
            );
        }
    }
}
