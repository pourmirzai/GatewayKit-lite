/**
 * GatewayKit Gutenberg Payment Form Block
 *
 * Plain vanilla JavaScript implementation (No-build / Zero npm dependencies).
 *
 * @package GatewayKit
 */
( function( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var __ = wp.i18n ? wp.i18n.__ : function( text ) { return text; };

	// Block editor components with backward compatibility
	var blockEditor = wp.blockEditor || wp.editor || {};
	var InspectorControls = blockEditor.InspectorControls;

	var components = wp.components || {};
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;
	var Button = components.Button;

	// ServerSideRender resolution across WordPress versions
	var ServerSideRender = ( wp.serverSideRender && ( wp.serverSideRender.default || wp.serverSideRender ) )
		|| components.ServerSideRender;

	var blockData = window.gatewaykitBlockData || {
		forms: [],
		newFormUrl: '',
		editFormUrlBase: '',
		strings: {}
	};

	var strings = blockData.strings || {};

	// SVG icon for GatewayKit Payment Form
	var icon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'currentColor' },
		el( 'path', {
			d: 'M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z'
		} )
	);

	registerBlockType( 'gatewaykit/payment-form', {
		title: strings.title || __( 'GatewayKit Payment Form', 'gatewaykit' ),
		description: strings.description || __( 'Embed a GatewayKit payment or donation form.', 'gatewaykit' ),
		icon: icon,
		category: 'widgets',
		keywords: [
			__( 'payment', 'gatewaykit' ),
			__( 'donate', 'gatewaykit' ),
			__( 'checkout', 'gatewaykit' ),
			__( 'gatewaykit', 'gatewaykit' )
		],
		attributes: {
			formId: {
				type: 'number',
				default: 0
			}
		},
		supports: {
			html: false,
			customClassName: true
		},

		edit: function( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var formId = attributes.formId ? parseInt( attributes.formId, 10 ) : 0;
			var forms = blockData.forms || [];

			// Build select options
			var options = [
				{ value: 0, label: strings.selectForm || __( '-- Select a Payment Form --', 'gatewaykit' ) }
			];

			for ( var i = 0; i < forms.length; i++ ) {
				options.push( {
					value: forms[i].id,
					label: forms[i].title + ' (ID: ' + forms[i].id + ')'
				} );
			}

			// Sidebar settings panel
			var inspectorElements = [
				el( SelectControl, {
					label: strings.chooseForm || __( 'Select Form', 'gatewaykit' ),
					value: formId,
					options: options,
					onChange: function( val ) {
						setAttributes( { formId: parseInt( val, 10 ) || 0 } );
					}
				} )
			];

			if ( formId && blockData.editFormUrlBase ) {
				inspectorElements.push(
					el(
						'p',
						{ style: { marginTop: '12px' } },
						el(
							'a',
							{
								href: blockData.editFormUrlBase + formId,
								target: '_blank',
								rel: 'noopener noreferrer',
								style: { fontSize: '13px', textDecoration: 'none' }
							},
							strings.editForm || __( 'Edit this form in builder ↗', 'gatewaykit' )
						)
					)
				);
			}

			var inspector = InspectorControls
				? el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: strings.formSettings || __( 'Form Settings', 'gatewaykit' ), initialOpen: true },
						inspectorElements
					)
				)
				: null;

			// Canvas preview
			var content;

			if ( ! formId ) {
				var placeholderChildren = [];

				if ( forms.length === 0 ) {
					placeholderChildren.push(
						el(
							'p',
							{ key: 'no-forms-msg', style: { marginBottom: '12px', color: '#64748b' } },
							strings.noFormsFound || __( 'No payment forms found. Create your first payment form to get started.', 'gatewaykit' )
						)
					);
					if ( blockData.newFormUrl ) {
						placeholderChildren.push(
							el(
								Button,
								{
									key: 'create-btn',
									isPrimary: true,
									href: blockData.newFormUrl,
									target: '_blank'
								},
								strings.createForm || __( 'Create a Payment Form', 'gatewaykit' )
							)
						);
					}
				} else {
					placeholderChildren.push(
						el( SelectControl, {
							key: 'form-select',
							value: formId,
							options: options,
							onChange: function( val ) {
								setAttributes( { formId: parseInt( val, 10 ) || 0 } );
							}
						} )
					);
				}

				content = el(
					Placeholder,
					{
						key: 'placeholder',
						icon: icon,
						label: strings.title || __( 'GatewayKit Payment Form', 'gatewaykit' ),
						instructions: strings.instructions || __( 'Select a payment form from the dropdown to embed it on this page.', 'gatewaykit' )
					},
					placeholderChildren
				);
			} else if ( ServerSideRender ) {
				content = el( ServerSideRender, {
					key: 'ssr-preview-' + formId,
					block: 'gatewaykit/payment-form',
					attributes: { formId: formId }
				} );
			} else {
				content = el(
					'div',
					{
						key: 'fallback-preview',
						style: { padding: '20px', border: '1px solid #ddd', borderRadius: '4px' }
					},
					__( 'GatewayKit Form #', 'gatewaykit' ) + formId
				);
			}

			return [ inspector, content ];
		},

		save: function() {
			// Dynamic block: server renders output via render_callback
			return null;
		}
	} );
} )( window.wp );
