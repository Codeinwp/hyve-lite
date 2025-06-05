/**
 * External dependencies.
 */
import classnames from 'classnames';

const ProgressBar = ( { value, max = 100, className } ) => {
	const progress = 0 < max ? Math.round( ( value / max ) * 100 ) : 0;

	const wrapClasses = classnames(
		'w-full h-5 border rounded-md border-solid bg-white border-light-gray relative overflow-hidden',
		className
	);

	return (
		<div
			className={ wrapClasses }
			role="progressbar"
			aria-valuemin="0"
			aria-valuemax={ max }
			aria-valuenow={ value }
		>
			<div
				className="absolute left-0 h-full bg-blue-500"
				style={ { width: `${ progress }%` } }
			></div>
		</div>
	);
};

export default ProgressBar;
