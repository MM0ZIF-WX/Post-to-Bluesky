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
        $this->log_upload(
            $this->weather_data ? 'Success' : 'Failed',
            $this->weather_data ? $this->weather_data : 'No data'
        );
    }

    public function getWeatherData() {
        return $this->weather_data;
    }

    /**
     * Format the expanded weather update for Bluesky
     */
    public function formatWeatherUpdate() {
        if (!$this->weather_data) {
            $this->log_debug('Format failed: No weather data');
            return 'Weather data unavailable';
        }

        $d = $this->weather_data;

        // Temperature + feels-like
        $temp_line = "🌡️ {$d['temperature']}°C";
        if (!empty($d['feels_like']) && $d['feels_like'] != $d['temperature']) {
            $temp_line .= " (feels like {$d['feels_like']}°C)";
        }

        $output  = $temp_line . "\n";
        $output .= "💧 Humidity: {$d['humidity']}%\n";
        $output .= "💨 Wind: {$d['wind_speed']} km/h {$d['wind_direction']}";

        if (!empty($d['wind_gust']) && $d['wind_gust'] > $d['wind_speed']) {
            $output .= " (gust {$d['wind_gust']} km/h)";
        }
        $output .= "\n";

        $output .= "📊 Pressure: {$d['pressure']} hPa\n";
        $output .= "🌧️ Rain today: {$d['rain_today']} mm";

        if (!empty($d['rain_rate']) && floatval($d['rain_rate']) > 0) {
            $output .= " (rate {$d['rain_rate']} mm)";
        }
        $output .= "\n";

        if (!empty($d['conditions'])) {
            $output .= "☁️ {$d['conditions']}\n";
        }

        $this->log_debug('Formatted weather update: ' . $output);
        return $output;
    }

    private function fetchWeatherData() {
        if (empty($this->clientraw_url)) {
            $this->log_debug('Fetch failed: Empty clientraw URL');
            return false;
        }

        $this->log_debug("Fetching clientraw.txt from: {$this->clientraw_url}");

        $response = wp_remote_get($this->clientraw_url, [
            'timeout'   => 30,
            'sslverify' => false,
            'user-agent'=> 'MM0ZIF_WX_WordPress_Plugin/1.8'
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

        // We need a reasonable number of fields
        if (count($data) < 50) {
            $this->log_debug('Fetch failed: Invalid clientraw format (too few fields) - ' . count($data));
            return false;
        }

        // --- Field mapping (0-based index) ---
        // 1  = Avg wind (knots)
        // 2  = Gust / current wind (knots)
        // 3  = Wind direction (degrees)
        // 4  = Outside temp (°C)
        // 5  = Outside humidity (%)
        // 6  = Barometer (hPa)
        // 7  = Daily rain (mm)
        // 10 = Rain rate (mm)
        // 44 = Wind chill (°C)
        // 45 = Humidex / Heat index (°C)
        // 49 = Weather description

        $temp       = isset($data[4])  ? round(floatval($data[4]), 1) : 0;
        $humidity   = isset($data[5])  ? round(floatval($data[5]))    : 0;
        $wind_knots = isset($data[1])  ? floatval($data[1])           : 0;
        $gust_knots = isset($data[2])  ? floatval($data[2])           : 0;
        $wind_deg   = isset($data[3])  ? floatval($data[3])           : 0;
        $pressure   = isset($data[6])  ? round(floatval($data[6]), 1) : 0;
        $rain_today = isset($data[7])  ? round(floatval($data[7]), 1) : 0;
        $rain_rate  = isset($data[10]) ? round(floatval($data[10]), 2): 0;
        $windchill  = isset($data[44]) ? round(floatval($data[44]), 1): null;
        $humidex    = isset($data[45]) ? round(floatval($data[45]), 1): null;
        $conditions = isset($data[49]) ? str_replace('_', ' ', $data[49]) : '';

        // Convert knots → km/h
        $wind_kmh = round($wind_knots * 1.852);
        $gust_kmh = round($gust_knots * 1.852);

        // Decide "feels like"
        $feels_like = null;
        if ($temp <= 10 && $windchill !== null && $windchill < $temp) {
            $feels_like = $windchill;          // cold → wind chill
        } elseif ($temp >= 20 && $humidex !== null && $humidex > $temp) {
            $feels_like = $humidex;            // warm → humidex
        }

        $weather_data = [
            'temperature'    => $temp,
            'humidity'       => $humidity,
            'wind_speed'     => $wind_kmh,
            'wind_gust'      => $gust_kmh,
            'wind_direction' => $this->getWindDirection($wind_deg),
            'pressure'       => $pressure,
            'rain_today'     => $rain_today,
            'rain_rate'      => $rain_rate,
            'feels_like'     => $feels_like,
            'conditions'     => $conditions,
        ];

        $this->log_debug('Weather data fetched: ' . print_r($weather_data, true));
        return $weather_data;
    }

    private function getWindDirection($degrees) {
        $degrees = floatval($degrees);
        $directions = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
                       'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $index = (int) round($degrees / 22.5) % 16;
        return $directions[$index];
    }

    private function log_debug($message) {
        $log = get_option('bluesky_debug_log', []);
        $log[] = [
            'time'    => current_time('mysql'),
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
            'time'    => current_time('mysql'),
            'status'  => $status,
            'details' => is_string($details) ? $details : print_r($details, true)
        ];
        if (count($log) > 50) {
            $log = array_slice($log, -50);
        }
        update_option('bluesky_upload_log', $log, false);
        $this->log_debug("Upload log entry: Status=$status, Details=" . (is_string($details) ? $details : print_r($details, true)));
    }
}