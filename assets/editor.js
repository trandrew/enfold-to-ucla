( function ( wp, config ) {
	const __ = wp.i18n.__;
	const registerPlugin = wp.plugins.registerPlugin;
	const PluginMoreMenuItem = wp.editPost.PluginMoreMenuItem;
	const blockEditorStore = wp.blockEditor.store;
	const Modal = wp.components.Modal;
	const Button = wp.components.Button;
	const Notice = wp.components.Notice;
	const Spinner = wp.components.Spinner;
	const useSelect = wp.data.useSelect;
	const useDispatch = wp.data.useDispatch;
	const useState = wp.element.useState;
	const Fragment = wp.element.Fragment;
	const createElement = wp.element.createElement;
	const apiFetch = wp.apiFetch;
	const createBlock = wp.blocks.createBlock;
	const rawHandler = wp.blocks.rawHandler;
	const serialize = wp.blocks.serialize;

	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

	function hasEnfoldLayoutShortcode( text ) {
		return /\[av_[a-z0-9_]+/i.test( text || '' );
	}

	function getShortcodeText( block ) {
		if ( block && block.attributes && typeof block.attributes.text === 'string' && block.attributes.text.trim() ) {
			return block.attributes.text;
		}

		if ( block && typeof block.originalContent === 'string' && block.originalContent.trim() ) {
			return block.originalContent
				.replace( /<!--\s*wp:shortcode\s*-->/gi, '' )
				.replace( /<!--\s*\/wp:shortcode\s*-->/gi, '' )
				.trim();
		}

		if ( block && block.attributes && typeof block.attributes.content === 'string' && block.attributes.content.trim() ) {
			return block.attributes.content;
		}

		if ( block ) {
			try {
				const serialized = serialize( [ block ] );
				if ( serialized && serialized.trim() ) {
					return serialized
						.replace( /<!--\s*wp:shortcode[^>]*-->/gi, '' )
						.replace( /<!--\s*\/wp:shortcode\s*-->/gi, '' )
						.trim();
				}
			} catch ( e ) {
				// Ignore serialization fallback errors and return empty.
			}
		}

		return '';
	}

	function flattenBlocks( blocks ) {
		const flat = [];
		( blocks || [] ).forEach( function walk( block ) {
			flat.push( block );
			( block.innerBlocks || [] ).forEach( walk );
		} );
		return flat;
	}

	function toBlocks( nodes ) {
		const output = [];

		( nodes || [] ).forEach( function ( node ) {
			if ( node.name === 'core/html' && node.attributes && typeof node.attributes.content === 'string' && node.attributes.content.trim() ) {
				const parsed = rawHandler( { HTML: node.attributes.content } ) || [];
				if ( parsed.length ) {
					output.push.apply( output, parsed );
					return;
				}
			}

			output.push(
				createBlock( node.name, node.attributes || {}, toBlocks( node.innerBlocks || [] ) )
			);
		} );

		return output;
	}

	function extractEnfoldShortcodeBodiesFromBlocks( blocks ) {
		const serialized = serialize( blocks || [] );
		const matches = [];
		const pattern = /<!--\s*wp:shortcode[^>]*-->\s*([\s\S]*?)\s*<!--\s*\/wp:shortcode\s*-->/gi;
		let match;

		while ( ( match = pattern.exec( serialized ) ) !== null ) {
			const body = ( match[1] || '' ).trim();
			if ( hasEnfoldLayoutShortcode( body ) ) {
				matches.push( body );
			}
		}

		return matches;
	}

	function ConvertControl() {
		const stateOpen = useState( false );
		const stateLoading = useState( false );
		const stateError = useState( '' );
		const statePayload = useState( null );

		const isOpen = stateOpen[0];
		const setIsOpen = stateOpen[1];
		const isLoading = stateLoading[0];
		const setIsLoading = stateLoading[1];
		const error = stateError[0];
		const setError = stateError[1];
		const payload = statePayload[0];
		const setPayload = statePayload[1];

		const topLevelBlocks = useSelect( function ( select ) {
			const editorSelect = select( blockEditorStore );
			return editorSelect.getBlocks();
		}, [] );

		const shortcodeTargets = useSelect( function ( select ) {
			const editorSelect = select( blockEditorStore );
			const allBlocks = flattenBlocks( editorSelect.getBlocks() );
			return allBlocks.filter( function ( block ) {
				if ( block.name !== 'core/shortcode' ) {
					return false;
				}
				const shortcodeText = getShortcodeText( block );
				return hasEnfoldLayoutShortcode( shortcodeText );
			} );
		}, [] );

		const extractedBodies = extractEnfoldShortcodeBodiesFromBlocks( topLevelBlocks );
		const canConvert = extractedBodies.length > 0;
		const contentToConvert = extractedBodies.join( '\n\n' );
		const blockDispatch = useDispatch( blockEditorStore );

		function openSummary() {
			setError( '' );
			setIsLoading( true );
			setIsOpen( true );
			setPayload( null );

			apiFetch( {
				path: config.route,
				method: 'POST',
				data: { content: contentToConvert },
			} ).then( function ( result ) {
				setPayload( result );
			} ).catch( function ( e ) {
				setError( ( e && e.message ) || __( 'Failed to generate conversion summary.', 'enfold-to-ucla' ) );
			} ).finally( function () {
				setIsLoading( false );
			} );
		}

		function runConvert() {
			if ( ! canConvert || ! payload || ! payload.blocks || ! payload.blocks.length ) {
				return;
			}
			const convertedBlocks = toBlocks( payload.blocks );
			const nextTopLevel = [];
			let inserted = false;

			( topLevelBlocks || [] ).forEach( function ( block ) {
				const isTarget = block.name === 'core/shortcode' && hasEnfoldLayoutShortcode( getShortcodeText( block ) );
				if ( isTarget ) {
					if ( ! inserted ) {
						nextTopLevel.push.apply( nextTopLevel, convertedBlocks );
						inserted = true;
					}
					return;
				}
				nextTopLevel.push( block );
			} );

			if ( ! inserted ) {
				nextTopLevel.push.apply( nextTopLevel, convertedBlocks );
			}

			blockDispatch.resetBlocks( nextTopLevel );
			setIsOpen( false );
		}

		if ( ! canConvert ) {
			return null;
		}

		return createElement(
			Fragment,
			null,
			createElement(
				PluginMoreMenuItem,
				{
					onClick: openSummary,
					icon: null,
				}
				,
				__( 'Convert Enfold shortcodes on page', 'enfold-to-ucla' )
			),
			isOpen && createElement(
				Modal,
				{
					title: __( 'Enfold Conversion Summary', 'enfold-to-ucla' ),
					onRequestClose: function () {
						setIsOpen( false );
					},
				},
				isLoading && createElement( Spinner, null ),
				error && createElement( Notice, { status: 'error', isDismissible: false }, error ),
				! isLoading && payload && createElement(
					'div',
					null,
					createElement(
						'p',
						null,
						__( 'All Enfold shortcode blocks on this page will be converted together so layout context is preserved.', 'enfold-to-ucla' )
					),
					createElement( 'p', null, extractedBodies.length + ' shortcode block(s) detected.' ),
					createElement(
						'p',
						null,
						'Returned top-level blocks: ' + ( ( payload.blocks || [] ).length )
					),
					createElement(
						'p',
						null,
						'Top-level names: ' + ( ( payload.blocks || [] ).map( function ( block ) {
							return block.name;
						} ).join( ', ' ) || 'none' )
					),
					createElement(
						'ul',
						null,
						( payload.summary || [] ).map( function ( item, index ) {
							return createElement(
								'li',
								{ key: item.sourceShortcode + '-' + index },
								createElement( 'strong', null, item.sourceShortcode ),
								' -> ',
								item.targetName,
								item.requiresManualReview ? ' (manual review)' : ''
							);
						} )
					),
					createElement(
						'div',
						{ style: { marginTop: '16px', display: 'flex', gap: '8px' } },
						createElement(
							Button,
							{
								variant: 'secondary',
								onClick: function () {
									setIsOpen( false );
								},
							},
							__( 'Cancel', 'enfold-to-ucla' )
						),
						createElement(
							Button,
							{ variant: 'primary', onClick: runConvert },
							__( 'Convert', 'enfold-to-ucla' )
						)
					)
				)
			)
		);
	}

	registerPlugin( 'enfold-to-ucla-convert-control', {
		render: ConvertControl,
		icon: null,
	} );
}( window.wp, window.etuConfig ) );
