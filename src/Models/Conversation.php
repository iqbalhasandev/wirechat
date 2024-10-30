<?php

namespace Namu\WireChat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Namu\WireChat\Enums\Actions;
use Namu\WireChat\Enums\ConversationType;
use Namu\WireChat\Enums\ParticipantRole;
use Namu\WireChat\Facades\WireChat;
use Namu\WireChat\Models\Scopes\WithoutClearedScope;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type'
    ];

    protected $casts = [
        'type' => ConversationType::class
    ];


    public function __construct(array $attributes = [])
    {
        $this->table = WireChat::formatTableName('conversations');

        parent::__construct($attributes);
    }

    protected static function boot()
    {
        parent::boot();

        // static::addGlobalScope(new WithoutClearedScope());
        //DELETED event
        static::deleted(function ($conversation) {

            // Use a DB transaction to ensure atomicity
            DB::transaction(function () use ($conversation) {

                // Delete associated participants 
                $conversation->participants()->delete();


                // Delete reads
                // Use a DB transaction to ensure atomicity
                DB::transaction(function () use ($conversation) {
                    // Delete associated reads (polymorphic readable relation)
                    $conversation->reads()->delete();
                });


                // Delete associated messages 
                $conversation->messages()->forceDelete();

                //Delete actions 
                $conversation->actions()->delete();

                //Delete group 
                $conversation->group()->delete();
            });
        });
    }

    /** 
     * since you have a non-standard namespace; 
     * the resolver cannot guess the correct namespace for your Factory class.
     * so we exlicilty tell it the correct namespace
     */
    protected static function newFactory()
    {
        return \Namu\WireChat\Workbench\Database\Factories\ConversationFactory::new();
    }

    /**
     * Define a relationship to fetch participants for this conversation.
     */
    public function participants()
    {
        return $this->hasMany(Participant::class, 'conversation_id', 'id');
    }


    /**
     * Get a participant model  from the user
     * @param Model $user
     * @return Participant|null
     */
    public function participant(Model $user)
    {

        $participant = null;
        // If loaded, simply check the existing collection
        if ($this->relationLoaded('participants')) {
            $participant = $this->participants
              ->  withoutGlobalScope('withoutExited')
                ->where('participantable_id', $user->id)
                ->where('participantable_type', get_class($user))
                ->first();
        } else {
            $participant = $this->participants()
            ->  withoutGlobalScope('withoutExited')

                ->where('participantable_id', $user->id)
                ->where('participantable_type', get_class($user))
                ->first();
        }

        return $participant;
    }





    /**
     * Add a new participant to the conversation.
     *
     * @param Model $participant
     * @return Participant
     */
    public function addParticipant(Model $participant): Participant
    {
        // Check if the participant is already in the conversation
        abort_if(
            $this->participants()
                ->where('participantable_id', $participant->id)
                ->where('participantable_type', get_class($participant))
                ->exists(),
            422,
            'Participant is already in the conversation.'
        );

        // If the conversation is private, ensure it doesn't exceed two participants
        if ($this->isPrivate()) {
            abort_if(
                $this->participants()->count() >= 2,
                422,
                'Private conversations cannot have more than two participants.'
            );
        }

        // Attach the participant to the conversation
        $participant = $this->participants()->updateOrCreate([
            'participantable_id' => $participant->id,
            'participantable_type' => get_class($participant),
            'role' => ParticipantRole::PARTICIPANT
        ],['exited_at'=>null]);

        return $participant;
    }



    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeWithoutCleared(Builder $builder): void
    {
        $user = auth()->user(); // Get the authenticated user

        // dd($model->id);
        // Apply the scope only if the user is authenticated
        if ($user) {
            $builder->whereHas('messages', function ($q) use ($user) {
                $q->whereDoesntHave('actions', function ($q) use ($user) {
                    $q->where('actor_id', $user->id)
                        ->where('actor_type', get_class($user)) // Safe since $user is authenticated
                        ->where('type', Actions::DELETE);
                });
            });
        }
    }


    public function getReceiver()
    {
        // Check if the conversation is private
        if ($this->type != ConversationType::PRIVATE) {
            return null;
        }

        // Ensure participants are already loaded (use the loaded relationship, not fresh queries)
        $participants = $this->participants;

        // Ensure there are exactly two participants
        if ($participants->count() !== 2) {
            return null;
        }

        // Get the participant who is not the authenticated user
        $receiverParticipant = $participants->where('participantable_id', '!=', auth()->id())
            ->where('participantable_type', get_class(auth()->user()))
            ->first();

        if ($receiverParticipant) {
            // Return the associated model via the participant's relationship
            return $receiverParticipant->participantable;
        }

        // Check the number of times the user appears as participant (fallback case)
        $authReceiver = $participants->where('participantable_id', auth()->id())
            ->where('participantable_type', get_class(auth()->user()))
            ->first();

        return $authReceiver?->participantable;
    }



    public function scopeWhereNotDeleted($query)
    {
        $userId = auth()->id();

        return $query->where(function ($query) use ($userId) {

            #where message is not deleted
            $query->whereHas('messages', function ($query) use ($userId) {

                $query->where(function ($query) use ($userId) {
                    $query->where('sender_id', $userId)
                        ->whereNull('sender_deleted_at');
                })->orWhere(function ($query) use ($userId) {

                    $query->where('receiver_id', $userId)
                        ->whereNull('receiver_deleted_at');
                });
            })
                #include conversations without messages
                ->orWhereDoesntHave('messages');
        });
    }



    /**
     * ----------------------------------------
     * ----------------------------------------
     * Reads 
     * Define relationship and methods for conversation reads
     * --------------------------------------------
     */


    /**
     * Get all of the reads for the conversation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reads(): HasMany
    {
        return $this->hasMany(Read::class, 'conversation_id');
    }

    /**
     * Mark the conversation as read for the current authenticated user.
     * @param Model $user||null 
     * If not user is passed ,it will attempt to user auth(),if not avaible then will return null
     */
    public function markAsRead(Model $user = null)
    {

        $user =  $user ?? auth()->user();
        if ($user == null) {

            return null;
            # code...
        }
        // Update or create a read record for the conversation
        $this->reads()->updateOrCreate(
            [
                'readable_id' => $user->id,
                'readable_type' => get_class($user),
            ],
            [
                'read_at' => now(),
            ]
        );
    }

    /**
     * Check if the conversation has been fully read by a specific user.
     * This returns true if there are no unread messages after the conversation
     * was marked as read by the user.
     *
     * @param Model $user
     * @return bool
     */
    public function readBy(Model $user): bool
    {
        // Reuse the unread count method and return true if unread count is 0
        return $this->getUnreadCountFor($user) <= 0;
    }



    /**
     * Retrieve unread messages in this conversation for a specific user.
     *
     * @param Model $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function unreadMessages(Model $user)
    {
        $lastReadAt = $this->reads()
            ->where('readable_id', $user->id)
            ->where('readable_type', get_class($user))
            ->value('read_at');

        return $this->messages()
            ->where('created_at', '>', $lastReadAt)
            ->get();
    }


    // /**
    //  * Mark all messages in the conversation as read by the authenticated user.
    //  *
    //  * @return void
    //  */
    // public function markAsRead()
    // {
    //     abort_unless(auth()->check(), 401);
    //     $authUserId = auth()->id();

    //     // Get all messages in the conversation that are not already read by the authenticated user
    //     $messages = $this->messages()->whereDoesntHave('reads', function ($query) use ($authUserId) {
    //         $query->where('readable_id', $authUserId)
    //             ->where('readable_type', get_class(auth()->user()));
    //     })->get();

    //     foreach ($messages as $message) {
    //         // Create a read record if it doesn't already exist
    //         $message->reads()->firstOrCreate([
    //             'readable_id' => $authUserId,
    //             'readable_type' => get_class(auth()->user())
    //         ], [
    //             'read_at' => now(),
    //         ]);
    //     }
    // }


    /**
     * Get unread messages count for the specified user.
     *
     * @param Model $model
     * @return int
     */
    public function getUnreadCountFor(Model $model): int
    {
        // Get the last time the conversation was marked as read by the user
        $lastReadAt = $this->reads()
            ->where('readable_id', $model->id)
            ->where('readable_type', get_class($model))
            ->value('read_at');

        // Check if the messages relation is already loaded to avoid duplicate queries
        if ($this->relationLoaded('messages')) {
            $messages = $this->messages;
        } else {
            $messages = $this->messages();
        }

        //exclude messages which user owns 
        $messages->where('sendable_id', '!=', $model->id)
            ->where('sendable_type', get_class($model));

        // If the conversation has never been marked as read, return all messages count
        if (!$lastReadAt) {
            return $messages->count();
        }

        // Count the messages that were created after the last read timestamp
        return $messages
            ->where('created_at', '>', $lastReadAt)
            ->count();
    }


    /**
     * ----------------------------------------
     * ----------------------------------------
     * Actions 
     * A message can have many actions by different users)
     * --------------------------------------------
     */


    public function actions()
    {
        return $this->morphMany(Action::class, 'actionable', 'actionable_type', 'actionable_id', 'id');
    }

    /**
     * Delete all messages for the given participant and check if the conversation can be deleted.
     * @param Model $participant The participant whose messages are to be deleted.
     * @return void|null Returns null if the other participant cannot be found in a private conversation.
     */
    public function deleteFor(Model $participant)
    {
        // Ensure the participant belongs to the conversation
        abort_unless($participant->belongsToConversation($this), 403, 'Does not belong to conversation');

        // Trigger deletion of all messages for the specified participant
        $this->messages()?->each(function ($message) use ($participant) {
            $message->deleteFor($participant);
        });

        // Check if the conversation is private
        if ($this->isPrivate()) {

            //check if conversatin is Self conversation 
            //Then force delete it 
            if ($this->isSelfConversation($participant)) {

                $this->forceDelete();
            } else {


                // Retrieve the other participant in the private conversation
                $otherParticipant = $this->participants
                    ->where('participantable_id', '!=', $participant->id)
                    ->where('participantable_type', get_class($participant))
                    ->first()?->participantable;
                
                // Return null if the other participant cannot be found
                if (!$otherParticipant) {
                    return null;
                }

                // If both participants have deleted all their messages, delete the conversation permanently
                if ($this->hasBeenDeletedBy($participant) && $this->hasBeenDeletedBy($otherParticipant)) {
                    // dd("deleted");
                    $this->forceDelete();
                }
            }
        }
    }

    /**
     * Check if the conversation is owned by the user themselves
     */
    public function isSelfConversation(Model $participant = null): bool
    {
        // Use the authenticated user if no participant is provided
        $participant = $participant ?? auth()->user();

        $isSelfConversation = false;

        // Ensure the conversation is private and has exactly two participants
        if ($this->type === ConversationType::PRIVATE) {
            $participants = $this->participants; // Use the loaded participants

            if ($participants->count() === 2) {
                // Check if both participants are the same user
                $isSelfConversation = $participants->every(function ($p) use ($participant) {
                    $value = $p->participantable_id == $participant->id && $p->participantable_type == get_class($participant);

                    // Log::info($value);

                    return $value;
                });
            }
        }

        return $isSelfConversation;
    }


    /**
     * Check if a given user has deleted all messages in the conversation using the deleteForMe
     */
    public function hasBeenDeletedBy(Model $user): bool
    {
        return !$this->messages()
            // Remove global scope for "excludeDeleted"
            ->withoutGlobalScope('excludeDeleted')
            ->whereDoesntHave('actions', function ($q) use ($user) {
                $q->where('actor_id', $user->id)
                    ->where('actor_type', get_class($user))
                    ->where('type', Actions::DELETE);
            })
            ->where('conversation_id', $this->id)
            ->exists();
    }



    /**
     * ------------------------------------------
     *  ROOM CONFIGURATION
     * 
     * -------------------------------------------
     */

    public function group()
    {
        return $this->hasOne(Group::class, 'conversation_id');
    }


    public function isPrivate(): bool
    {
        return $this->type == ConversationType::PRIVATE;
    }

    public function isGroup(): bool
    {
        return $this->type == ConversationType::GROUP;
    }
}
