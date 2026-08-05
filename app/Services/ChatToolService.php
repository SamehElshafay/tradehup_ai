<?php
namespace App\Services;

use App\Models\Coin;
use App\Models\Recommendation;
use App\Models\PaperTradingSession;
use Illuminate\Support\Facades\Log;
use Exception;

class ChatToolService {
    public function __construct(private PythonTAService $taService) {}

    public function getToolDefinitions(): array {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_crypto_price',
                    'description' => 'Get the current live price for a cryptocurrency symbol (e.g. BTCUSDT, ADAUSDT).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'symbol' => [
                                'type' => 'string',
                                'description' => 'The trading pair symbol (e.g. BTCUSDT)'
                            ]
                        ],
                        'required' => ['symbol']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_technical_analysis',
                    'description' => 'Perform full technical analysis on a symbol. Returns EMAs, RSI, MACD, Patterns, and Support/Resistance levels.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'symbol' => [
                                'type' => 'string',
                                'description' => 'The trading pair symbol (e.g. BTCUSDT)'
                            ],
                            'timeframe' => [
                                'type' => 'string',
                                'description' => 'The timeframe to analyze (e.g. 15m, 1h, 4h, 1d)',
                                'enum' => ['15m', '1h', '4h', '1d']
                            ]
                        ],
                        'required' => ['symbol', 'timeframe']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_recent_opportunities',
                    'description' => 'Get the latest trading opportunities discovered by the automated AI scanner.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Number of opportunities to return (max 10)'
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_my_active_trades',
                    'description' => 'Get the user\'s currently active paper trades.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => []
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_my_trading_stats',
                    'description' => 'Get the user\'s paper trading session statistics, balance, win rate, and P&L.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => []
                    ]
                ]
            ]
        ];
    }

    public function executeToolCall(string $name, array $args, int $userId): string {
        try {
            switch ($name) {
                case 'get_crypto_price':
                    $symbol = strtoupper($args['symbol'] ?? '');
                    $price = $this->taService->getPrice($symbol);
                    if ($price) return json_encode(['symbol' => $symbol, 'price' => $price]);
                    $coin = Coin::where('symbol', $symbol)->first();
                    if ($coin) return json_encode(['symbol' => $symbol, 'price' => $coin->current_price]);
                    return json_encode(['error' => "Price not found for {$symbol}"]);

                case 'get_technical_analysis':
                    $symbol = strtoupper($args['symbol'] ?? '');
                    $timeframe = strtolower($args['timeframe'] ?? '4h');
                    $analysis = $this->taService->getAnalysis($symbol, $timeframe);
                    return json_encode(['symbol' => $symbol, 'timeframe' => $timeframe, 'analysis' => $analysis]);

                case 'get_recent_opportunities':
                    $limit = min((int)($args['limit'] ?? 5), 10);
                    $recs = Recommendation::with('coin')->where('action', '!=', 'WAIT')->latest()->take($limit)->get();
                    $result = $recs->map(fn($r) => [
                        'symbol' => $r->coin->symbol,
                        'strategy' => $r->strategy,
                        'action' => $r->action,
                        'reasoning' => $r->reasoning,
                        'created_at' => $r->created_at->toDateTimeString()
                    ]);
                    return json_encode(['opportunities' => $result]);

                case 'get_my_active_trades':
                    $session = PaperTradingSession::where('user_id', $userId)->where('status', 'active')->first();
                    if (!$session) return json_encode(['error' => 'No active paper trading session found.']);
                    $trades = $session->trades()->where('status', 'open')->with('coin')->get()->map(fn($t) => [
                        'id' => $t->id,
                        'symbol' => $t->coin->symbol,
                        'type' => $t->type,
                        'entry_price' => $t->entry_price,
                        'quantity' => $t->quantity,
                        'opened_at' => $t->opened_at,
                        'tp3' => $t->tp3,
                        'sl' => $t->sl
                    ]);
                    return json_encode(['active_trades' => $trades]);

                case 'get_my_trading_stats':
                    $session = PaperTradingSession::where('user_id', $userId)->where('status', 'active')->first();
                    if (!$session) return json_encode(['error' => 'No active paper trading session found.']);
                    return json_encode([
                        'initial_balance' => $session->initial_balance,
                        'current_balance_available' => $session->current_balance,
                        'total_profit' => (float) $session->trades()->where('pnl', '>', 0)->sum('pnl'),
                        'total_loss' => (float) $session->trades()->where('pnl', '<', 0)->sum('pnl'),
                        'win_rate' => $session->win_rate,
                        'total_trades' => $session->total_trades
                    ]);

                default:
                    return json_encode(['error' => "Unknown function {$name}"]);
            }
        } catch (Exception $e) {
            Log::error("Error executing tool {$name}: " . $e->getMessage());
            return json_encode(['error' => 'An error occurred while fetching data.']);
        }
    }
}
