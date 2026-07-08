const Chip = ( { tone = 'muted', dot = true, children } ) => {
	return (
		<span className={ `hyve-next-chip is-${ tone }` }>
			{ dot && <span className="hyve-next-chip__dot"></span> }
			{ children }
		</span>
	);
};

export default Chip;
