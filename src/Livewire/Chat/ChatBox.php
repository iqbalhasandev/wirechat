<?php

namespace Namu\WireChat\Livewire\Chat;

use App\Notifications\TestNotification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
//use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Namu\WireChat\Models\Conversation;
use Namu\WireChat\Models\Message;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithPagination;
use Namu\WireChat\Events\MessageCreated;
use Namu\WireChat\Jobs\BroadcastMessage;
use Namu\WireChat\Models\Attachment;
use Namu\WireChat\Models\Scopes\WithoutClearedScope;

class ChatBox extends Component
{

    use WithFileUploads;
    use WithPagination;

    #[Locked] 
    public $conversation;

    public $receiver;
    public $body;

    public $loadedMessages;
    public int $paginate_var = 10;
    public bool $canLoadMore;


    public array $media = [];


    public array $files = [];


    //Theme 
    public string $authMessageBodyColor;

    public $replyMessage = null;



    public function getListeners()
    {
        return [
            "echo-private:conversation.{$this->conversation->id},.Namu\\WireChat\\Events\\MessageCreated" => 'appendNewMessage',
        ];
    }


    /** 
     * Todo: Authorize the property
     * Todo: or lock it 
     * todo:Check if user can reply to this message 
     * Set replyMessage as Message Model
     *  */
    public function setReply(Message $message)
    {
        #check if user belongs to message
        abort_unless(auth()->user()->belongsToConversation($this->conversation), 403);

        #abort if message does not belong to this conversation or is not owned by any participant
        abort_unless($message->conversation_id==$this->conversation->id,403);

        //Set owner as Id we are replying to 
        $this->replyMessage = $message;


        #dispatch event to focus input field 
        $this->dispatch('focus-input-field');
    }

    public function removeReply()
    {

        $this->replyMessage = null;
    }

    /**
     * livewire method
     ** This is avoid replacing temporary files on add more files
     * We override the function in WithFileUploads Trait
     */
    function _finishUpload($name, $tmpPath, $isMultiple)
    {
        $this->cleanupOldUploads();


        $files = collect($tmpPath)->map(function ($i) {
            return TemporaryUploadedFile::createFromLivewire($i);
        })->toArray();
        $this->dispatch('upload:finished', name: $name, tmpFilenames: collect($files)->map->getFilename()->toArray())->self();

        // If the property is an array, APPEND the upload to the array.
        $currentValue = $this->getPropertyValue($name);

        if (is_array($currentValue)) {
            $files = array_merge($currentValue, $files);
        } else {
            $files = $files[0];
        }

        app('livewire')->updateProperty($this, $name, $files);
    }

    
    function listenBroadcastedMessage($event)
    {

        // dd('reached');
        $this->dispatch('scroll-bottom');
        $newMessage = Message::find($event['message_id']);

       


        #push message
        $this->loadedMessages->push($newMessage);

        #mark as read
        $newMessage->read_at = now();
        $newMessage->save();
    }
  
    //handle incomming broadcasted message event
    public function appendNewMessage($event)
    {

        //before appending message make sure it belong to this conversation 
        if ($event['message']['conversation_id'] == $this->conversation->id) {

            #scroll to bottom
            $this->dispatch('scroll-bottom');

            $newMessage = Message::find($event['message']['id']);

            //Make sure message does not belong to auth
            // Make sure message does not belong to auth
            if ($newMessage->sendable_id == auth()->id() && $newMessage->sendable_type === get_class(auth()->user())) {
                return null;
            }

            #push message
            $this->loadedMessages->push($newMessage);

            #mark as read
            $newMessage->markAsRead();
            #broadcast 
            // $this->selectedConversation->getReceiver()->notify(new MessageRead($this->selectedConversation->id));
        }
    }

    /**
     * Delete conversation  */
    function deleteConversation()
    {
        abort_unless(auth()->check(),401);

        
        #delete conversation 
        $this->conversation->deleteFor(auth()->user());

        #redirect to chats page 
        $this->redirectRoute("wirechat");
    }

      /**
     * clearChat  */
    // function clearChat()
    // {
    //     abort_unless(auth()->check(),401);

    //     #delete conversation 
    //     $this->conversation->clearFor(auth()->user());

    //     #clear the blade of chats
    //     $this->reset('loadedMessages');
    // }

     protected function rateLimit(){


        if (RateLimiter::tooManyAttempts('send-message:'.auth()->id(), $perMinute = 60)) {

            return abort(429,'Too many attempts!, Please slow down');
         }
          
         RateLimiter::increment('send-message:'.auth()->id());
    }

    /**
     * Send a message  */
    function sendMessage()
    {

        abort_unless(auth()->check(), 401);

        #rate limit 
        $this->rateLimit();

        /* If media is empty then conitnue to validate body , since media can be submited without body */
        // Combine media and files arrays

        $attachments = array_merge($this->media, $this->files);
        //    dd(config('wirechat.file_mimes'));

        // If combined files array is empty, continue to validate body
        if (empty($attachments)) {
            $this->validate(['body' => 'required|string']);
        }

        if (count($attachments) != 0) {

            //Validation 

            // Retrieve maxUploads count
            $maxUploads = config('wirechat.attachments.max_uploads');

            //Files
            $fileMimes = implode(',', config('wirechat.attachments.file_mimes'));
            $fileMaxUploadSize = config('wirechat.attachments.file_max_upload_size');

            //media
            $mediaMimes = implode(',', config('wirechat.attachments.media_mimes'));
            $mediaMaxUploadSize = config('wirechat.attachments.media_max_upload_size');

            try {
                //$this->js("alert('message')");
                $this->validate([
                    "files" => "max:$maxUploads|nullable",
                    "files.*" => "mimes:$fileMimes|max:$fileMaxUploadSize",
                    "media" => "max:$maxUploads|nullable",
                    "media.*" => "max:$mediaMaxUploadSize|mimes:$mediaMimes",

                ]);
            } catch (\Illuminate\Validation\ValidationException $th) {


                return $this->dispatch('notify', type: 'warning', message: $th->getMessage());
            }


            //Combine media and files thne perform loop together

            $createdMessages = [];
            foreach ($attachments as $key => $attachment) {

                /**
                 * todo: Add url to table
                 */

                #save photo to disk 
                $path =  $attachment->store(config('wirechat.attachments.storage_folder', 'attachments'), config('wirechat.attachments.storage_disk','public'));

                #create attachment
                $createdAttachment = Attachment::create([
                    'file_path' => $path,
                    'file_name' => basename($path),
                    'original_name' => $attachment->getClientOriginalName(),
                    'mime_type' => $attachment->getMimeType(),
                    'url' => url($path)
                ]);


                $message = Message::create([
                    'reply_id' => $this->replyMessage?->id,
                    'conversation_id' => $this->conversation->id,
                    'attachment_id' => $createdAttachment?->id, // Ensure that $createdAttachment is nullable if not always present
                    'sendable_type' => get_class(auth()->user()), // Polymorphic sender type
                    'sendable_id' => auth()->id(), // Polymorphic sender ID
                    // 'body' => $this->body, // Add body if required
                ]);
                

                #append message to createdMessages
                $createdMessages[] = $message;


                #update the conversation model - for sorting in chatlist
                $this->conversation->updated_at = now();
                $this->conversation->save();

                #dispatch event 'refresh ' to chatlist 
                $this->dispatch('refresh')->to(ChatList::class);

                #broadcast message 
                $this->dispatchMessageCreatedEvent($message);
            }

            #push the message
            $this->loadedMessages = $this->loadedMessages->concat($createdMessages);

            #scroll to bottom
            $this->dispatch('scroll-bottom');
        }


        if ($this->body != null) {

            $createdMessage = Message::create([
                'reply_id' => $this->replyMessage?->id,
                'conversation_id' => $this->conversation->id,
                'sendable_type' => get_class(auth()->user()), // Polymorphic sender type
                'sendable_id' => auth()->id(), // Polymorphic sender ID
                'body' => $this->body
            ]);

            $this->reset('body');

            #push the message
            $this->loadedMessages->push($createdMessage);


            #update the conversation model - for sorting in chatlist
            $this->conversation->updated_at = now();
            $this->conversation->save();

            #dispatch event 'refresh ' to chatlist 
            $this->dispatch('refresh')->to(ChatList::class);

            #broadcast message  
            $this->dispatchMessageCreatedEvent($createdMessage);
        }
        $this->reset('media', 'files', 'body');

        #scroll to bottom
        $this->dispatch('scroll-bottom');


        #remove reply just incase it is present 
        $this->removeReply();
    }

    /**
     * Delete for me means any participant of the conversation  can delete the message
     * and this will hide the message from them but other participants can still access/see it 
     **/
    function deleteForMe(Message $message){


        #make sure user is authenticated
        abort_unless(auth()->check(), 401);

        #make sure user owns message
        // abort_unless($message->ownedBy(auth()->user()), 403);

        //make sure user belongs to conversation from the message
        //We are checking the $message->conversation for extra security because the param might be tempered with 
        abort_unless(auth()->user()->belongsToConversation($message->conversation),403);

        #remove message from collection
        $this->loadedMessages= $this->loadedMessages->reject(function ($loadedMessage) use ($message) {
            return $loadedMessage->id == $message->id;
        });

        #dispatch event 'refresh ' to chatlist 
        $this->dispatch('refresh')->to(ChatList::class);


        #delete For $user
        $message->deleteFor(auth()->user());

    }


    /**
     * Delete for eveyone means only owner of messages &  participant of the conversation  can delete the message
     * and this will completely delete the message from the database 
     * Unless it has a foreign key child or parent :then it i will be soft deleted
     **/
    function deleteForEveryone(Message $message){


        #make sure user is authenticated
        abort_unless(auth()->check(), 401);

        #make sure user owns message
        abort_unless($message->ownedBy(auth()->user()), 403);

        //make sure user belongs to conversation from the message
        //We are checking the $message->conversation for extra security because the param might be tempered with 
        abort_unless(auth()->user()->belongsToConversation($message->conversation),403);

        #remove message from collection
        $this->loadedMessages= $this->loadedMessages->reject(function ($loadedMessage) use ($message) {
            return $loadedMessage->id == $message->id;
        });

        #dispatch event 'refresh ' to chatlist 
        $this->dispatch('refresh')->to(ChatList::class);


        //if message has reply then only soft delete it 
        if ($message->hasReply()) {
        
            #delete message from database
            $message->delete();
        }
        else {

        #else Force delete message from database
        $message->forceDelete();

        }


       

    }


    //used to broadcast message sent to receiver
    protected function dispatchMessageCreatedEvent(Message $message)
    {

        // send broadcast message only to others 
        // we add try catch to avoid runtime error when broadcasting services are not connected
        // todo create a job to broadcast multiple messages
        try {

            //!remove the receiver from the messageCreated and add it to the job instead 
            //!also do not forget to exlude auth user or message owner from particpants 

            BroadcastMessage::dispatch($this->conversation,$message);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    /** Send Like as  message */
    public function sendLike()
    {

        //sleep(2);

        #rate limit 
        $this->rateLimit();

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'attachment_id' => null,
            'sendable_type' => get_class(auth()->user()), // Polymorphic sender type
            'sendable_id' => auth()->id(), // Polymorphic sender ID
            'body' => '❤️'
        ]);


        #update the conversation model - for sorting in chatlist
        $this->conversation->updated_at = now();
        $this->conversation->save();

        #push the message
        $this->loadedMessages->push($message);

        #dispatch event 'refresh ' to chatlist 
        $this->dispatch('refresh')->to(ChatList::class);

        #scroll to bottom
        $this->dispatch('scroll-bottom');

        #dispatch event 
        $this->dispatchMessageCreatedEvent($message);
    }

    // #[On('loadMore')]
    function loadMore()
    {
        //dd('reached');

        #increment
        $this->paginate_var += 10;
        #call loadMessage
        $this->loadMessages();

        #dispatch event- update height
        $this->dispatch('update-height');
    }


    function loadMessages()
    {


        #get count
        $count = Message::where('conversation_id', $this->conversation->id)->where(function ($query) {
          //  $query->whereNotDeleted();
        })->count();

        #skip and query
        $this->loadedMessages = Message::where('conversation_id', $this->conversation->id)
            ->where(function ($query) {
              //  $query->whereNotDeleted();
            })
            ->with('parent')
            ->skip($count - $this->paginate_var)
            ->take($this->paginate_var)
            ->get();

        // Calculate whether more messages can be loaded
        $this->canLoadMore = $count > count($this->loadedMessages);



        return $this->loadedMessages;
    }

    /* to generate color auth message background color */
    public function getAuthMessageBodyColor(): string
    {

        $color = config('wirechat.theme', 'blue');

        return 'bg-' . $color . '-500';
    }

    public function mount()
    {
        //auth 

        abort_unless(auth()->check(), 401);

        //assign converstion


        $this->conversation = Conversation::withoutGlobalScope(WithoutClearedScope::class)->where('id', $this->conversation)->first();
     //    dd($this->conversation);


        //Abort if not made 
        abort_unless($this->conversation, 404);


        // Check if the user belongs to the conversation
        $belongsToConversation = $this->conversation->participants()
        ->where('participantable_id', auth()->id())
              ->where('participantable_type', get_class(auth()->user()))
        ->exists();

        abort_unless($belongsToConversation, 403);

        $this->receiver = $this->conversation->getReceiver();

        $this->authMessageBodyColor = $this->getAuthMessageBodyColor();

        $this->loadMessages();
    }

    public function render()
    {
       // sleep(3);
        $conversation = Conversation::first();

        return view('wirechat::livewire.chat.chat-box');
    }
}
