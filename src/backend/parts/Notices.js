/**
 * WordPress dependencies.
 */
import { SnackbarList } from '@wordpress/components';

import { useDispatch, useSelect } from '@wordpress/data';

import { store as noticesStore } from '@wordpress/notices';

const Notices = () => {
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => 'snackbar' === notice.type ),
		[]
	);

	const { removeNotice } = useDispatch( noticesStore );

	return (
		<SnackbarList
			className="edit-site-notices"
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
};

export default Notices;
