<?php

// Load Composer dependencies
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    throw new \Exception('please run "composer require google/apiclient:~2.0" in "' . __DIR__ .'"');
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * A wrapper around Youtube Data API service and Google OAuth2 service PHP libraries.
 * Only functionality is adding or removing videos from user's playlist via Youtube API.
 * Saves and automatically refreshes OAuth2 access token so user interaction is required only once.
 * Processes are logged and log is rotated.
 */
class YoutubePlaylistHandler 
{

    const CONFIG_PATH = __DIR__ . '/yph_config.json';
    const TOKEN_FILE_PATH = __DIR__ . '/yph_token.json';
    const LOG_PATH = __DIR__ . '/log/yph.log';
    const LOG_ROTATIONS = 14;
    const LOG_DEBUG = 'DEBUG';
    const LOG_ERROR = 'ERROR';

    private static $client; 
    private static $service;
    private static $config;

    public function __construct() {

        // Start session if not running already
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        self::$config = isset(self::$config) ? self::$config : $this->createConfigFromFile(self::CONFIG_PATH);
        self::$client = isset(self::$client) ? self::$client : $this->createClient();
        self::$service = isset(self::$service) ? self::$service : new Google_Service_YouTube(self::$client);

    }

    /**
     * Creates config object from JSON file. Checks for required config entries.
     *
     * @param string $path Path to JSON file
     * @return object Object parsed from JSON config file
     */
    private function createConfigFromFile($path) {

        $configString = file_get_contents($path);
        $config = json_decode($configString);
        $required = [
            'clientId',
            'clientSecret',
            'redirectUrl',
        ];

        foreach ($required as $property) {

            if (!isset($config->$property)) {
                $this->log("Missing $property from config.");
                throw new \Exception("Missing $property from config.");
            }

        }

        return $config;

    }

    private function log($message, $severity = 'INFO') {

        // Rotate day old log
        $this->rotateLog(self::LOG_PATH);
        // Default timezone is GMT +0
        $time = new \DateTime('now', new \DateTimeZone('Europe/London'));
        $entry = "[$severity] {$time->format('Y-m-d H:i:s:.u')}: " . strval($message) . PHP_EOL;

        return file_put_contents(self::LOG_PATH, $entry , FILE_APPEND | LOCK_EX);

    }

    private function rotateLog($path) {

        if (file_exists($path)) {

            if (date ("Y-m-d", filemtime($path)) !== date('Y-m-d')) {

                if (file_exists($path . "." . self::LOG_ROTATIONS)) {
                    unlink($path . "." . self::LOG_ROTATIONS);
                }

                for ($i = self::LOG_ROTATIONS; $i > 0; $i--) {

                    if (file_exists($path . "." . $i)) {
                        $next = $i+1;
                        rename($path . "." . $i, $path . "." . $next);
                    }

                }

                rename($path, $path . ".1");

            }

        }

    }

    /**
     * Creates new Google Client object used for OAuth2 authentication.
     *
     * @return Google_Client Client object used for authentication.
     */
    private function createClient() {

        $client = new Google_Client();
        $client->setClientId(self::$config->clientId);
        $client->setClientSecret(self::$config->clientSecret);
        $client->setScopes(Google_Service_YouTube::YOUTUBE);
        $client->setRedirectUri(self::$config->redirectUrl);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');

        return $client;

    }

    /**
     * Checks authentication on OAuth2 client. If client doesn't have
     * a token, tries to get a saved or new one. Also handles automatic
     * refresh of expired token.
     * If auth fails, it can optionally redirect user to Google authentication page.
     *
     * @param boolean $redirect Redirect user to Google auth. on auth fail?
     * @return boolean Returns true if auth passed
     */
    public function auth($redirect = false) {

        // Client already has access token and is authenticated
        if (!is_null(self::$client->getAccessToken())) {
            $this->log('Client already has a token.');

            return true;
        }

        // Get saved access token from file or redirect to Google authentication
        // and fetch a new one from given code
        try {
            $accessToken = $this->getAccessToken($redirect);
        }
        catch (\Exception $e) {
            $this->log("Error when getting token: {$e->getMessage()}");
            throw $e;
        }

        // Try to auth using the access token
        self::$client->setAccessToken($accessToken);

        // Refresh access token if it's expired
        if (self::$client->isAccessTokenExpired()) {
            $this->log('Token expired. Refreshing token.');
            self::$client->refreshToken(self::$client->getRefreshToken());
            $freshAccessToken = self::$client->getAccessToken();

            if (!is_null($freshAccessToken)) {
                file_put_contents(self::TOKEN_FILE_PATH, json_encode($freshAccessToken));
                $this->log('Token refreshed and saved.');
            }
            else {
                $this->log('Token refresh failed.', self::LOG_ERROR);
                throw new \Exception('Access token refresh failed.');
            }
            
        }

        if ($redirect) {
            echo 'Authentication token saved.';
        }

        return true;

    }

    /**
     * Returns access token from file or tries to fetch a new one from OAuth2 client 
     * using given auth code.
     * If theres no token to load or no code, can optionally redirect user to Google auth. page.
     *
     * @param boolean $redirect Redirect user to Google auth. page when no token accessible
     * @return array Access token
     */
    private function getAccessToken($redirect = false) {

        if (file_exists(self::TOKEN_FILE_PATH)) {
            $this->log("Fetching token from file.");

            // If file with saved token is available, load that file contents as access token
            return $this->loadAccessToken();
        }
        elseif (isset($_GET['code'])) {
            $this->log('No token file. Code given.');

            // Check if returned state matches state sent with client auth request
            if (!isset($_GET['state']) || strval($_SESSION['state']) !== strval($_GET['state'])) {
                $this->log('Auth failed. State mismatch.', self::LOG_ERROR);
                throw new \Exception('Authentification failed. State mismatch.');
            }

            // If token file is not available and "code" query parameter is set, 
            // try to authenticate using the code
            $newToken = $this->fetchNewAccessToken($_GET['code']);
            unset($_SESSION['state']);
            
            return $newToken;
        }
        elseif ($redirect) {
            // Send random string set as state to verify response origin
            $state = mt_rand();
            self::$client->setState($state);
            $_SESSION['state'] = $state;
            // No token file available and no "code" parameter is set,
            // get authentication URL and redirect
            $authUrl = self::$client->createAuthUrl();
            $this->log('No token. State set. Do Google auth.');
            $this->redirect($authUrl);
        }
        else {
            throw new \Exception('Client has to be authorized.');
        }

    }

    /**
     * Load access token from JSON file
     *
     * @return array Parsed access token from file
     */
    private function loadAccessToken() {

        try {
            $savedAccessTokenString = file_get_contents(self::TOKEN_FILE_PATH);
            $this->log('File token loaded.');
        }
        catch (\Exception $e) {
            $this->log("Failed to open token file. {$e->getMessage()}", self::LOG_ERROR);
            throw new \Exception("Failed to open token file. {$e->getMessage()}");
        }
        
        return json_decode($savedAccessTokenString, true);

    }

    /**
     * Fetch new access token from OAuth2 client by given access code.
     * Save fetched access token to file.
     *
     * @param string $accessCode Access code
     * @return array New fetched access token
     */
    private function fetchNewAccessToken($accessCode) {

        self::$client->authenticate($accessCode);
        $accessToken = self::$client->getAccessToken();

        // Code has been accepted and new access token is returned,
        // save the new access token in token file
        if (!is_null($accessToken)) {
            file_put_contents(self::TOKEN_FILE_PATH, json_encode($accessToken));
            $this->log('New token acquired. Saved to file.');
            $this->redirect(self::$config->redirectUrl);
        }
        else {
            $this->log('Invalid code.', self::LOG_ERROR);            
            throw new \Exception('Invalid code given.');
        }

        return $accessToken;

    }

    /**
     * Redirect user to given URL.
     *
     * @param string $url URL to redirect to
     * @return void
     */
    private function redirect($url) {

        $this->log('Redirecting.');
        $cleanUrl = filter_var($url, FILTER_SANITIZE_URL);
        header("Location: $cleanUrl");
        exit();

    }

    /**
     * Adds nested property of value to object when given string with dot annotation.
     *
     * @param object &$ref Object reference
     * @param string $property String with property dot annotation
     * @param mixed $value Property value
     * @return void
     */
    private function addPropertyToResource(&$ref, $property, $value) {

        $keys = explode('.', $property);
        $is_array = false;

        foreach ($keys as $key) {

            if (substr($key, -2) == "[]") {
                $key = substr($key, 0, -2);
                $is_array = true;
            }
            $ref = &$ref[$key];

        }

        // Set the property value.
        if ($is_array && $value) {
            $ref = $value;
            $ref = explode(",", $value);
        } 
        elseif ($is_array) {
            $ref = array();
        } 
        else {
            $ref = $value;
        }

    }

    /**
     * Build a resource based on a list of properties given as key-value pairs.
     *
     * @param array $properties Object properties
     * @return Google_Service_YouTube_PlaylistItem New playlist item resource
     */
    private function createResource($properties) {

        $resource = array();

        foreach ($properties as $prop => $value) {
            if ($value) {
                $this->addPropertyToResource($resource, $prop, $value);
            }
        }

        return new Google_Service_YouTube_PlaylistItem($resource);
    }

    /**
     * Returns items of playlist given it's ID. Loops through all pages
     *  to get all the resulting items.
     *
     * @param string $playlistId ID of playlist
     * @return array Array containing playlist items
     */
    private function getPlaylistItems($playlistId) {

        $items = [];

        do {
            $options = [
                'playlistId' => $playlistId,
                'maxResults' => 25,
            ];

            if (isset($result) && !empty($result) && $result->nextPageToken) {
                $options['pageToken'] = $result->nextPageToken;
            }

            $result = self::$service
                ->playlistItems
                ->listPlaylistItems('snippet,contentDetails', $options);

            $items = array_merge($items, $result->getItems());
        }
        while (isset($result) && !empty($result) && isset($result->nextPageToken));

        return $items;

    }

    /**
     * Add video to playlist given public video ID (from Youtube video URL).
     * Doesn't add duplicate videos. If no playlist ID is specified, it is taken from
     * config file.
     *
     * @param string $videoId Public video ID
     * @param string $playlistId Public playlist ID
     * @return YoutubePlaylistHandler Returns self for method chaining
     */
    public function addToPlaylist($videoId, $playlistId = null) {

        // Check authentication
        $this->auth();

        // Set default playlist ID from config if not given
        if (!isset($playlistId) && isset(self::$config->playlistId)) {
            $playlistId = self::$config->playlistId;
        }
        else if (!isset($playlistId)) {
            throw new \Exception('No playlist ID given.');
        }

        try {
            $this->log("Adding video \"$videoId\" to playlist");

            // Get all videos in playlist
            $playlistItems = $this->getPlaylistItems($playlistId);

            // Get IDs of all videos in playlist
            $videoIds = array_map(function ($video) {

                return  $video->getContentDetails()->getVideoId();

            }, $playlistItems);

            // Don't add video that already is in the playlist
            if (in_array($videoId, $videoIds)) {
                $this->log("Duplicate video \"$videoId\".");
            }
            else {
                // Resource object has to be created first
                $videoResource = $this->createResource([
                    'snippet.playlistId' => $playlistId,
                    'snippet.resourceId.kind' => 'youtube#video',
                    'snippet.resourceId.videoId' => $videoId,
                    'snippet.position' => ''
                ]);
    
                // Append video into playlist
                $insertVideo = self::$service
                    ->playlistItems
                    ->insert('snippet', $videoResource);

                $this->log("Video \"$videoId\" added.");
            }

            return $this;
        }
        catch (Google_Exception $e) {
            $exception = json_decode($e->getMessage());
            $this->log("Client error: {$e->getMessage()}", self::LOG_ERROR);
            throw $e;
        }
        catch (Google_Service_Exception $e) {
            $exception = json_decode($e->getMessage());
            $this->log("Service error: {$e->getMessage()}", self::LOG_ERROR);
            throw $e;
        }
        catch (\Exception $e) {
            $this->log("Error occured: {$e->getMessage()}", self::LOG_ERROR);
            throw $e;
        }

    }

    /**
     * Removes video from playlist given public video ID (from Youtube video URL).
     * Checks if video is in playlist before removing. If no playlist ID is given,
     * playlist ID from config is taken.
     *
     * @param string $videoId Public video ID
     * @param string $playlistId Public playlist ID
     * @return YoutubePlaylistHandler Returns self for method chaining
     */
    public function removeFromPlaylist($videoId, $playlistId = null) {

        // Check authentication
        $this->auth();

        // Set default playlist ID from config if not given
        if (!isset($playlistId) && isset(self::$config->playlistId)) {
            $playlistId = self::$config->playlistId;
        }
        else if (!isset($playlistId)) {
            throw new \Exception('No playlist ID given.');
        }

        try {
            $this->log("Removing video: $videoId from playlist.");

            // Get all videos in the playlist
            $playlistItems = $this->getPlaylistItems($playlistId);

            // Get playlist item with matching video ID
            $playlistItemId = array_reduce($playlistItems, function ($acc, $video) use ($videoId) {

                if ($video->getContentDetails()->getVideoId() === $videoId) {
                    $acc = $video->getId();
                }

                return $acc;

            });

            // Don't try to remove video that is not in the playlist
            if (is_null($playlistItemId)) {
                $this->log("Video: \"$videoId\" not in playlist.");
            }
            else {
                // Remove video from playlist
                $removedVideo = self::$service
                    ->playlistItems
                    ->delete($playlistItemId);

                $this->log("Video: \"$videoId\" removed.");
            }
            
            return $this;
        }
        catch (Google_Exception $e) {
            $exception = json_decode($e->getMessage());
            $this->log("Client error: {$e->getMessage()}", self::LOG_ERROR);
            throw $e;
        }
        catch (Google_Service_Exception $e) {
            $exception = json_decode($e->getMessage());
            $this->log("Service error: {$e->getMessage()}", self::LOG_ERROR);
            throw $e;
        }
        catch (\Exception $e) {
            $this->log("Error occured: {$e->getMessage()}", self::LOG_ERROR);
            throw $e;
        }

    }

}

/**
 * Functions wrapping usage of class methods for procedural approach
 */

/**
 * Adds video to playlist given video public ID (from Youtube video URL).
 * If no playlist ID is given, it's taken from the config file.
 * Checks if video is in playlist before trying to add it.
 *
 * @param string $videoId Public video ID
 * @param string $playlistId Public playlist ID
 * @return boolean Adding success
 */
function yph_add_to_playlist($videoId, $playlistId = null) {


    try {
        $youtube = new YoutubePlaylistHandler();
        $youtube->addToPlaylist($videoId, $playlistId);

        return true;
    }
    catch (\Exception $e) {
        return false;
    }


}

/**
 * Removes video from playlist given video public ID (from Youtube video URL).
 * If no playlist ID is given, it's taken from the config file.
 * Checks if video even is in playlist before removing it.
 *
 * @param string $videoId Public video ID
 * @param string $playlistId Public playlist ID
 * @return boolean Removal success
 */
function yph_remove_from_playlist($videoId, $playlistId = null) {

    try {
        $youtube = new YoutubePlaylistHandler();
        $youtube->removeFromPlaylist($videoId, $playlistId);

        return true;
    }
    catch (\Exception $e) {
        return false;
    }

}

/**
 * Checks client authentication token and redirect user to Google Auth page
 * if token is invalid or no token is found.
 * Resulting new token is saved to file.
 *
 * @return void
 */
function yph_auth() {

    try {
        $youtube = new YoutubePlaylistHandler();
        $youtube->auth(true);
    }
    catch (\Exception $e) {
        http_response_code(500);
        die("Error occured during authentication:\n{$e->getMessage()}");
    }

}