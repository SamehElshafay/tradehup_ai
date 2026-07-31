<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Favorite extends Model {
    public $timestamps = false;
    protected $fillable = ['user_id', 'coin_id'];
    protected $casts = ['created_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
    public function coin() { return $this->belongsTo(Coin::class); }
}
