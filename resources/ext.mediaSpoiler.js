( function () {
	var nospoiler = function ( elem ) {
		var button = OO.ui.ButtonWidget.static.infuse( elem );
		var $figure = button.$element.parents( 'figure' );
		button.$element.on( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			$figure.addClass( 'nospoiler' );
			$figure.children( 'a:first-child' ).attr( 'href', button.getHref() ).removeAttr( 'aria-disabled' );
			button.$element.parent( '.spoiler-cover' ).remove();
		} );
	};

	// eslint-disable-next-line no-jquery/no-global-selector
	var $buttons = $( '.spoiler-button' );
	for ( var i = 0; i < $buttons.length; i++ ) {
		nospoiler( $buttons[ i ] );
	}
}() );
