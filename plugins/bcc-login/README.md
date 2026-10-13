# BCC Login

Utilities for visibility, groups, and notifications in WordPress.

## Development

- Source code: `src/`
- Built assets: `build/`
- PHP plugin classes: `includes/`

## Features

- Post and block audience (Public, Logged In, Members, Not Logged In)
- Group-based visibility and notifications
- Admin settings with PrimeReact components

### Prerequisites
- WP-CLI installed and available on PATH
- Run the command from the plugin root: `/wp-content/plugins/bcc-login`

### Build

```bash
npm install
npm run build
```
or
```bash
npm install
npm run start
```
## Translation

Generate or update the translation template (POT):

```bash
npm run update-strings
```

### Notes
- The text domain must be `bcc-login`
- POT file is generated into `languages/bcc-login.pot`
- Update translations after changing strings
- Clear caches when testing visibility filters