<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OpportunityCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $opportunity;

    /**
     * Create a new event instance.
     */
    public function __construct($opportunity)
    {
        $this->opportunity = $opportunity;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('opportunities'),
        ];
    }
    
    public function broadcastWith(): array
    {
        // Load the coin relationship if not loaded
        if (!$this->opportunity->relationLoaded('coin')) {
            $this->opportunity->load('coin');
        }
        return ['opportunity' => $this->opportunity->toArray()];
    }
}
