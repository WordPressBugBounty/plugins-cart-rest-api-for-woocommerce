/* global CoCartPluginSearch */
var CoCartPS = {};

( function ( $ ) {
	CoCartPS = {
		$pluginFilter: $( '#plugin-filter' ),

		/**
		 * Get parent search hint element.
		 * @returns {Element | null}
		 */
		getSuggestion: function () {
			return document.querySelector( '.plugin-card-cocart-suggestion' );
		},

		/**
		 * Get plugin result element.
		 * @returns {NodeList | null}
		 */
		getCard: function () {
			return document.querySelectorAll( '.plugin-card' );
		},

		/**
		 * Update title of the card to be presentable.
		 */
		updateCardTitle: function () {
			var hint = CoCartPS.getSuggestion();
			var card = CoCartPS.getCard();

			if ( 'object' === typeof hint && null !== hint ) {
				var title  = hint.querySelector( '.column-name h3' );
				var author = hint.querySelector( '.column-name h3 strong' );

				$(title).after( '<strong>' + $(author).text() + '</strong>' );
				$(author).remove();
			}

			if ( 'object' === typeof card && null !== card ) {
				card.forEach( function( element, index ) {
					if ( element.className.includes( 'plugin-card-cocart' ) || document.querySelector( 'body.cocart-plugin-install' ) ) {
						var title  = element.querySelector( '.column-name h3' );
						var author = element.querySelector( 'p.authors' );

						if ( $(author).length > 0 ) {
							$(title).after( '<strong>' + $(author).text() + '</strong>' );
						}
						$(author).remove();
					}
				} );
			}
		},

		/**
		 * Unlinks the title of the card to remove link to plugin information that wont exist.
		 */
		unlinkCardTitle: function () {
			var hint = CoCartPS.getSuggestion();
			var card = CoCartPS.getCard();

			if ( 'object' === typeof hint && null !== hint ) {
				var title = hint.querySelector( '.column-name h3 a' );

				$(title).outerHTML = $(title).replaceWith( $(title).html() );
			}

			if ( 'object' === typeof card && null !== card ) {
				card.forEach( function( element, index ) {
					if ( element.className.includes( 'plugin-card-cocart' ) || document.querySelector( 'body.cocart-plugin-install' ) ) {
						var title = element.querySelector( '.column-name h3 a' );

						$(title).outerHTML = $(title).replaceWith( $(title).html() );
					}
				} );
			}
		},

		/**
		 * Move action links below description.
		 */
		moveActionLinks: function () {
			var hint = CoCartPS.getSuggestion();

			if ( 'object' === typeof hint && null !== hint ) {
				var descriptionContainer = hint.querySelector( '.column-description' );

				// Keep only the first paragraph. The second is the plugin author.
				var descriptionText = descriptionContainer.querySelector( 'p:first-child' );
				var actionLinks     = hint.querySelector( '.action-links' );

				// Change the contents of the description, to keep the description text and the action links.
				descriptionContainer.innerHTML = descriptionText.outerHTML + actionLinks.outerHTML;

				// Remove the action links from their default location.
				actionLinks.parentNode.removeChild( actionLinks );
			}
		},

		/**
		 * Replace bottom row of the card.
		 */
		replaceCardBottom: function () {
			var hint = CoCartPS.getSuggestion();
			var card = CoCartPS.getCard();

			if ( 'object' === typeof hint && null !== hint ) {
				hint.querySelector( '.plugin-card-bottom' ).outerHTML =
					'<div class="cocart-suggestion__bottom">' +
					'<p class="cocart-suggestion__text">' +
					CoCartPluginSearch.legend +
					' <a class="cocart-suggestion__support_link" href="' +
					CoCartPluginSearch.supportLink +
					'" target="_blank" rel="noopener noreferrer" data-track="support_link" >' +
					CoCartPluginSearch.supportText +
					'</a>' +
					'</p>' +
					'</div>';
			}

			if ( 'object' === typeof card && null !== card ) {
				card.forEach( function( element, index ) {
					var bottomCard  = element.querySelector( '.plugin-card-bottom' );
					var review      = element.querySelector( '.column-rating' );
					var downloads   = element.querySelector( '.column-downloaded' );
					var lastUpdated = element.querySelector( '.column-updated' );
					var require     = element.querySelector( '.plugin-requirement' );

					if ( element.className.includes( 'plugin-card-cocart' ) ) {
						// Remove elements if they exist.
						if (review) review.remove();
						if (downloads) downloads.remove();
						if (lastUpdated) lastUpdated.remove();

						// Move plugin requirement if it exists.
						if ( $(require).length > 0 ) {
							bottomCard.append(require);
						}
					}

					if ( document.querySelector( 'body.cocart-plugin-install' ) ) {
						// Remove elements if they exist.
						if (review) review.remove();
						if (downloads) downloads.remove();
						if (lastUpdated) lastUpdated.remove();
					}
				} );
			}
		},

		/**
		 * Removes the core plugin from results.
		 */
		hideCoreCard: function ( ) {
			var core = document.querySelector( 'body.cocart-plugin-install .plugin-card.plugin-card-cart-rest-api-for-woocommerce' );

			if ( $(core).length > 0 ) {
				core.remove();
			}
		},

		/**
		 * Resets the plugin results.
		 */
		reset: function() {
			var body = document.querySelector( 'body' );
			var dashboard = document.querySelector( '.cocart-plugin-install-dashboard' );

			if ( $(body).hasClass( 'cocart-plugin-install' ) ) {
				$(body).removeClass( 'cocart-plugin-install' );
			}

			if ( $(dashboard).length > 0 ) {
				$(dashboard).remove();
			}
		},

		/**
		 * Check if plugin card list nodes changed. If there's a CoCart PSH card, replace the title and the bottom row.
		 * @param {array} mutationsList
		 */
		replaceOnNewResults: function ( mutationsList ) {
			mutationsList.forEach( function ( mutation ) {
				if ( 'childList' === mutation.type ) {
					var card = CoCartPS.getCard();
					card.forEach( function ( element ) {
						if ( element.className.includes( 'plugin-card-cocart' ) ) {
							element.classList.add('cocart-plugin');
							CoCartPS.unlinkCardTitle();
							CoCartPS.updateCardTitle();
							CoCartPS.moveActionLinks();
							CoCartPS.replaceCardBottom();
						}
					} );
				}
			} );
		},

		/**
		 * Start suggesting.
		 */
		init: function () {
			if ( CoCartPS.$pluginFilter.length < 1 ) {
				return;
			}

			// Highlight only CoCart plugins.
			var card = CoCartPS.getCard();
			card.forEach( function ( element ) {
				if ( element.className.includes( 'plugin-card-cocart' ) ) {
					element.classList.add('cocart-plugin');
				}
			} );

			// Removes plugin information link from title.
			CoCartPS.unlinkCardTitle();

			// Update title to show that the suggestion is from CoCart.
			CoCartPS.updateCardTitle();

			// Update the description and action links.
			CoCartPS.moveActionLinks();

			// Replace PSH bottom row on page load.
			CoCartPS.replaceCardBottom();

			// Hide CoCart core card.
			CoCartPS.hideCoreCard();

			// Listen for changes in plugin search results
			var resultsObserver = new MutationObserver( CoCartPS.replaceOnNewResults );
			resultsObserver.observe( document.getElementById( 'plugin-filter' ), { childList: true } );
		},
	};

	CoCartPS.init();
} )( jQuery, CoCartPluginSearch );
