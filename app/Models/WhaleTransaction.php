<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class WhaleTransaction extends Model {
    protected $fillable = [
        'coin_id', 'source', 'tx_hash', 'from_address', 'to_address',
        'amount', 'amount_usd', 'transaction_type', 'occurred_at'
    ];
    protected $casts = [
        'amount' => 'float', 'amount_usd' => 'float', 'occurred_at' => 'datetime'
    ];
    public function coin() { return $this->belongsTo(Coin::class); }
}
