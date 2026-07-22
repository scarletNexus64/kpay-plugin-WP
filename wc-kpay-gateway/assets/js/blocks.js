/* global wc, wp */
( function () {
	'use strict';

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = wc.wcSettings.getSetting;
	var createElement = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var decodeEntities = wp.htmlEntities.decodeEntities;
	var __ = wp.i18n.__;

	var settings = getSetting( 'kpay_data', {} );
	var providers = settings.providers || [];
	var label = decodeEntities( settings.title || __( 'Mobile Money', 'k-pay-for-woocommerce' ) );

	/**
	 * Champs opérateur + numéro. Les valeurs sont poussées vers le serveur
	 * via onPaymentSetup, qui les place dans paymentMethodData.
	 */
	var isGateway = settings.mode === 'gateway';

	/**
	 * Mode passerelle : aucun champ à collecter, K-Pay héberge le formulaire.
	 * Le composant se contente d'annoncer la redirection.
	 */
	function KPayRedirectNotice() {
		var children = [];

		if ( settings.description ) {
			children.push(
				createElement( 'p', { key: 'desc' }, decodeEntities( settings.description ) )
			);
		}

		if ( settings.isSandbox ) {
			children.push(
				createElement(
					'p',
					{ key: 'sandbox' },
					createElement(
						'strong',
						null,
						__( 'Mode test actif — aucun paiement réel ne sera effectué.', 'k-pay-for-woocommerce' )
					)
				)
			);
		}

		children.push(
			createElement(
				'p',
				{ key: 'redirect', className: 'wc-kpay-redirect-notice' },
				settings.redirectNotice ||
					__( 'Vous serez redirigé vers la page de paiement sécurisée K-Pay.', 'k-pay-for-woocommerce' )
			)
		);

		return createElement( 'div', { className: 'wc-kpay-fields' }, children );
	}

	function KPayFields( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;

		var providerState = useState( providers.length ? providers[ 0 ].value : '' );
		var provider = providerState[ 0 ];
		var setProvider = providerState[ 1 ];

		var phoneState = useState( '' );
		var phone = phoneState[ 0 ];
		var setPhone = phoneState[ 1 ];

		useEffect( function () {
			var unsubscribe = eventRegistration.onPaymentSetup( function () {
				if ( ! provider ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Veuillez sélectionner un opérateur.', 'k-pay-for-woocommerce' ),
					};
				}

				if ( ! phone || phone.replace( /\D/g, '' ).length < 8 ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Veuillez saisir un numéro Mobile Money valide.', 'k-pay-for-woocommerce' ),
					};
				}

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							kpay_provider: provider,
							kpay_phone: phone,
						},
					},
				};
			} );

			return unsubscribe;
		}, [ provider, phone, eventRegistration, emitResponse ] );

		var children = [];

		if ( settings.description ) {
			children.push(
				createElement( 'p', { key: 'desc' }, decodeEntities( settings.description ) )
			);
		}

		if ( settings.isSandbox ) {
			children.push(
				createElement(
					'p',
					{ key: 'sandbox' },
					createElement(
						'strong',
						null,
						__( 'Mode test actif — aucun paiement réel ne sera effectué.', 'k-pay-for-woocommerce' )
					)
				)
			);
		}

		// La classe wc-block-components-text-input n'est délibérément pas
		// réutilisée : elle applique un label flottant en position absolue,
		// qui se superpose au champ si le markup n'est pas exactement celui
		// attendu par WooCommerce Blocks. Un markup neutre reste lisible sur
		// tous les thèmes.
		children.push(
			createElement( 'div', { key: 'provider', className: 'wc-kpay-field' }, [
				createElement(
					'label',
					{ key: 'l', htmlFor: 'kpay_provider', className: 'wc-kpay-field__label' },
					__( 'Opérateur', 'k-pay-for-woocommerce' )
				),
				createElement(
					'select',
					{
						key: 's',
						id: 'kpay_provider',
						className: 'wc-kpay-field__control',
						value: provider,
						onChange: function ( e ) {
							setProvider( e.target.value );
						},
					},
					providers.map( function ( item ) {
						return createElement( 'option', { key: item.value, value: item.value }, item.label );
					} )
				),
			] )
		);

		children.push(
			createElement( 'div', { key: 'phone', className: 'wc-kpay-field' }, [
				createElement(
					'label',
					{ key: 'l', htmlFor: 'kpay_phone', className: 'wc-kpay-field__label' },
					__( 'Numéro Mobile Money', 'k-pay-for-woocommerce' )
				),
				createElement( 'input', {
					key: 'i',
					id: 'kpay_phone',
					className: 'wc-kpay-field__control',
					type: 'tel',
					autoComplete: 'tel',
					value: phone,
					placeholder: __( 'Ex : 6 70 00 00 01', 'k-pay-for-woocommerce' ),
					onChange: function ( e ) {
						setPhone( e.target.value );
					},
				} ),
			] )
		);

		return createElement( 'div', { className: 'wc-kpay-fields' }, children );
	}

	/**
	 * Titre du moyen de paiement, suivi du logo. La hauteur est contrainte :
	 * un SVG sans taille s'étire à la largeur du conteneur.
	 */
	function KPayLabel() {
		var children = [ createElement( 'span', { key: 'text' }, label ) ];

		if ( settings.iconUrl ) {
			children.push(
				createElement( 'img', {
					key: 'icon',
					src: settings.iconUrl,
					alt: '',
					style: { height: '24px', width: 'auto', marginLeft: '8px', verticalAlign: 'middle' },
				} )
			);
		}

		return createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center', width: '100%' } },
			children
		);
	}

	registerPaymentMethod( {
		name: 'kpay',
		label: createElement( KPayLabel, null ),
		content: createElement( isGateway ? KPayRedirectNotice : KPayFields, null ),
		edit: createElement( 'div', null, label ),
		canMakePayment: function () {
			// En mode passerelle, la liste d'opérateurs est gérée par K-Pay :
			// l'exiger ici masquerait la passerelle à tort.
			return isGateway || providers.length > 0;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
