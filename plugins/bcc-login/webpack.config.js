const defaultConfig = require('@wordpress/scripts/config/webpack.config')

defaultConfig.entry = {
  visibility: './src/visibility',
  settings: './src/settings',
}

module.exports = defaultConfig
