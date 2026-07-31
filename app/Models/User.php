<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable {
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['username', 'name', 'email', 'password', 'avatar', 'paper_balance'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'paper_balance' => 'float'
    ];

    public function favorites() { return $this->hasMany(Favorite::class); }
    public function follows() { return $this->hasMany(Follow::class); }
    public function favoritedCoins() { return $this->belongsToMany(Coin::class, 'favorites'); }
    public function followedCoins() { return $this->belongsToMany(Coin::class, 'follows')->withPivot('notify'); }
    public function paperSessions() { return $this->hasMany(PaperTradingSession::class); }
    public function activePaperSession() { return $this->hasOne(PaperTradingSession::class)->where('status', 'active'); }
    public function chatConversations() { return $this->hasMany(ChatConversation::class); }
}
