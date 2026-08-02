/**
 * Populates the "Inventory Count" and "Links" columns of the Bundle
 * Components repeater for whichever product is currently selected in that
 * row's "Product" column. Plain jQuery + a `change` listener rather than
 * ACF's own action hooks, since a `select`'s native `change` event (which
 * select2 already triggers on selection) works the same way across ACF/SCF
 * versions and covers rows added after page load without extra wiring.
 */
( function ( $ ) {
	'use strict';

	function updateLinksForSelect( $select ) {
		var productId = $select.val();
		var $linksField = $select
			.closest( 'tr.acf-row' )
			.find( '.acf-field[data-name="csl_component_links"] .acf-input' );

		if ( ! $linksField.length ) {
			return;
		}

		if ( ! productId ) {
			$linksField.html( '' );
			return;
		}

		var editUrl = cslBundleAdmin.adminUrl + 'post.php?post=' + encodeURIComponent( productId ) + '&action=edit';
		var viewUrl = cslBundleAdmin.homeUrl + '/?p=' + encodeURIComponent( productId );

		$linksField.html(
			'<a href="' + editUrl + '" target="_blank" rel="noopener noreferrer">' + cslBundleAdmin.editLabel + '</a>' +
			' | ' +
			'<a href="' + viewUrl + '" target="_blank" rel="noopener noreferrer">' + cslBundleAdmin.viewLabel + '</a>'
		);
	}

	function formatStock( data ) {
		if ( data.manage_stock ) {
			return data.stock_quantity === null ? '&#8212;' : data.stock_quantity;
		}

		switch ( data.stock_status ) {
			case 'outofstock':
				return cslBundleAdmin.outOfStockText;
			case 'onbackorder':
				return cslBundleAdmin.onBackorderText;
			default:
				return cslBundleAdmin.inStockText;
		}
	}

	function updateStockForSelect( $select ) {
		var productId = $select.val();
		var $stockField = $select
			.closest( 'tr.acf-row' )
			.find( '.acf-field[data-name="csl_component_stock"] .acf-input' );

		if ( ! $stockField.length ) {
			return;
		}

		if ( ! productId ) {
			$stockField.html( '' );
			return;
		}

		// Guards against an older, slower request overwriting a newer one
		// if the selection changes again before the first response returns.
		$stockField.data( 'csl-request-product-id', productId );
		$stockField.text( '…' );

		$.post( cslBundleAdmin.ajaxUrl, {
			action: 'csl_get_product_stock',
			nonce: cslBundleAdmin.stockNonce,
			product_id: productId
		} ).done( function ( response ) {
			if ( $stockField.data( 'csl-request-product-id' ) !== productId ) {
				return;
			}
			if ( response && response.success ) {
				$stockField.html( formatStock( response.data ) );
			} else {
				$stockField.html( '&#8212;' );
			}
		} ).fail( function () {
			if ( $stockField.data( 'csl-request-product-id' ) === productId ) {
				$stockField.html( '&#8212;' );
			}
		} );
	}

	function updateRowForSelect( $select ) {
		updateLinksForSelect( $select );
		updateStockForSelect( $select );
	}

	$( document ).on( 'change', '.acf-field[data-name="csl_component_product"] select', function () {
		updateRowForSelect( $( this ) );
	} );

	$( function () {
		$( '.acf-field[data-name="csl_component_product"] select' ).each( function () {
			updateRowForSelect( $( this ) );
		} );
	} );
} )( jQuery );
