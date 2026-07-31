<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Follow extends Model {
    public $timestamps = false;
    protected $fillable = ['user_id', 'coin_id', 'notify'];
    protected $casts = ['notify' => 'boolean', 'created_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function coin() { return $this->belongsTo(Coin::class); }
}
