# Bluesky Weather Poster WordPress Plugin

A WordPress plugin that automatically posts weather updates from clientraw.txt to Bluesky social network.
Written by Marcus Hazel-McGown - MM0ZIF owner of https://mm0zif.radio contact: marcus@havenswell.com
## Features

- Automatic weather updates posted to Bluesky
- Configurable posting frequency (1, 2, 3, or 6 hours)
- Parses clientraw.txt data
- Includes live weather station link
- Easy-to-use admin interface
- Test post functionality

## Installation

1. Upload the `bluesky-weather-poster` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Bluesky Poster to configure

## Configuration

You'll need:
- Bluesky account credentials
- URL to your clientraw.txt file
- URL to your live weather station (optional)

## Weather Data Format

The plugin formats weather data as:
Current conditions: [temp]°C, Wind [dir] [speed] km/h, Humidity [value]%, Pressure [value] hPa, Rain today [value] mm


## Usage

1. Enter your credentials and URLs in the settings page
2. Select your preferred posting frequency
3. Click "Test Post" to verify your setup
4. The plugin will automatically post updates based on your schedule

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Active Bluesky account
- Accessible clientraw.txt file

## Support

For support or feature requests, please use the GitHub issues page.

## License

GPL v2 or later
