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

# Data model

- Configs are stored locally on disk in `var/data/configs.json`.
- Export/import uses a versioned config collection schema (v1) under `schemas/api-config/v1/config.schema.json`.

# Import / Export

- Import: upload a JSON export file from the left panel.
- Export: click **Export** and you’ll be prompted per-export which secrets to include.
	- Default is safe: secrets are redacted using placeholders like `{{AQTO_SECRET:...}}`.

# Execute

- Execution runs the currently stored request definition via Symfony HttpClient.
- Responses are displayed as formatted JSON/XML when possible; HTML is shown as escaped source by default.