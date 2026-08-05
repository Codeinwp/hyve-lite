/**
 * Adapter that exposes conventional-changelog-simple-preset (old preset API)
 * in the format expected by conventional-changelog v8 loaders, used by
 * @semantic-release/commit-analyzer >= 13 and release-notes-generator >= 14.
 *
 * Referenced from .releaserc.yml via the `config` option.
 */
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );

const parser = require( 'conventional-changelog-simple-preset/parser-opts' );
const writerOptsPromise = require( 'conventional-changelog-simple-preset/writer-opts' );
const {
	whatBump,
} = require( 'conventional-changelog-simple-preset/conventional-recommended-bump' );

export default async function createPreset() {
	const writerOpts = await writerOptsPromise;
	const legacyTransform = writerOpts.transform;

	return {
		parser,
		whatBump,
		writer: {
			...writerOpts,
			// The v8 writer passes immutable commit objects and expects a patch
			// in return, while the legacy transform mutates the commit in place.
			// Hand it a mutable clone to keep the original behavior.
			transform: ( commit, context ) => {
				const clone = JSON.parse( JSON.stringify( commit ) );
				return legacyTransform( clone, context ) || null;
			},
		},
	};
}
