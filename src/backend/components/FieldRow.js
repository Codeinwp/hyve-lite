const FieldRow = ( { label, description, wide = false, children } ) => {
	return (
		<div className="hyve-next-field">
			<div className="hyve-next-field__info">
				<span className="hyve-next-field__label">{ label }</span>
				{ description && <p>{ description }</p> }
			</div>
			<div
				className={ `hyve-next-field__control${
					wide ? ' is-wide' : ''
				}` }
			>
				{ children }
			</div>
		</div>
	);
};

export default FieldRow;
