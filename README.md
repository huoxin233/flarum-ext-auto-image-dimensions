# Auto Image Dimensions

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/huoxin/auto-image-dimensions.svg)](https://packagist.org/packages/huoxin/auto-image-dimensions) [![Total Downloads](https://img.shields.io/packagist/dt/huoxin/auto-image-dimensions.svg)](https://packagist.org/packages/huoxin/auto-image-dimensions) [![Review](https://floxum.com/extension/huoxin/auto-image-dimensions/badge/review)](https://floxum.com/extension/huoxin/auto-image-dimensions) [![Review Score](https://floxum.com/extension/huoxin/auto-image-dimensions/badge/review-score)](https://floxum.com/extension/huoxin/auto-image-dimensions)

A [Flarum](https://flarum.org) extension that automatically fetches and applies `width` and `height` dimensions to all `<img>` tags in your forum posts.

By hardcoding the dimensions into the parsed XML of the posts, this extension eliminates Cumulative Layout Shift (CLS), dramatically improving your forum's page performance scores and scrolling experience.

## Features

- **Smart Processing**: Uses highly optimized HTTP streams (via `FastImageSize`) to only download the header bytes of remote images, drastically reducing memory and bandwidth overhead.
- **Dynamic Operating Modes**: Choose how the extension extracts dimensions based on your server resources:
  - `Client Mode (Default)`: Forum visitors automatically detect image dimensions in their browser and silently report them back to the server. Best for heavily cached or low-resource servers.
  - `Backend Mode`: The server uses a background Queue Worker to fetch dimensions every time a post is created or edited.
  - `Hybrid Mode`: Combines both approaches for maximum coverage.
- **Data Integrity**: Implements robust Optimistic Locking to ensure background workers never overwrite concurrent user edits.
- **Network Proxy Support**: Configurable proxy settings (e.g. `tcp://10.0.0.5:3128`) for servers behind corporate firewalls.
- **Scheduled Backfills**: Automatically retry failed image fetching on a daily, weekly, or monthly cron schedule.

## Installation

Install with composer:

```sh
composer require huoxin/auto-image-dimensions:"*"
```

> **Installation Note for Backend Mode:** 
> If you plan to use **Backend** or **Hybrid** mode on an existing forum, the extension will NOT automatically fetch dimensions for your old posts during installation (to prevent server timeout crashes). After enabling the extension, please go to the extension's settings page and click the **"Trigger All"** button, or safely queue your old posts via the terminal by running: `php flarum auto-image-dimensions:backfill`. (If you exclusively use Client mode, no action is required).

## CLI Commands

If you have thousands of existing posts, you can manually trigger a backfill from the command line:

```sh
# Fetch dimensions for ALL images that don't have them
php flarum auto-image-dimensions:backfill

# ONLY retry images that previously failed (e.g. due to 404s or timeouts)
php flarum auto-image-dimensions:backfill --retry-failed

# Dry-run to see exactly which posts would be queued, without actually queueing them
php flarum auto-image-dimensions:backfill --dry-run --verbose

# Clear all dimensions, optionally see what posts are affected without modifying them
php flarum auto-image-dimensions:clear-all --dry-run --verbose
```

## Updating

```sh
composer update huoxin/auto-image-dimensions:"*"
php flarum migrate
php flarum cache:clear
```

## Links

- [Packagist](https://packagist.org/packages/huoxin/auto-image-dimensions)
- [GitHub](https://github.com/huoxin233/flarum-ext-auto-image-dimensions)
