/**
 * WordPress dependencies.
 */
import { Icon } from '@wordpress/components';

const StatCard = ( {
	icon,
	label,
	value,
	suffix,
	meter,
	foot,
	chip,
	compact = false,
	planned = false,
} ) => {
	return (
		<div
			className={ `hyve-next-stat${ planned ? ' is-planned' : '' }${
				compact ? ' is-compact' : ''
			}` }
		>
			<div className="hyve-next-stat__top">
				{ icon && <Icon icon={ icon } size={ 16 } /> }
				<span className="hyve-next-stat__label">{ label }</span>
				{ chip }
			</div>

			<div className="hyve-next-stat__num">
				{ value }
				{ suffix && <small> { suffix }</small> }
			</div>

			{ undefined !== meter && (
				<div className="hyve-next-stat__meter" aria-hidden="true">
					<i
						style={ {
							width: `${ Math.min(
								100,
								Math.max( 0, meter )
							) }%`,
						} }
					></i>
				</div>
			) }

			{ foot && <div className="hyve-next-stat__foot">{ foot }</div> }
		</div>
	);
};

export default StatCard;
