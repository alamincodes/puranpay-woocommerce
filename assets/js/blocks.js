( function ( wp, wc ) {
	'use strict';

	if ( ! wp || ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
		return;
	}

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = wc.wcSettings.getSetting;
	var decodeEntities = wp.htmlEntities.decodeEntities;
	var el = wp.element.createElement;
	var settings = getSetting( 'puranpay_data', {} ) || {};
	var label = decodeEntities( settings.title || 'PuranPay' );
	var description = decodeEntities( settings.description || '' );
	var icon = settings.icon || '';

	var Label = el(
		'span',
		{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
		icon
			? el( 'img', {
					src: icon,
					alt: label,
					style: { height: '24px', width: 'auto' },
			  } )
			: null,
		el( 'span', null, label )
	);

	registerPaymentMethod( {
		name: 'puranpay',
		label: Label,
		ariaLabel: label,
		canMakePayment: function () {
			return true;
		},
		content: el( 'div', { className: 'wc-puranpay-blocks-content' }, description ),
		edit: el( 'div', { className: 'wc-puranpay-blocks-content' }, description ),
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window.wp, window.wc );
