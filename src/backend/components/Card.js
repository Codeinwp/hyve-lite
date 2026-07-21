const Card = ( { title, actions, footer, children } ) => {
	return (
		<div className="hyve-next-card">
			{ ( title || actions ) && (
				<div className="hyve-next-card__head">
					{ title && <h2>{ title }</h2> }
					{ actions && (
						<div className="hyve-next-card__actions">
							{ actions }
						</div>
					) }
				</div>
			) }

			{ children }

			{ footer && <div className="hyve-next-card__foot">{ footer }</div> }
		</div>
	);
};

export default Card;
