( function () {
	const nospoiler = function ( elem ) {
		const button = OO.ui.ButtonWidget.static.infuse( elem );
		const $figure = button.$element.parents( 'figure' );
		button.$element.on( 'click', ( e ) => {
			e.preventDefault();
			e.stopPropagation();
			$figure.addClass( 'nospoiler' );
			$figure.children( 'a:first-child' ).attr( 'href', button.getHref() ).removeAttr( 'aria-disabled' );
			button.$element.parent( '.spoiler-cover' ).remove();
		} );
	};

	// eslint-disable-next-line no-jquery/no-global-selector
	const $buttons = $( '.spoiler-button' );
	for ( let i = 0; i < $buttons.length; i++ ) {
		nospoiler( $buttons[ i ] );
	}
}() );
