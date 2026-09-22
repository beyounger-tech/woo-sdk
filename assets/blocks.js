( function () {
	const decode = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
		? window.wp.htmlEntities.decodeEntities
		: function ( value ) {
			return value;
		};
	const methods = [
		'beyounger',
		'beyounger_paypal',
		'beyounger_google_pay',
		'beyounger_cash_app',
		'beyounger_apple_pay',
		'beyounger_card_to_crypto',
	];
	const tokenState = {
		token: '',
		orderId: '',
		iframe: null,
		iframeUrl: '',
		wrapper: null,
		activePlaceholder: null,
		height: 96,
		waiters: [],
	};

	function getIframeWrapper() {
		if ( tokenState.wrapper && document.body.contains( tokenState.wrapper ) ) {
			return tokenState.wrapper;
		}

		tokenState.wrapper = document.createElement( 'div' );
		tokenState.wrapper.setAttribute( 'data-beyounger-card-wrapper', '1' );
		tokenState.wrapper.style.cssText = 'position:absolute;display:none;z-index:999999;overflow:hidden;background:transparent;pointer-events:auto;';
		document.body.appendChild( tokenState.wrapper );
		return tokenState.wrapper;
	}

	function getOrCreateCardIframe( cardForm ) {
		if ( tokenState.iframe && tokenState.iframeUrl === cardForm.url ) {
			const wrapper = getIframeWrapper();
			if ( tokenState.iframe.parentNode !== wrapper ) {
				wrapper.appendChild( tokenState.iframe );
			}
			return tokenState.iframe;
		}

		if ( tokenState.iframe && tokenState.iframe.parentNode ) {
			tokenState.iframe.parentNode.removeChild( tokenState.iframe );
		}

		tokenState.token = '';
		tokenState.iframeUrl = cardForm.url;
		tokenState.iframe = document.createElement( 'iframe' );
		tokenState.iframe.setAttribute( 'data-beyounger-card-iframe', '1' );
		tokenState.iframe.setAttribute( 'src', cardForm.url );
		tokenState.iframe.setAttribute( 'title', 'Secure card form' );
		tokenState.iframe.setAttribute( 'loading', 'lazy' );
		tokenState.iframe.setAttribute( 'referrerpolicy', 'no-referrer-when-downgrade' );
		tokenState.iframe.style.border = '0';
		tokenState.iframe.style.height = tokenState.height + 'px';
		tokenState.iframe.style.width = '100%';
		getIframeWrapper().appendChild( tokenState.iframe );
		return tokenState.iframe;
	}

	function setCardFrameHeight( height ) {
		tokenState.height = height;
		if ( tokenState.iframe ) {
			tokenState.iframe.style.height = height + 'px';
		}
		if ( tokenState.wrapper ) {
			tokenState.wrapper.style.height = height + 'px';
		}
		document.querySelectorAll( '[data-beyounger-card-placeholder="1"]' ).forEach( function ( placeholder ) {
			placeholder.style.height = height + 'px';
		} );
	}

	function positionCardFrame( placeholder ) {
		if ( ! placeholder || ! tokenState.wrapper || ! document.body.contains( placeholder ) ) {
			return;
		}

		const accordionContent = placeholder.closest( '.wc-block-components-radio-control-accordion-content' );
		const cardRadio = document.getElementById( 'radio-control-wc-payment-method-options-beyounger' );
		if ( cardRadio && ! cardRadio.checked ) {
			if ( accordionContent ) {
				accordionContent.style.removeProperty( 'display' );
			}
			hideCardFrame();
			return;
		}

		if ( accordionContent ) {
			accordionContent.style.display = 'block';
		}

		const rect = placeholder.getBoundingClientRect();
		if ( rect.width <= 0 || rect.height <= 0 ) {
			return;
		}

		tokenState.activePlaceholder = placeholder;
		tokenState.wrapper.style.display = 'block';
		tokenState.wrapper.style.left = rect.left + window.scrollX + 'px';
		tokenState.wrapper.style.top = rect.top + window.scrollY + 'px';
		tokenState.wrapper.style.width = rect.width + 'px';
		tokenState.wrapper.style.height = tokenState.height + 'px';
	}

	function hideCardFrame() {
		if ( tokenState.wrapper ) {
			tokenState.wrapper.style.display = 'none';
		}
	}

	document.addEventListener( 'change', function ( event ) {
		if ( ! event.target || event.target.name !== 'radio-control-wc-payment-method-options' ) {
			return;
		}
		window.setTimeout( function () {
			const cardRadio = document.getElementById( 'radio-control-wc-payment-method-options-beyounger' );
			if ( cardRadio && ! cardRadio.checked ) {
				hideCardFrame();
			}
		}, 0 );
	}, true );

	function resolveTokenWaiters( token ) {
		const waiters = tokenState.waiters.splice( 0 );
		waiters.forEach( function ( waiter ) {
			waiter.resolve( token );
		} );
	}

	function rejectTokenWaiters( message ) {
		const waiters = tokenState.waiters.splice( 0 );
		waiters.forEach( function ( waiter ) {
			waiter.reject( new Error( message || 'Unable to tokenize card.' ) );
		} );
	}

	function requestCardToken( cardForm ) {
		const iframe = getOrCreateCardIframe( cardForm );
		const targetOrigin = new URL( cardForm.url ).origin;
		tokenState.token = '';

		return new Promise( function ( resolve, reject ) {
			const timeout = window.setTimeout( function () {
				tokenState.waiters = tokenState.waiters.filter( function ( waiter ) {
					return waiter.resolve !== resolve;
				} );
				reject( new Error( 'Please complete the secure card form before placing the order.' ) );
			}, 10000 );

			tokenState.waiters.push( {
				resolve: function ( token ) {
					window.clearTimeout( timeout );
					resolve( token );
				},
				reject: function ( error ) {
					window.clearTimeout( timeout );
					reject( error );
				},
			} );

			iframe.contentWindow.postMessage( { type: 'beyounger.create_token' }, targetOrigin );
		} );
	}

	function createLabel( settings, label ) {
		return function Label() {
			return window.wp.element.createElement(
				'span',
				{
					style: {
						alignItems: 'center',
						display: 'inline-flex',
						gap: '8px',
					},
				},
				( settings.icons || ( settings.icon ? [ settings.icon ] : [] ) ).map( function ( icon ) {
					return window.wp.element.createElement( 'img', {
						key: icon,
						src: icon,
						alt: '',
						style: {
							height: '24px',
							width: 'auto',
						},
					} );
				} ),
				window.wp.element.createElement( 'span', null, label )
			);
		};
	}

	function createCardContent( settings, description ) {
		return function CardContent( props ) {
			const element = window.wp.element;
			const eventRegistration = props && props.eventRegistration ? props.eventRegistration : {};
			const emitResponse = props && props.emitResponse ? props.emitResponse : {};
			const cardForm = settings.cardForm || {};
			const containerRef = element.useRef( null );

			element.useEffect( function () {
				if ( ! cardForm.url || ! containerRef.current ) {
					return undefined;
				}

				getOrCreateCardIframe( cardForm );
				setCardFrameHeight( tokenState.height );
				const reposition = function () {
					positionCardFrame( containerRef.current );
				};
				const onPaymentMethodChange = function () {
					window.setTimeout( reposition, 0 );
				};
				reposition();
				window.requestAnimationFrame( reposition );
				window.setTimeout( reposition, 50 );
				const repositionTimer = window.setInterval( reposition, 300 );
				document.addEventListener( 'change', onPaymentMethodChange, true );
				window.addEventListener( 'resize', reposition );
				window.addEventListener( 'scroll', reposition, true );

				return function () {
					window.clearInterval( repositionTimer );
					document.removeEventListener( 'change', onPaymentMethodChange, true );
					window.removeEventListener( 'resize', reposition );
					window.removeEventListener( 'scroll', reposition, true );
					const accordionContent = containerRef.current
						? containerRef.current.closest( '.wc-block-components-radio-control-accordion-content' )
						: null;
					if ( accordionContent ) {
						accordionContent.style.removeProperty( 'display' );
					}
					const cardRadio = document.getElementById( 'radio-control-wc-payment-method-options-beyounger' );
					if ( tokenState.activePlaceholder === containerRef.current || ( cardRadio && ! cardRadio.checked ) ) {
						tokenState.activePlaceholder = null;
						hideCardFrame();
					}
				};
			}, [ cardForm.url ] );

			element.useEffect( function () {
				if ( ! cardForm.url ) {
					return undefined;
				}

				tokenState.orderId = cardForm.order_id || '';
				const allowedOrigins = cardForm.allowed_origins || [ 'https://cashier.beyounger.com' ];
				const onMessage = function ( event ) {
					if ( allowedOrigins.indexOf( event.origin ) === -1 ) {
						return;
					}
					if ( event.data && event.data.type === 'beyounger.cardform_resize' ) {
						let height = parseInt( event.data.height, 10 );
						if ( ! height || height < 90 ) {
							height = 90;
						}
						if ( height > 420 ) {
							height = 420;
						}
						setCardFrameHeight( height );
						positionCardFrame( containerRef.current );
						return;
					}
				if ( event.data && ( event.data.type === 'beyounger.card_error' || event.data.type === 'beyounger.cardform_error' || event.data.type === 'beyounger.payment_error' ) ) {
					rejectTokenWaiters( event.data.message || 'Unable to tokenize card.' );
					return;
				}
					if ( ! event.data || event.data.type !== 'beyounger.card_token' ) {
						return;
					}
					tokenState.token = event.data.card && event.data.card.token ? event.data.card.token : event.data.token || '';
					if ( tokenState.token ) {
						resolveTokenWaiters( tokenState.token );
					}
				};

				window.addEventListener( 'message', onMessage );
				return function () {
					window.removeEventListener( 'message', onMessage );
				};
			}, [ cardForm.url, cardForm.order_id ] );

			element.useEffect( function () {
				if ( ! eventRegistration.onPaymentSetup ) {
					return undefined;
				}

				return eventRegistration.onPaymentSetup( function () {
					return requestCardToken( cardForm ).then( function ( token ) {
						return {
							type: emitResponse.responseTypes && emitResponse.responseTypes.SUCCESS
								? emitResponse.responseTypes.SUCCESS
								: 'success',
							meta: {
								paymentMethodData: {
									beyounger_card_token: token,
									beyounger_card_order_id: tokenState.orderId || cardForm.order_id || '',
								},
							},
						};
					} ).catch( function ( error ) {
						return {
							type: emitResponse.responseTypes && emitResponse.responseTypes.ERROR
								? emitResponse.responseTypes.ERROR
								: 'error',
							message: error.message || 'Please complete the secure card form before placing the order.',
						};
					} );
				} );
			}, [ eventRegistration.onPaymentSetup, emitResponse.responseTypes, cardForm.order_id ] );

			if ( ! cardForm.url ) {
				return window.wp.element.createElement( 'span', null, description );
			}

			return window.wp.element.createElement(
				'div',
				{
					className: 'beyounger-card-form-block',
					'data-beyounger-card-placeholder': '1',
					style: { height: tokenState.height + 'px' },
					ref: containerRef,
				},
				description
					? window.wp.element.createElement( 'p', null, description )
					: null
			);
		};
	}

	function parseLimit( value ) {
		if ( value === null || value === undefined || value === '' ) {
			return null;
		}

		const parsed = parseFloat( value );
		return Number.isFinite( parsed ) && parsed >= 0 ? parsed : null;
	}

	function getCartTotal( args ) {
		const cartTotals = args && ( args.cartTotals || ( args.cart && args.cart.cartTotals ) );
		if ( ! cartTotals ) {
			return null;
		}

		const total = cartTotals.total_price || cartTotals.totalPrice || cartTotals.total || cartTotals.total_items;
		if ( total === null || total === undefined || total === '' ) {
			return null;
		}

		const parsed = parseFloat( total );
		if ( ! Number.isFinite( parsed ) ) {
			return null;
		}

		const minorUnit = parseInt( cartTotals.currency_minor_unit || cartTotals.currencyMinorUnit || '2', 10 );
		if ( Number.isFinite( minorUnit ) && minorUnit > 0 && String( total ).indexOf( '.' ) === -1 ) {
			return parsed / Math.pow( 10, minorUnit );
		}

		return parsed;
	}

	function canMakePaymentForOrderAmount( settings, args ) {
		const limits = settings.orderLimits || {};
		const min = parseLimit( limits.min );
		const max = parseLimit( limits.max );

		if ( min === null && max === null ) {
			return true;
		}

		const total = getCartTotal( args );
		if ( total === null ) {
			return true;
		}

		if ( min !== null && total < min ) {
			return false;
		}

		if ( max !== null && total > max ) {
			return false;
		}

		return true;
	}

	methods.forEach( function ( name ) {
		const settings = window.wc.wcSettings.getSetting( name + '_data', null );

		if ( ! settings ) {
			return;
		}

		const label = decode( settings.title || 'Credit card' );
		const description = settings.description ? decode( settings.description ) : '';
		const Label = createLabel( settings, label );
		const Content = 'beyounger' === name
			? createCardContent( settings, description )
			: function () {
				return window.wp.element.createElement( 'span', null, description );
			};

		window.wc.wcBlocksRegistry.registerPaymentMethod( {
			name: name,
			label: window.wp.element.createElement( Label, null ),
			content: window.wp.element.createElement( Content, null ),
			edit: window.wp.element.createElement( Content, null ),
			canMakePayment: function ( args ) {
				return canMakePaymentForOrderAmount( settings, args );
			},
			ariaLabel: label,
			supports: {
				features: settings.supports || [ 'products' ],
			},
		} );
	} );
} )();
