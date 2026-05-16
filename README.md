# HC Sermons

A WordPress plugin for managing and displaying church sermon videos. Pulls
videos from a YouTube channel via its public RSS feed, stores each as a custom
post type with metadata (series, speaker, scripture, preached date), and
provides Gutenberg blocks plus a default archive for displaying them.

## Features

- **Custom post type** `hc_sermon` with title, content, featured image,
  description, and revision support.
- **Taxonomies**: Series (hierarchical), Speaker, Sermon Tag, and Scripture
  Reference (tag-style, multi-value, searchable).
- **YouTube sync** — paste a `UC…` channel ID, click Sync Now, or enable a
  daily 3 AM cron. Duplicate videos are detected by ID and skipped. A
  watchdog catches missed runs on low-traffic sites.
- **Manual sermon entry** — paste any YouTube URL or 11-character video ID;
  the plugin fetches the title via oEmbed and sideloads the thumbnail as the
  featured image. Warns when a video is already saved.
- **Bulk actions** — Publish or Move to Draft any selection of sermons in one
  click from the admin list.
- **Two Gutenberg blocks** under the *HC Blocks* category:
  - **Sermon (Single)** — render one sermon (most recent, most recent in a
    series, or hand-picked) with optional title/date/speaker/scripture meta.
  - **Sermon List** — grid, list, or featured + list layout with a sortable,
    filterable selection of sermons. The featured + list layout swaps the
    player in place when a list item is clicked.
- **Default archive at `/sermons/`** with filter dropdowns for series,
  speaker, and scripture, plus a keyword search.
- **Sync diagnostics** — settings page surfaces cron health, last sync time,
  and a 20-entry activity log.

## Installation

1. Copy this directory into `wp-content/plugins/hc-sermons/`.
2. Activate **HC Sermons** in the WP admin.
3. Visit **Sermons → Sync Settings** and paste your YouTube channel ID
   (the `UC…` value, not the `@handle`).
4. Click **Sync Now** to pull the channel's 15 most recent videos as draft
   sermons. Toggle **Auto-sync daily** to run the sync on a 3 AM cron.

## Configuration

| Setting | Description |
|---|---|
| Channel ID | The `UC…` ID for the YouTube channel. Find it in your channel's page source by searching for `"channelId"`. |
| Auto-sync daily | Enable the WP-Cron task that runs at 3 AM site time. |
| New sermons status | Whether new sermons are imported as `draft` (default) or `publish`. |

## Theme overrides

Themes can override the bundled single and archive templates by creating
files at:

- `<theme>/hc-sermons/single-hc_sermon.php`
- `<theme>/hc-sermons/archive-hc_sermon.php`

The override directory name is set by `HC_SERMONS_THEME_OVERRIDE_DIR` in
[`hc-sermons.php`](hc-sermons.php).

Stylesheet: `assets/css/sermons.css` (filename and enqueue handle live in
branding constants too).

## Block category

Both blocks register under a shared category called **HC Blocks**
(`hc-blocks` slug), so other HC plugins can group their blocks together in
the editor inserter. The first plugin to load creates the category;
subsequent plugins reuse it.

## Updates

The plugin self-updates from this GitHub repo via
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
in release mode. Once installed, the site checks for new GitHub Releases on
WordPress's normal update cadence (~12h) and shows "Update Available" in
**WP Admin → Plugins**.

### Releasing a new version

1. Bump both the `Version:` header in [`hc-sermons.php`](hc-sermons.php) and
   the `HC_SERMONS_VERSION` constant to match (e.g. `0.2.0`).
2. Commit and push to `main`.
3. On GitHub: **Releases → Draft a new release**.
4. Tag: `v0.2.0` (the leading `v` is conventional and plugin-update-checker
   handles either form).
5. Title + description: changelog notes.
6. Publish.

Sites will pick up the new version on their next update check, or
immediately when an admin clicks **Check Again** on the Updates screen.

Pushes to `main` without a Release do **not** trigger updates — only tagged
GitHub Releases do.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer

## License

GPL-2.0-or-later (matches WordPress core; the plugin is intended for
single-organization use, not the .org repo).
