<?php
if (!defined('ABSPATH')) {
    exit;
}

class ClientrawParser {
    private $clientraw_url;
    private $weather_data;

    public function __construct($clientraw_url) {
        $this->clientraw_url = $clientraw_url;
        $this->log_debug("ClientrawParser initialized with URL: $clientraw_url");
        $this->weather_data = $this->fetchWeatherData();
        $this->log_upload($this->weather_data ? 'Success' : 'Failed', $this->weather_data ? $this->weather_data : 'No data');
    }

    public function getWeatherData() {
        return $this->weather_data;
    }

    public function formatWeatherUpdate() {
        if (!$this->weather_data) {
            $this->log_debug('Format failed: No weather data');
            return 'Weather data unavailable';
        }

        $output = "🌡️ Temperature: {$this->weather_data['temperature']}°C\n";
        $output .= "💧 Humidity: {$this->weather_data['humidity']}%\n";
        $output .= "💨 Wind: {$this->weather_data['wind_speed']} km/h {$this->weather_data['wind_direction']}\n";

        $this->log_debug('Formatted weather update: ' . $output);
        return $output;
    }

    private function fetchWeatherData() {
        if (empty($this->clientraw_url)) {
            $this->log_debug('Fetch failed: Empty clientraw URL');
            return false;
        }

        $this->log_debug("Fetching clientraw.txt from: $this->clientraw_url");
        $response = wp_remote_get($this->clientraw_url, [
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'MM0ZIF_WX_WordPress_Plugin/1.8'
        ]);

        if (is_wp_error($response)) {
            $this->log_debug('Fetch failed: HTTP Error - ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->log_debug("Fetch failed: HTTP $response_code");
            return false;
        }

        $content = wp_remote_retrieve_body($response);
        if (empty($content)) {
            $this->log_debug('Fetch failed: Empty response');
            return false;
        }

        $data = explode(' ', trim($content));
        if (count($data) < 6) {
            $this->log_debug('Fetch failed: Invalid clientraw format - ' . print_r($data, true));
            return false;
        }

        $weather_data = [
            'temperature' => isset($data[4]) ? round(floatval($data[4]), 1) : 0,
            'humidity' => isset($data[5]) ? round(floatval($data[5])) : 0,
            'wind_speed' => isset($data[1]) ? round(floatval($data[1])) : 0,
            'wind_direction' => isset($data[3]) ? $this->getWindDirection($data[3]) : 'N/A'
        ];

        $this->log_debug('Weather data fetched: ' . print_r($weather_data, true));
        return $weather_data;
    }

    private function getWindDirection($degrees) {
        $degrees = floatval($degrees);
        $directions = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $index = round($degrees / 22.5) % 16;
        return $directions[$index];
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
        error_log('ClientrawParser: ' . $message);
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
        $this->log_debug("Upload log entry: Status=$status, Details=$details");
    }
}
?>