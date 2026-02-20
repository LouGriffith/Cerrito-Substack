# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.0] - 2026-02-20

### Added
- Initial release
- Automatic RSS feed import from `https://cerrito.substack.com/feed`
- Configurable import frequency (hourly, twice daily, daily)
- Duplicate prevention via Substack GUID stored as post meta
- Optional featured image sideloading from enclosure or first inline image
- Automatic stripping of Substack subscribe/footer boilerplate
- Admin settings page under Settings → Substack Importer
- Manual Import Now button for on-demand runs
- `[cerrito_substack_posts]` shortcode with `count`, `category`, and `show_excerpt` attributes
- Stores original Substack URL as `_csi_substack_url` post meta
