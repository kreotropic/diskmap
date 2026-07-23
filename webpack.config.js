/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Single entry point. @nextcloud/webpack-vue-config prefixes the app id, so
// this emits js/diskmap-main.js, which templates/main.php loads via
// script('diskmap', 'diskmap-main').
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

module.exports = webpackConfig
