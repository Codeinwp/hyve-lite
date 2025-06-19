export function setUpTracking() {
	if ( window?.tiTrk ) {
		window.tiTrk.eventsLimit = 3;
		window.hyveTrk = window.tiTrk?.with( 'hyve' );
	}
}
