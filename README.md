# Cerrito Substack Importer

A WordPress plugin that automatically imports posts from [cerrito.substack.com](https://cerrito.substack.com) into your WordPress site via RSS feed.

## Features

- Fetches the Substack RSS feed on a configurable schedule (hourly, twice daily, or daily)
- Prevents duplicate imports using Substack's GUID
- Optionally sideloads featured images into your Media Library
- Strips Substack subscribe/footer boilerplate from post content
- Stores the original Substack URL as post meta for reference
- Manual **Import Now** button for on-demand runs
- Shortcode to display imported posts anywhere on your site

## Installation

1. Download the latest ZIP from [Releases](../../releases)
2. In WordPress, go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Activate**
4. Go to **Settings → Substack Importer** to configure

## Configuration

| Setting | Description |
|---|---|
| Import Frequency | How often the feed is checked (hourly / twice daily / daily) |
| Post Status | Import as Published, Draft, or Pending Review |
| Assign Category | Automatically assign a WordPress category to imported posts |
| Post Author | WordPress user credited as the post author |
| Import Featured Images | Sideload the post's image into the Media Library |

## Shortcode

Display imported Substack posts anywhere using:

```
[cerrito_substack_posts]
```

### Shortcode Attributes

| Attribute | Default | Description |
|---|---|---|
| `count` | `5` | Number of posts to display |
| `category` | _(all)_ | Filter by category slug or ID |
| `show_excerpt` | `1` | Show post excerpt (1 = yes, 0 = no) |

**Example:**
```
[cerrito_substack_posts count="3" category="news" show_excerpt="1"]
```

## Post Meta

Each imported post stores two custom meta fields:

- `_csi_substack_guid` — The Substack GUID used to prevent re-importing
- `_csi_substack_url` — The original Substack post URL

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Changelog

See [CHANGELOG.md](CHANGELOG.md)

## License

GPL v2 — see [LICENSE](LICENSE)
