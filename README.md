

This is a highly customizable one to one Laravel messaging application made with Livewire v3, it is used to provide a convinient way to allows users to communicate with eachother in the application 

## Prerequisites

Before we start, make sure you have the following installed:

* PHP version 8.1 or later
* Laravel version 10.47 or later
* Livewire version 3.2.3 or later


## Installing

Install package via composer 

```shell
composer require namu/wirechat
```

### Configuration & Migrations

1. publish the Migration file 
```
php artisan vendor:publish --tag=wirechat-migration
```
2. Optionally, publish the configuration file if you want to change any defaults:
```
 php artisan vendor:publish --tag=wirechat-config
```



## Usage

### Traits

#### `Namu\WireChat\Traits\Chatable`

```php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Namu\WireChat\Traits\Chatable;

class User extends Authenticatable
{
    use Chatable;

    ...
}


```

 

### API


```php

$user = User::find(1);
$auth = auth()->user();



$auth->sendMessageTo($user,'message');

$auth->createConversationWith($user,'message');

$auth->deleteConversationWith($user);

$auth->hasConversationWith($user); // bool


```


### Attachments



Wirechat offers a convienient way to add attachments to messages , you can exchange both media as in images and videos including documents such as pdf, zip etc when sending messsages

#### Media Attachments

Media represents videos , images, gifs etc .In order to allow media attachments you need to set the allow_media_attachments & allow_file_attachments to true in wirechat config

```php
  ...
  'attachments' => [
        'storage_folder' => 'attachments',
        'storage_disk' => 'public',

        //Media config
        'allow_media_attachments'=>true,
        'media_mimes' => (array) ['png','jpg','jpeg','gif','mov','mp4'],
        'media_max_upload_size' => 12288, // 12MB

        //Files config
        'allow_file_attachments'=>true,
        'file_mimes' => (array) ['zip','rar','txt','pdf'],
        'file_max_upload_size' => 12288, //12 MB

    ],
```

#### Filtering attachments 
 
You can filter the attachments types by adding the extentions in the array 

for example if we want to allow only add images upload in media then add only image extentions exclusively ,if you want to allow both videos and images then add add images and video extentions in the `media_mimes=[]` array

This is the same for filtering Files in the `file_mimes=[]`

you also need to allow these extensions   in the livewire config `preview_mimes` in order to allow preview while uploading 


#### max_upload_size

Wirechat is able to validate attachments on both on client side before they hit the server  and on server side ,  the `max_upload_size` represents maximum file upload size for each individual attachment represented in kilobytes 

Note: In the Livewire config file the `temporary_file_upload.rules` is set to `max:12288 //12MB ` by default if you wish to  set max_upload_size greater that this , make sure you increase it in the livewire config file as well

Lastly the `post_max_size` in the `php.ini` superseeds any configuration in livewire config and wirechat config as well , so make sure you adjust the upload size accordinly in order to allow larger file uploads 


### Aggregations
Here you can retrieve items from the pivot table to we can get the count()

```php
// retrieve all Favorites
$user->unReadMessagesCount(); //

// Filter by type
$admin->favoriteObjects()->whereType(Post::class)->count();


```

Using withCount() attribute:

```php
//For Favoriter
$users = User::withCount('favorites')->get();

foreach($users as $user) {
    echo $user->favorites_count;
}


// For Favoriteable
$posts = Post::withCount('favoriters')->get();

foreach($posts as $post) {
    echo $post->favoriters_count;
}
```


### N+1 Issue


To optimize query performance and avoid N+1 issues, use eager loading. Specify the desired relationships to be loaded with the with method.


```php

// Favoriter
$users = User::with('favorites')->get();

foreach($users as $user) {
    $user->hasFavorited($post);
}


// Favoriteable
$posts = Post::with('favorites')->get();

foreach($posts as $post) {
    $post->isFavoritedBy($user);
}

```