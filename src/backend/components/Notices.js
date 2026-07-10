/**
 * WordPress dependencies.
 */
import { SnackbarList } from '@wordpress/components';

import { useDispatch, useSelect } from '@wordpress/data';

const Notices = () => {
	const notices = useSelect( ( select ) =>
		select( 'core/notices' )
			.getNotices()
			.filter( ( notice ) => 'snackbar' === notice.type )
	);

	const { removeNotice } = useDispatch( 'core/notices' );

	return (
		<SnackbarList
			className="edit-site-notices"
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
};

export default Notices;
