module.exports = {
	plugins: {
		...( 'production' === process.env.NODE_ENV ? { cssnano: {}} : {} ),
	},
};
