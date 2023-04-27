/* global gpInlineTranslation */

( function( $ ) {
	$( function() {
		if ( ! $( '#translator-launcher' ).length || typeof gpInlineTranslation !== 'object' ) {
			// gpInlineTranslation not available, maybe interface is in English
			return false;
		}

		if ( shouldAutoloadTranslator() ) {
			$( '#inline-translation-switch' ).prop( 'checked', true );
		}

		function loadTranslator() {
			if ( gpInlineTranslation.load() !== false ) {
				// was loaded successfully
				autoloadTranslator( true );
			}
		}

		function unloadTranslator() {
			gpInlineTranslation.unload();
			autoloadTranslator( false );
		}

		$( document.body ).on( 'click', '#translator-launcher', function() {
			$( '#translator-pop-out' ).toggle();
		} );

		$( document.body ).on( 'change', '#inline-translation-switch', function() {
			if ( $( this ).prop( 'checked' ) ) {
				loadTranslator();
			} else {
				unloadTranslator();
			}
		} );

		$( document.body ).on( 'click', '#gp-show-translation-list', function() {
			$( '#translator-pop-out' ).hide();
			$( '#gp-inline-translation-list' ).toggle();
			$( '#gp-inline-search' ).focus();
		} );

		$( document.body ).on( 'search', '#gp-show-translation-list', function( event, keyword ) {
			$( this ).trigger( 'click' );
			$( '#gp-inline-search' ).val( keyword ).trigger( 'keyup' );
		} );

		$( document.body ).on( 'click', '#gp-inline-translation-list', function( event ) {
			var targetId = $( event.target ).attr( 'id' );
			if ( targetId && targetId === 'gp-inline-translation-list' ) {
				$( '#gp-inline-translation-list' ).hide();
			}
		} );

		$( document.body ).on( 'keyup', function( e ) {
			if ( e.keyCode === 27 ) {
				$( '#gp-inline-translation-list' ).hide();
			}
		} );
		$( document.body ).on( 'keyup', '#gp-inline-search', function() {
			var i,
				search = $( this ).val().toLowerCase().split( ' || ' ).map( function( s ) {
					return s.trim();
				} ),
				searchLength = search.length;
			$( '#gp-inline-translation-list li' ).each( function() {
				var t = $( this ).text();
				for ( i = 0; i < searchLength; i++ ) {
					if ( t.toLowerCase().indexOf( search[ i ] ) >= 0 ) {
						$( this ).show();
						return;
					}
				}
				$( this ).hide();
			} );
		} );

		$( document.body ).on( 'change', '#inline-jump-next-switch', function() {
			if ( $( this ).prop( 'checked' ) ) {
				document.cookie = 'inlinejumptonext=1;path=/';
			} else {
				document.cookie = 'inlinejumptonext=;expires=Sat,%201%20Jan%202000%2000:00:00%20GMT;path=/';
			}
		} );

		if ( jumpToNextOnSave() ) {
			$( '#inline-jump-next-switch' ).prop( 'checked', true );
		}

		jQuery( document.body ).tooltip( {
			items: '.glossary-word',
			content: function() {
				var content = $( '<ul>' );
				$.each( $( this ).data( 'translations' ), function( i, e ) {
					var def = $( '<li>' );
					if ( e.locale_entry ) {
						def.append( $( '<span>', { text: e.locale_entry } ).addClass( 'locale-entry bubble' ) );
					}
					def.append( $( '<span>', { text: e.pos } ).addClass( 'pos' ) );
					def.append( $( '<span>', { text: e.translation } ).addClass( 'translation' ) );
					def.append( $( '<span>', { text: e.comment } ).addClass( 'comment' ) );
					content.append( def );
				} );
				return content;
			},
			hide: false,
			show: false,
		} );

		// only show the button when the translator has been loaded
		runWhenTranslatorIsLoaded( function() {
			$( '#translator-launcher' ).show();
			if ( shouldAutoloadTranslator() ) {
				loadTranslator();
			}
		} );

		// because of the nature of wp_enqueue_script and the fact that we can only insert the gpInlineTranslation at the bottom of the page, we have to wait until the object exists
		function runWhenTranslatorIsLoaded( callback ) {
			if ( 'undefined' === typeof window.gpInlineTranslation ) {
				window.setTimeout( function() {
					runWhenTranslatorIsLoaded( callback );
				}, 100 );
				return;
			}
			callback();
		}

		function autoloadTranslator( enable ) {
			if ( enable ) {
				document.cookie = 'autoinlinetranslation=1;path=/';
			} else {
				document.cookie = 'autoinlinetranslation=;expires=Sat,%201%20Jan%202000%2000:00:00%20GMT;path=/';
			}
		}

		function shouldAutoloadTranslator() {
			// also enable if the gp_enable_inline_translation field from the HTTP POST is set
			if ( $( 'input[name="gp_enable_inline_translation"]' ).prop( 'checked' ) ) {
				return true;
			}

			return !! document.cookie.match( /autoinlinetranslation=1/ );
		}
		function jumpToNextOnSave() {
			return !! document.cookie.match( /inlinejumptonext=1/ );
		}
	} );
}( jQuery ) );

