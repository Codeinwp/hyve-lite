module.exports = {
	plugins: {
		'@tailwindcss/postcss': {},
		...( 'production' === process.env.NODE_ENV ? { cssnano: {}} : {})
	}
};
