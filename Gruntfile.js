/* eslint-disable camelcase */
/* jshint node:true */
/* global require */

module.exports = function( grunt ) {
	grunt.loadNpmTasks( 'grunt-version' );
	grunt.initConfig(
		{
			version: {
				project: {
					src: [
						'package.json'
					]
				},
				composer: {
					src: [
						'composer.json'
					]
				},
				metatag: {
					options: {
						prefix: 'Version:\\s*',
						flags: ''
					},
					src: [ 'hyve-lite.php' ]
				},
				php: {
					options: {
						prefix: 'HYVE_LITE_VERSION\', \'',
						flags: ''
					},
					src: [ 'hyve-lite.php' ]
				}
			}
		}
	);
};
