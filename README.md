# YoutubePlaylistHandler

## Description

The `YoutubePlaylistHandler` class and functions are used to add or remove a video to or from Youtube playlist.

Client authentication credentials and redirect URL have to be configured. Default playlist ID can be configured optionally.

## Configuration

Configuration file is `yph_config.json` in root directory.
These parameters have to be set:

1. `clientId` - Google OAuth2 client ID *
2. `clientSecret` - Google OAuth2 client secret *
3. `redirectUrl` - Redirect URL for Google OAuth2 *
4. `playlistId` - Default playlist ID

*\* required parameters*

## Usage

Usage has two parts:

1. Client authentication
2. Add/remove video to/from playlist

The authentication has to be done first. It has to be done only once. The resulting auth token is saved to file and used from then on. If the token expires, it's refreshed automatically when used.

It is recommended to call client authentication in a separate script.

Functions and methods from adding/removing accept **playlist ID** as an optional parameter. When no playlist ID is given, value from config file will be used.

**Include the YoutubePlaylistHandler main file first** before using any of it's functions:

```php
// Include YoutubePlaylistHandler
require_once('./YoutubePlaylistHandler.php');
```

### Using functions

#### Authentication

Function `yph_auth()` will check authentication token and redirect user to Google Auth page if token is invalid or no token is found. New auth token will be created upon returning from Google Auth page. The resulting token will be saved to file and used from now on.

```php
// Throws an exception and dies if error occured
yph_auth();
```

#### Add video to playlist

```php
// Returns false if error occured
$success = yph_add_to_playlist('video ID', 'optional playlist ID');
```

#### Remove from playlist

```php
// Returns false if error occured
$success = yph_remove_from_playlist('video ID', 'optional playlist ID');
```

### Using class methods

#### Authentication

Method `auth()` with parameter `true` will check authentication token and redirect user to Google Auth page if token is invalid or no token is found. New auth token will be created upon returning from Google Auth page. The resulting token will be saved to file and used from now on.

```php
$youtube = new YoutubePlaylistHandler();
$youtube->auth(true);
```

#### Add video to playlist

```php
$youtube = new YoutubePlaylistHandler();
$youtube->addToPlaylist('video ID', 'optional playlist ID');
```

#### Remove video from playlist

```php
$youtube = new YoutubePlaylistHandler();
$youtube->removeFromPlaylist('video ID', 'optional playlist ID');
```

#### Method chaining

Object method calls can be chained.

```php
$youtube = new YoutubePlaylistHandler();
$youtube
    ->removeFromPlaylist('1st video ID', 'optional playlist ID')
    ->addToPlaylist('2nd video ID', 'optional playlist ID')
    ->addToPlaylist('3rd video ID', 'different optional playlist ID')
    ->addToPlaylist('4th video ID', 'optional playlist ID');
```

## Logging

Default log file is `log/yph.log`. Logging is verbose, log file is rotated once a day and 14 past rotations are kept.
