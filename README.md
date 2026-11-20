# Connect to Buffer

A WordPress plugin that sends a post to Buffer with its title, URL and featured image when it is published, as a draft or straight into your queue.

## Service information

| Item | Value |
|---|---|
| Service name | wordpress-connect-to-buffer |
| Stage | In development |
| GitHub repository | https://github.com/smallpiece2024/wordpress-connect-to-buffer |
| URL | TBD |
| Infrastructure | WordPress, Buffer |
| Description | A WordPress plugin that sends posts to Buffer |
| Release date | Not released |
| UptimeRobot monitor ID | None |

## Documentation

- [readme.txt](readme.txt): what the plugin does, how to install and set it up, FAQ and changelog. It follows the readme format of the WordPress.org Plugin Directory.
- [README.ja.md](README.ja.md): the earlier README in Japanese. It describes the plugin as of version 0.4.2.

## Requirements

- WordPress 5.6 or later, PHP 7.4 or later
- A Buffer account and an API key
- The GD extension of PHP with FreeType support, if you use the vertical image with the title

## Installation

1. Put this repository in `wp-content/plugins/wordpress-connect-to-buffer` and activate the plugin.
2. Create an API key in the [API settings of Buffer](https://publish.buffer.com/settings/api).
3. Open "Settings > Connect to Buffer", save the API key and choose the channels.

See [readme.txt](readme.txt) for the rest of the settings.

## Development

With Docker and Node.js, `@wordpress/env` starts a local WordPress.

```
npx @wordpress/env start
```

Open http://localhost:8888/wp-admin/ and log in with the user name `admin` and the password `password`.

Run the check script with the following command. It mocks the requests to Buffer, so no real post is created. The saved settings and the activity log are restored afterwards.

```
npx @wordpress/env run cli wp eval-file wp-content/plugins/wordpress-connect-to-buffer/tests/smoke.php
```

Write samples of the vertical image to `tests/output/` with the following command.

```
npx @wordpress/env run cli wp eval-file wp-content/plugins/wordpress-connect-to-buffer/tests/sample-image.php
```

When you change how the plugin behaves, raise the version in three places: the `Version` header and `WCTB_VERSION` in `wordpress-connect-to-buffer.php`, and `Stable tag` in `readme.txt`. Add the change to the changelog in `readme.txt`.

## Infrastructure

| Service | Purpose | Where it is configured |
|---|---|---|
| WordPress | The site that runs the plugin | The admin screen of the site where the plugin is installed |
| Buffer | Scheduling posts on social media. The plugin saves a draft or adds to the queue through the GraphQL API | The settings screen "Settings > Connect to Buffer", or the constant `WCTB_API_KEY` in `wp-config.php` |

## License

GPL-2.0-or-later

The bundled font Noto Sans JP (`assets/fonts/NotoSansJP-Bold.otf`) is distributed under the SIL Open Font License 1.1. The full text of the license is in `assets/fonts/OFL.txt`.
