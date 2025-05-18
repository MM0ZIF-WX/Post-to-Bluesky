<?php
if (!defined('ABSPATH')) {
    exit;
}

class BlueskyPoster {
    private $username;
    private $password;
    private $api_base = 'https://bsky.social/xrpc';

    public function __construct($username, $password) {
        $this->username = $username;
        $this->password = $password;
        $this->log_debug("BlueskyPoster initialized for user: " . sanitize_text_field($username));
    }

    public function postContent($text, $image = '', $link = '') {
        $this->log_debug('Attempting to post content');
        $session = $this->createSession();
        if (!$session || !isset($session['accessJwt'])) {
            throw new Exception('Failed to create session');
        }

        $post = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => gmdate('c'),
        ];

        if (!empty($link)) {
            $post['embed'] = [
                '$type' => 'app.bsky.embed.external',
                'external' => [
                    'uri' => $link,
                    'title' => 'Live Weather Data',
                    'description' => 'Latest weather updates from MM0ZIF_WX'
                ]
            ];
        }

        $response = $this->apiRequest('com.atproto.repo.createRecord', [
            'repo' => $session['did'],
            'collection' => 'app.bsky.feed.post',
            'record' => $post
        ], $session['accessJwt']);

        $this->log_debug("Post API response: " . print_r($response, true));
        return $response;
    }

    public function getPostInteractions($post_uri, $limit) {
        $this->log_debug("Fetching interactions for post: $post_uri");
        $session = $this->createSession();
        if (!$session || !isset($session['accessJwt'])) {
            throw new Exception('Failed to create session for interactions');
        }

        $response = $this->apiRequest('app.bsky.feed.getPostThread', [
            'uri' => $post_uri,
            'depth' => 1
        ], $session['accessJwt']);

        $interactions = [];
        if (isset($response['thread']['replies']) && is_array($response['thread']['replies'])) {
            foreach ($response['thread']['replies'] as $reply) {
                if (isset($reply['post'])) {
                    $interactions[] = [
                        'author' => $reply['post']['author']['displayName'] ?? $reply['post']['author']['handle'],
                        'text' => $reply['post']['record']['text'],
                        'time' => $reply['post']['record']['createdAt'],
                        'likes' => $reply['post']['likeCount'] ?? 0,
                        'reposts' => $reply['post']['repostCount'] ?? 0
                    ];
                }
            }
        }

        $this->log_debug("Interactions fetched: " . print_r($interactions, true));
        return array_slice($interactions, 0, $limit);
    }

    private function createSession() {
        $this->log_debug('Creating Bluesky session');
        $response = wp_remote_post($this->api_base . '/com.atproto.server.createSession', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'identifier' => $this->username,
                'password' => $this->password
            ]),
            'timeout' => 30,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            $error = 'HTTP Error: ' . $response->get_error_message();
            $this->log_debug("Session creation failed: $error");
            throw new Exception($error);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200) {
            $error = isset($body['error']) ? $body['error'] : "HTTP $response_code - Unknown error";
            $this->log_debug("Session creation failed: $error");
            throw new Exception("Session creation failed: $error");
        }

        $this->log_debug('Session created successfully');
        return $body;
    }

    private function apiRequest($method, $data, $jwt) {
        $this->log_debug("API request to $method with data: " . print_r($data, true));
        $response = wp_remote_post($this->api_base . '/' . $method, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $jwt
            ],
            'body' => json_encode($data),
            'timeout' => 30,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            $error = 'HTTP Error: ' . $response->get_error_message();
            $this->log_debug("API request failed: $error");
            throw new Exception($error);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200) {
            $error = isset($body['error']) ? $body['error'] : "HTTP $response_code - Unknown error";
            $this->log_debug("API request failed: $error");
            throw new Exception("API Error: $error");
        }

        $this->log_debug("API request successful");
        return $body;
    }

    private function log_debug($message) {
        $log = get_option('bluesky_debug_log', []);
        $log[] = [
            'time' => current_time('mysql'),
            'message' => is_string($message) ? $message : print_r($message, true)
        ];
        if (count($log) > 50) {
            $log = array_slice($log, -50);
        }
        update_option('bluesky_debug_log', $log, false);
        error_log('BlueskyPoster: ' . $message);
    }
}
?>