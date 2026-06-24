const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		ignores: [ 'assets/**', '**/*.d.ts' ],
	},
	{
		files: [ '**/*.{js,jsx,mjs,cjs}' ],
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'module',
			parserOptions: {
				ecmaFeatures: {
					jsx: true,
				},
			},
			globals: {
				Audio: 'readonly',
			},
		},
		rules: {
			'import/default': 'off',
			'import/named': 'off',
			'import/no-duplicates': 'off',
			'import/no-extraneous-dependencies': 'off',
			'import/no-unresolved': 'off',
			'linebreak-style': [ 'error', 'unix' ],
			'array-bracket-spacing': [
				'warn',
				'always',
				{
					arraysInArrays: false,
					objectsInArrays: false,
				},
			],
			'key-spacing': [
				'warn',
				{
					beforeColon: false,
					afterColon: true,
				},
			],
			'object-curly-spacing': [
				'warn',
				'always',
				{
					arraysInObjects: true,
					objectsInObjects: false,
				},
			],
			'@wordpress/i18n-text-domain': [
				'error',
				{
					allowedTextDomain: 'hyve-lite',
				},
			],
		},
	},
];
