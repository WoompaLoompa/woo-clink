( function () {
	'use strict';

	if ( typeof wcClinkPriceData === 'undefined' ) {
		return;
	}

	var rate   = parseFloat( wcClinkPriceData.btcRate ) || 0;
	var format = wcClinkPriceData.currencyFormat || 'sats';

	if ( rate <= 0 ) {
		return;
	}

	function priceToSats( amount ) {
		return Math.round( ( amount * 100000000 ) / rate );
	}

	function formatSats( sats ) {
		var btc = sats / 100000000;
		switch ( format ) {
			case 'btc':
				return btc.toFixed( 8 ) + ' BTC';
			case 'bip0177':
				return '\u20BF ' + btc.toFixed( 8 );
			case 'sats':
			default:
				return Number( sats ).toLocaleString() + ' sats';
		}
	}

	function parsePrice( text ) {
		var cleaned = text.replace( /[^0-9.\-]/g, '' );
		return parseFloat( cleaned );
	}

	function shouldConvert( text ) {
		return ! /(sats|BTC|₿)/i.test( text );
	}

	function convertPrices( root ) {
		if ( ! root ) {
			root = document;
		}
		var elements = root.querySelectorAll(
			'.woocommerce-Price-amount.amount, .woocommerce-Price-amount'
		);
		[].forEach.call( elements, function ( el ) {
			if ( el.getAttribute( 'data-clink-converted' ) ) {
				return;
			}
			if ( ! shouldConvert( el.textContent ) ) {
				return;
			}
			var amount = parsePrice( el.textContent );
			if ( isNaN( amount ) || amount <= 0 ) {
				return;
			}
			var sats = priceToSats( amount );
			if ( sats <= 0 ) {
				return;
			}
			el.setAttribute( 'data-clink-converted', '1' );
			el.textContent = formatSats( sats );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			convertPrices();
		} );
	} else {
		convertPrices();
	}

	( function ( win ) {
		if ( ! win.jQuery ) {
			return;
		}
		win.jQuery( document.body ).on( 'updated_wc_div updated_cart_totals found_variation', function () {
			convertPrices();
		} );
	} )( window );

	var observer = new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( mutation ) {
			[].forEach.call( mutation.addedNodes, function ( node ) {
				if ( node.nodeType === 1 ) {
					convertPrices( node );
				}
			} );
		} );
	} );

	if ( document.body ) {
		observer.observe( document.body, { childList: true, subtree: true } );
	} else {
		document.addEventListener( 'DOMContentLoaded', function () {
			observer.observe( document.body, { childList: true, subtree: true } );
		} );
	}
} )();
