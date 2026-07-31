<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model {
    protected $fillable = ['conversation_id', 'role', 'content', 'ai_model', 'bridge_request_id', 'bridge_status'];
    public function conversation() { return $this->belongsTo(ChatConversation::class); }
}
