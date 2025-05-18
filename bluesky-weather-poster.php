<?php
/*
Plugin Name: Bluesky Weather Poster
Description: Posts weather updates from clientraw.txt to Bluesky with custom scheduling and interaction display
Version: 1.8
Author: Marcus Hazel-McGown - MM0ZIF
Homepage: https://mm0zif.radio - Contact: marcus@mm0zif.radio 
*/

if (!defined('ABSPATH')) {
    exit;
}

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>Bluesky Weather Poster requires PHP 7.4 or higher. Current version: ' . PHP_VERSION . '</p></div>';
    });
    return;
}

// Include required files safely
$required_files = [
    __DIR__ . '/class-bluesky-poster.php',
    __DIR__ . '/class-clientraw-parser.php'
];
foreach ($required_files as $file) {
    if (!file_exists($file)) {
        add_action('admin_notices', function() use ($file) {
            echo '<div class="notice notice-error"><p>Bluesky Weather Poster error: Missing file ' . esc_html(basename($file)) . '</p></div>';
        });
        return;
    }
    require_once $file;
}

class BlueskyWeatherPosterPlugin {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        try {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('bluesky_weather_post_event', [$this, 'post_weather_update']);
            add_action('wp_ajax_test_bluesky_post', [$this, 'post_weather_update']);
            add_action('admin_post_clear_bluesky_log', [$this, 'clear_debug_log']);
            add_action('admin_post_bluesky_manual_cron', [$this, 'manual_cron']);
            add_filter('cron_schedules', [$this, 'setup_custom_schedule']);
            add_shortcode('bluesky_weather_feed', [$this, 'render_bluesky_feed']);
            add_action('admin_notices', [$this, 'check_cron_status']);
        } catch (Exception $e) {
            $this->log_debug('Plugin initialization failed: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>Bluesky Weather Poster initialization error: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }

    public function check_cron_status() {
        $next_cron = wp_next_scheduled('bluesky_weather_post_event');
        if (!$next_cron && $this->is_settings_page()) {
            echo '<div class="notice notice-error is-dismissible"><p>Bluesky Weather Poster: Cron job is not scheduled. Save settings or run cron manually to schedule posts. Check Debug Log for details.</p></div>';
            $this->log_debug('Cron check: No scheduled event found');
        }
    }

    private function is_settings_page() {
        return isset($_GET['page']) && $_GET['page'] === 'bluesky-weather-poster';
    }

    public function setup_custom_schedule($schedules) {
        $interval = get_option('bluesky_post_interval', 60);
        $interval = max(30, intval($interval));
        $schedules['bluesky_custom'] = [
            'interval' => $interval * 60,
            'display' => sprintf('Every %d minutes', $interval)
        ];
        $this->log_debug("Custom schedule set: Every $interval minutes");
        return $schedules;
    }

    public function add_admin_menu() {
        add_options_page(
            'Bluesky Weather Poster Settings',
            'Bluesky Poster',
            'manage_options',
            'bluesky-weather-poster',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        $settings = [
            'bluesky_username' => 'sanitize_text_field',
            'bluesky_password' => 'sanitize_text_field',
            'clientraw_url' => ['sanitize_callback' => 'esc_url_raw'],
            'weather_live_url' => ['sanitize_callback' => 'esc_url_raw'],
            'weather_location' => 'sanitize_text_field',
            'website_url' => ['sanitize_callback' => 'esc_url_raw'],
            'bluesky_post_times' => ['sanitize_callback' => [$this, 'sanitize_post_times']],
            'bluesky_post_interval' => ['sanitize_callback' => [$this, 'sanitize_post_interval']],
            'bluesky_feed_limit' => ['sanitize_callback' => 'absint']
        ];

        foreach ($settings as $option => $callback) {
            register_setting('bluesky_weather_poster_options', $option, is_array($callback) ? $callback : ['sanitize_callback' => $callback]);
        }

        add_action('update_option_bluesky_post_times', function($old_value, $new_value) {
            $this->reschedule_cron();
        }, 10, 2);
        add_action('update_option_bluesky_post_interval', function($old_value, $new_value) {
            $this->reschedule_cron();
        }, 10, 2);
    }

    public function sanitize_post_times($value) {
        if (empty($value)) {
            $this->log_debug('Post times sanitized: Empty');
            return '';
        }
        $times = array_map('trim', explode(',', $value));
        $valid_times = [];
        foreach ($times as $time) {
            if (preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                $valid_times[] = $time;
            }
        }
        $result = implode(',', $valid_times);
        $this->log_debug("Post times sanitized: $result");
        return $result;
    }

    public function sanitize_post_interval($value) {
        $value = absint($value);
        $sanitized = $value >= 30 ? $value : 60;
        $this->log_debug("Post interval sanitized: $sanitized minutes");
        return $sanitized;
    }

    private function reschedule_cron() {
        wp_clear_scheduled_hook('bluesky_weather_post_event');
        $post_times = get_option('bluesky_post_times', '');
        $interval = get_option('bluesky_post_interval', 60);
        $this->log_debug("Rescheduling cron with post_times: '$post_times', interval: $interval");

        if (!empty($post_times)) {
            $times = explode(',', $post_times);
            foreach ($times as $time) {
                if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                    $this->log_debug("Invalid time format: $time");
                    continue;
                }
                list($hour, $minute) = explode(':', $time);
                $next = strtotime("today $hour:$minute");
                if ($next < time()) {
                    $next = strtotime("tomorrow $hour:$minute");
                }
                wp_schedule_event($next, 'daily', 'bluesky_weather_post_event');
                $this->log_debug("Scheduled post at $time daily, next run: " . date('Y-m-d H:i:s', $next));
            }
        } else {
            wp_schedule_event(time() + 60, 'bluesky_custom', 'bluesky_weather_post_event');
            $this->log_debug("Scheduled posts every $interval minutes, next run: " . date('Y-m-d H:i:s', time() + 60));
        }

        $next_cron = wp_next_scheduled('bluesky_weather_post_event');
        if (!$next_cron) {
            $this->log_debug('Cron scheduling failed: No event scheduled');
        } else {
            $this->log_debug('Cron scheduled successfully, next run: ' . date('Y-m-d H:i:s', $next_cron));
        }
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
        error_log('Bluesky Weather Poster: ' . $message);
    }

    private function log_upload($status, $details = '') {
        $log = get_option('bluesky_upload_log', []);
        $log[] = [
            'time' => current_time('mysql'),
            'status' => $status,
            'details' => is_string($details) ? $details : print_r($details, true)
        ];
        if (count($log) > 50) {
            $log = array_slice($log, -50);
        }
        update_option('bluesky_upload_log', $log, false);
    }

    public function clear_debug_log() {
        if (!isset($_POST['clear_bluesky_log']) || !check_admin_referer('clear_bluesky_log')) {
            $this->log_debug('Clear debug log failed: Invalid nonce');
            wp_redirect(admin_url('options-general.php?page=bluesky-weather-poster'));
            exit;
        }
        update_option('bluesky_debug_log', [], false);
        set_transient('bluesky_admin_notice', [
            'message' => 'Debug log cleared.',
            'type' => 'success'
        ], 45);
        wp_redirect(admin_url('options-general.php?page=bluesky-weather-poster'));
        exit;
    }

    public function manual_cron() {
        if (!isset($_POST['bluesky_manual_cron']) || !check_admin_referer('bluesky_manual_cron')) {
            $this->log_debug('Manual cron failed: Invalid nonce');
            wp_redirect(admin_url('options-general.php?page=bluesky-weather-poster'));
            exit;
        }
        $this->post_weather_update();
        $this->reschedule_cron();
        set_transient('bluesky_admin_notice', [
            'message' => 'Manual cron executed and cron rescheduled. Check debug log for results.',
            'type' => 'success'
        ], 45);
        wp_redirect(admin_url('options-general.php?page=bluesky-weather-poster'));
        exit;
    }

    public function settings_page() {
        $this->log_debug('Loading Bluesky Weather Poster settings page');
        $next_cron = wp_next_scheduled('bluesky_weather_post_event');
        $post_times = get_option('bluesky_post_times', '');
        $post_interval = get_option('bluesky_post_interval', 60);
        $feed_limit = get_option('bluesky_feed_limit', 5);

        // Trigger a test fetch to populate upload log
        $clientraw_url = get_option('clientraw_url');
        if ($clientraw_url) {
            try {
                $clientraw = new ClientrawParser($clientraw_url);
                $this->log_debug('Test fetch triggered on settings page load');
            } catch (Exception $e) {
                $this->log_debug('Test fetch failed: ' . $e->getMessage());
            }
        }
        ?>
        <div class="wrap">
            <h2>Bluesky Weather Poster Settings</h2>
            <div class="notice notice-info">
                <p>Configure Bluesky credentials, weather data sources, post scheduling, and interaction display. Use Test Post to verify settings.</p>
            </div>
            <h2 class="nav-tab-wrapper">
                <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
                <a href="#debug" class="nav-tab">Debug Log</a>
                <a href="#cron" class="nav-tab">Cron Status</a>
                <a href="#interactions" class="nav-tab">Bluesky Interactions</a>
            </h2>

            <!-- Settings Tab -->
            <div id="settings" class="tab-content">
                <?php $this->log_debug('Rendering Settings tab'); ?>
                <form method="post" action="options.php">
                    <?php 
                    settings_fields('bluesky_weather_poster_options');
                    do_settings_sections('bluesky-weather-poster');
                    ?>
                    <table class="form-table">
                        <tr>
                            <th>Bluesky Username</th>
                            <td>
                                <input type="text" name="bluesky_username" class="regular-text" 
                                       value="<?php echo esc_attr(get_option('bluesky_username')); ?>" />
                                <p class="description">Your Bluesky account username (e.g., @mm0zif.bsky.social)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Bluesky Password</th>
                            <td>
                                <input type="password" name="bluesky_password" class="regular-text" 
                                       value="<?php echo esc_attr(get_option('bluesky_password')); ?>" />
                                <p class="description">Your Bluesky app-specific password</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Weather Location</th>
                            <td>
                                <input type="text" name="weather_location" class="regular-text" 
                                       value="<?php echo esc_attr(get_option('weather_location')); ?>" />
                                <p class="description">Your weather station location (e.g., Dundee, Scotland)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Website URL</th>
                            <td>
                                <input type="url" name="website_url" class="regular-text" 
                                       value="<?php echo esc_attr(get_option('website_url')); ?>" />
                                <p class="description">Your website URL (e.g., https://mm0zif.radio/)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Clientraw.txt URL</th>
                            <td>
                                <input type="url" name="clientraw_url" class="regular-text" 
                                       value="<?php echo esc_attr(get_option('clientraw_url')); ?>" />
                                <p class="description">Full URL to clientraw.txt (e.g., https://mm0zif.radio/clientraw.txt)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Live Weather URL</th>
                            <td>
                                <input type="url" name="weather_live_url" class="regular-text" 
                                       value="<?php echo esc_attr(get_option('weather_live_url')); ?>" />
                                <p class="description">URL to live weather page (e.g., https://mm0zif.radio/current/WX/)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Specific Post Times</th>
                            <td>
                                <input type="text" name="bluesky_post_times" id="bluesky_post_times" class="regular-text" 
                                       value="<?php echo esc_attr($post_times); ?>" placeholder="e.g., 08:00,12:00,18:00" />
                                <p class="description">Enter times in 24-hour format (HH:MM), separated by commas. Posts daily at these times. Leave blank to use interval below.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Post Interval</th>
                            <td>
                                <input type="number" name="bluesky_post_interval" id="bluesky_post_interval" class="regular-text" 
                                       value="<?php echo esc_attr($post_interval); ?>" min="30" step="1" />
                                <p class="description">Minutes between posts if no specific times are set (minimum 30 minutes). Used when Specific Post Times is blank.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Feed Display Limit</th>
                            <td>
                                <input type="number" name="bluesky_feed_limit" class="regular-text" 
                                       value="<?php echo esc_attr($feed_limit); ?>" min="1" max="20" />
                                <p class="description">Number of Bluesky interactions (replies, likes) to display (1-20).</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </form>
                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3>Test Your Configuration</h3>
                    <p>Click to test your settings. Check Debug Log for detailed errors if the test fails.</p>
                    <button class="button button-primary" onclick="testPost()">Test Post</button>
                    <div id="test-result" style="margin-top: 15px;"></div>
                </div>
            </div>

            <!-- Debug Log Tab -->
            <div id="debug" class="tab-content" style="display:none;">
                <h2>Debug Log</h2>
                <p>Recent plugin activity (last 50 entries):</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $log = get_option('bluesky_debug_log', []);
                        if (empty($log)) {
                            echo '<tr><td colspan="2">No debug entries yet.</td></tr>';
                        } else {
                            foreach (array_reverse($log) as $entry) {
                                echo '<tr><td>' . esc_html($entry['time']) . '</td><td><pre>' . esc_html($entry['message']) . '</pre></td></tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="clear_bluesky_log" />
                    <input type="hidden" name="clear_bluesky_log" value="1" />
                    <?php wp_nonce_field('clear_bluesky_log'); ?>
                    <?php submit_button('Clear Debug Log', 'secondary', 'clear_bluesky_log_submit', false); ?>
                </form>
            </div>

            <!-- Cron Status Tab -->
            <div id="cron" class="tab-content" style="display:none;">
                <h2>Cron Status</h2>
                <p><strong>Schedule:</strong> <?php echo $post_times ? esc_html("At $post_times daily") : esc_html("Every $post_interval minutes"); ?></p>
                <p><strong>Next Scheduled Run:</strong> <?php echo $next_cron ? esc_html(date('Y-m-d H:i:s', $next_cron)) : 'Not scheduled'; ?></p>
                <p><strong>Upload Log (last 10 entries):</strong></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $upload_log = get_option('bluesky_upload_log', []);
                        if (empty($upload_log)) {
                            echo '<tr><td colspan="3">No upload entries yet.</td></tr>';
                        } else {
                            foreach (array_slice(array_reverse($upload_log), 0, 10) as $entry) {
                                echo '<tr><td>' . esc_html($entry['time']) . '</td><td>' . esc_html($entry['status']) . '</td><td>' . esc_html($entry['details']) . '</td></tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="bluesky_manual_cron" />
                    <?php wp_nonce_field('bluesky_manual_cron'); ?>
                    <?php submit_button('Run Cron Manually', 'secondary', 'bluesky_manual_cron', false); ?>
                </form>
            </div>

            <!-- Bluesky Interactions Tab -->
            <div id="interactions" class="tab-content" style="display:none;">
                <h2>Bluesky Interactions</h2>
                <p>Recent replies and likes on your weather posts (last <?php echo esc_html($feed_limit); ?> entries):</p>
                <?php echo $this->render_bluesky_feed(); ?>
            </div>

            <style>
            .nav-tab { cursor: pointer; }
            .tab-content { margin-top: 20px; }
            .debug-output { background: #f8f9fa; border: 1px solid #ddd; padding: 15px; margin-top: 10px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; }
            .notice-error pre { max-height: 200px; overflow-y: auto; }
            .bluesky-feed { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 4px; }
            .bluesky-feed-meta { color: #555; font-size: 0.9em; }
            </style>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tabs = document.querySelectorAll('.nav-tab');
                const contents = document.querySelectorAll('.tab-content');
                tabs.forEach(tab => {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();
                        tabs.forEach(t => t.classList.remove('nav-tab-active'));
                        contents.forEach(c => c.style.display = 'none');
                        this.classList.add('nav-tab-active');
                        document.querySelector(this.getAttribute('href')).style.display = 'block';
                    });
                });
                jQuery('#test-result').on('click', '.dismiss-notice', function() {
                    jQuery(this).closest('.notice').remove();
                });
                if (document.getElementById('bluesky_post_times') && document.getElementById('bluesky_post_interval')) {
                    console.log('Bluesky Weather Poster: Scheduling fields loaded successfully');
                } else {
                    console.error('Bluesky Weather Poster: Scheduling fields (bluesky_post_times or bluesky_post_interval) not found');
                }
            });
            function testPost() {
                jQuery('#test-result').html('<div class="notice notice-info"><p><span class="dashicons dashicons-update spinning"></span> Testing post...</p></div>');
                jQuery.post(ajaxurl, {
                    action: 'test_bluesky_post'
                }).done(function(response) {
                    let output = '<div class="notice notice-success is-dismissible"><p><strong>Test post successful!</strong></p></div>';
                    output += '<div class="debug-output">';
                    output += '<strong>Weather Data:</strong>\n' + response.data.weather + '\n\n';
                    output += '<strong>API Response:</strong>\n' + JSON.stringify(response.data.api_response, null, 2);
                    output += '</div>';
                    jQuery('#test-result').html(output);
                }).fail(function(xhr, status, error) {
                    let message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ?
                        xhr.responseJSON.data.message : 'Unknown error. Check Debug Log for details.';
                    let debugInfo = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.debug ?
                        xhr.responseJSON.data.debug : error;
                    jQuery('#test-result').html(
                        '<div class="notice notice-error is-dismissible">' +
                        '<p><strong>Error:</strong> ' + message + '</p>' +
                        '<p><strong>Debug Info:</strong></p><pre>' + debugInfo + '</pre>' +
                        '<p>Verify your Bluesky credentials, clientraw.txt URL, and check Debug Log.</p>' +
                        '</div>'
                    );
                });
            }
            </script>
        </div>
        <?php
        $notice = get_transient('bluesky_admin_notice');
        if ($notice) {
            echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . wp_kses_post($notice['message']) . '</p></div>';
            delete_transient('bluesky_admin_notice');
        }
    }

    public function post_weather_update() {
        try {
            $clientraw_url = get_option('clientraw_url');
            $username = get_option('bluesky_username');
            $password = get_option('bluesky_password');

            if (empty($clientraw_url)) {
                throw new Exception('Clientraw.txt URL not configured');
            }
            if (empty($username) || empty($password)) {
                throw new Exception('Bluesky username or password not configured');
            }

            $this->log_debug('Starting weather update post');
            $clientraw = new ClientrawParser($clientraw_url);
            $weather_data = $clientraw->getWeatherData();
            if (!$weather_data) {
                throw new Exception('Failed to fetch or parse clientraw.txt data');
            }

            $location = get_option('weather_location', 'Unknown Location');
            $website = get_option('website_url', '');

            $weatherUpdate = "📍 $location\n\n";
            $weatherUpdate .= $clientraw->formatWeatherUpdate();
            $weatherUpdate .= " #weather";

            if (!empty($website)) {
                $weatherUpdate .= "\n\n🌐 $website";
            }

            $poster = new BlueskyPoster($username, $password);
            $response = $poster->postContent($weatherUpdate, '', get_option('weather_live_url'));

            // Store post URI for fetching interactions
            update_option('bluesky_last_post_uri', $response['uri'], false);

            $this->log_debug("Post successful:\nWeather: $weatherUpdate\nResponse: " . print_r($response, true));

            if (wp_doing_ajax()) {
                wp_send_json_success([
                    'weather' => $weatherUpdate,
                    'api_response' => $response,
                    'timestamp' => current_time('mysql')
                ]);
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->log_debug("Post failed: $error_message\nStack trace: " . $e->getTraceAsString());
            if (wp_doing_ajax()) {
                wp_send_json_error([
                    'message' => $error_message,
                    'timestamp' => current_time('mysql'),
                    'debug' => $e->getTraceAsString()
                ]);
            }
        }
    }

    public function render_bluesky_feed($atts = []) {
        $atts = shortcode_atts(['limit' => get_option('bluesky_feed_limit', 5)], $atts);
        $limit = max(1, min(20, absint($atts['limit'])));

        try {
            $username = get_option('bluesky_username');
            $password = get_option('bluesky_password');
            $last_post_uri = get_option('bluesky_last_post_uri', '');

            if (empty($username) || empty($password) || empty($last_post_uri)) {
                return '<p>No Bluesky interactions available. Ensure a post has been made and credentials are set.</p>';
            }

            $cache_key = 'bluesky_feed_' . md5($last_post_uri . $limit);
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }

            $poster = new BlueskyPoster($username, $password);
            $interactions = $poster->getPostInteractions($last_post_uri, $limit);

            if (empty($interactions)) {
                return '<p>No interactions found for the latest post.</p>';
            }

            $output = '<div class="bluesky-feed-container">';
            foreach ($interactions as $interaction) {
                $output .= '<div class="bluesky-feed">';
                $output .= '<p><strong>' . esc_html($interaction['author']) . '</strong>: ' . esc_html($interaction['text']) . '</p>';
                $output .= '<div class="bluesky-feed-meta">';
                $output .= '<span>Posted: ' . esc_html($interaction['time']) . '</span>';
                if (!empty($interaction['likes'])) {
                    $output .= '<span> | Likes: ' . esc_html($interaction['likes']) . '</span>';
                }
                if (!empty($interaction['reposts'])) {
                    $output .= '<span> | Reposts: ' . esc_html($interaction['reposts']) . '</span>';
                }
                $output .= '</div></div>';
            }
            $output .= '</div>';

            set_transient($cache_key, $output, 15 * MINUTE_IN_SECONDS);
            return $output;
        } catch (Exception $e) {
            $this->log_debug('Feed render failed: ' . $e->getMessage());
            return '<p>Error loading Bluesky interactions: ' . esc_html($e->getMessage()) . '</p>';
        }
    }

    public function activate() {
        try {
            $this->reschedule_cron();
            $this->log_debug('Plugin activated, cron scheduled');
        } catch (Exception $e) {
            $this->log_debug('Activation failed: ' . $e->getMessage());
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('bluesky_weather_post_event');
        $this->log_debug('Plugin deactivated, cron cleared');
    }
}

// Initialize plugin safely
try {
    if (class_exists('BlueskyPoster') && class_exists('ClientrawParser')) {
        $plugin = BlueskyWeatherPosterPlugin::get_instance();
        register_activation_hook(__FILE__, [$plugin, 'activate']);
        register_deactivation_hook(__FILE__, [$plugin, 'deactivate']);
    } else {
        throw new Exception('Required classes BlueskyPoster or ClientrawParser not found');
    }
} catch (Exception $e) {
    error_log('Bluesky Weather Poster initialization failed: ' . $e->getMessage());
    add_action('admin_notices', function() use ($e) {
        echo '<div class="notice notice-error"><p>Bluesky Weather Poster failed to initialize: ' . esc_html($e->getMessage()) . '</p></div>';
    });
}
?>