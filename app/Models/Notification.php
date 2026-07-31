<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model {
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'user_id', 'type', 'title', 'body', 'data', 'read_at'];
    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];
    protected static function boot() {
        parent::boot();
        static::creating(function ($model) {
            if (!$model->id) $model->id = (string) \Illuminate\Support\Str::uuid();
        });
    }
    public function user() { return $this->belongsTo(User::class); }
    public function isRead(): bool { return $this->read_at !== null; }
}
