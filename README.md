# About

The Aqto API Wizard is designed to provide a locally stored JSON file, which is parsed and editable by the front end interface, and allows the user to organize requests to whatever APIs they might want. It includes configuration for authorization headers, query parameters, and request bodies. The user can save and load different API configurations as needed. They can see the payload response from the API in a formatted way. The application uses PHP for the backend and JavaScript for the frontend. AlpineJS, HTMX, and TailwindCSS are used for the frontend framework and styling. The application backend uses Symfony and composer.json for dependency management. For front end assets we use a package.json file with npm for dependency management. The app is easily run locally with a built-in PHP server.

# Local development

## Requirements

- PHP 8.2+ (tested with PHP 8.3)
- Composer
- Node.js + npm

## Install

- PHP deps: `composer install`
- Frontend deps: `npm install`
- Build assets: `npm run build`

## Run

- Start the app: `php -S localhost:8000 -t public`
- Open: http://localhost:8000

## Debugging (Xdebug + VS Code)

### Requirements

- Xdebug installed/enabled for the same PHP binary you run the server with.
	- Quick check: `php -v` should mention Xdebug, or `php --ri xdebug` should work.
- VS Code extension: **PHP Debug** (`xdebug.php-debug`).

### Option A: Run the built-in PHP server with Xdebug (terminal)

1) Start the VS Code debug config **Listen for Xdebug**.

2) Start the server with Xdebug enabled:

`XDEBUG_MODE=debug,develop XDEBUG_CONFIG="client_host=127.0.0.1 client_port=9003" php -d xdebug.start_with_request=yes -S 127.0.0.1:8000 -t public`

3) Set a breakpoint in PHP code and refresh the page.

### Option B: Launch from VS Code

- Use the VS Code debug config **Launch built-in server (Xdebug)**.

Notes:
- Xdebug uses port `9003` by default. If you changed it in `php.ini`, update `.vscode/launch.json` and the command above.
- If you prefer triggering debug only on demand, replace `xdebug.start_with_request=yes` with `trigger` and set `XDEBUG_TRIGGER=1` for requests you want to debug.

# Data model

- Configs are stored locally on disk in `var/data/configs.json`.
- Export/import uses a versioned config collection schema under `schemas/api-config/`.

# Import / Export

- Import: upload a JSON export file from the left panel.
- Export: click **Export** and you’ll be prompted per-export which secrets to include.
	- Default is safe: secrets are redacted using placeholders like `{{AQTO_SECRET:...}}`.

# Execute

- Execution runs the currently stored request definition via Symfony HttpClient.
- Responses are displayed as formatted JSON/XML when possible; HTML is shown as escaped source by default.