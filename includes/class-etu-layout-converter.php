<?php
/**
 * Convert parsed Enfold shortcode nodes into block payloads.
 *
 * @package EnfoldToUCLA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETU_Layout_Converter {

	/**
	 * @var ETU_Layout_Parser
	 */
	private $parser;

	/**
	 * @param ETU_Layout_Parser $parser Parser service.
	 */
	public function __construct( ETU_Layout_Parser $parser ) {
		$this->parser = $parser;
	}

	/**
	 * @param string $shortcode_content Raw shortcode content.
	 * @return array<string, mixed>
	 */
	public function convert( $shortcode_content ) {
		$nodes   = $this->parser->parse( $shortcode_content );
		$summary = array();
		$blocks  = $this->nodes_to_blocks( $nodes, $summary );
		$blocks  = $this->wrap_top_level_columns( $blocks );

		return array(
			'hasShortcodes' => ! empty( $summary ),
			'summary'       => $summary,
			'blocks'        => $blocks,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes Node tree.
	 * @param array<int, array<string, mixed>> $summary Conversion summary by reference.
	 * @return array<int, array<string, mixed>>
	 */
	private function nodes_to_blocks( $nodes, &$summary ) {
		$blocks = array();

		foreach ( $nodes as $node ) {
			if ( 'text' === ( $node['type'] ?? '' ) ) {
				$text = trim( wp_strip_all_tags( (string) $node['text'] ) );
				if ( '' !== $text ) {
					$blocks[] = array(
						'name'       => 'core/paragraph',
						'attributes' => array(
							'content' => esc_html( $text ),
						),
						'innerBlocks' => array(),
					);
				}
				continue;
			}

			if ( 'shortcode' !== ( $node['type'] ?? '' ) ) {
				continue;
			}

			$mapped = $this->map_shortcode_node( $node, $summary );
			if ( ! empty( $mapped ) ) {
				$blocks[] = $mapped;
			}
		}

		return $blocks;
	}

	/**
	 * @param array<string, mixed> $node Shortcode node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator by reference.
	 * @return array<string, mixed>
	 */
	private function map_shortcode_node( $node, &$summary ) {
		$tag      = (string) $node['tag'];
		$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();

		$inner_blocks = $this->nodes_to_blocks( $children, $summary );
		$class_name   = '';
		$target_name  = '';
		$column_span  = null;

		switch ( $tag ) {
			case 'av_textblock':
				return $this->map_textblock_node( $node, $summary );
			case 'av_heading':
				return $this->map_heading_node( $node, $summary );
			case 'av_table':
				return $this->map_table_node( $node, $summary );
			case 'av_hr':
				return $this->map_separator_node( $node, $summary );
			case 'av_image':
				return $this->map_image_node( $node, $summary );
			case 'av_slideshow':
			case 'av_slideshow_full':
				return $this->map_slideshow_node( $node, $summary );
			case 'av_slide':
			case 'av_slide_full':
				return $this->map_slide_node( $node, $summary );
			case 'av_section':
				$class_name  = trim( 'ucla-section ' . $this->map_section_utilities( $attrs ) );
				$target_name = 'core/group';
				break;
			case 'av_row':
			case 'av_flex_row':
				$class_name  = 'ucla-section';
				$target_name = 'core/group';
				$inner_blocks = $this->flatten_row_columns( $children, $summary );
				break;
			case 'av_one_full':
				$class_name  = 'ucla-group-full';
				$target_name = 'core/group';
				break;
			case 'av_one_half':
				$target_name = 'core/column';
				$column_span = 6;
				break;
			case 'av_one_third':
				$target_name = 'core/column';
				$column_span = 4;
				break;
			case 'av_two_third':
				$target_name = 'core/column';
				$column_span = 8;
				break;
			case 'av_one_fourth':
				$target_name = 'core/column';
				$column_span = 3;
				break;
			case 'av_three_fourth':
				$target_name = 'core/column';
				$column_span = 9;
				break;
			case 'av_flex_column':
				$target_name = 'core/column';
				$column_span = $this->normalize_width_to_span( $attrs['width'] ?? '' );
				break;
			default:
				$target_name = 'core/group';
				$class_name  = 'etu-manual-review';
				$summary[]   = array(
					'sourceShortcode'     => $tag,
					'targetType'          => 'html-wrapper',
					'targetName'          => 'core/group .etu-manual-review',
					'attributesMapped'    => array(),
					'warnings'            => array( 'Unsupported shortcode in phase 1 layout converter.' ),
					'requiresManualReview' => true,
				);
				return array(
					'name'       => $target_name,
					'attributes' => array(
						'className' => $class_name,
					),
					'innerBlocks' => $inner_blocks,
				);
		}

		$summary[] = array(
			'sourceShortcode'      => $tag,
			'targetType'           => 'core-block',
			'targetName'           => 'core/column' === $target_name ? $target_name : $target_name . ( '' !== $class_name ? ' .' . $class_name : '' ),
			'attributesMapped'     => $attrs,
			'warnings'             => array(),
			'requiresManualReview' => false,
		);

		$attributes = array();
		if ( '' !== $class_name ) {
			$attributes['className'] = $class_name;
		}
		if ( 'core/column' === $target_name ) {
			$span                = is_int( $column_span ) ? $column_span : 12;
			$attributes['width'] = (string) round( ( $span / 12 ) * 100, 2 ) . '%';
		}

		return array(
			'name'       => $target_name,
			'attributes' => $attributes,
			'innerBlocks' => $inner_blocks,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $children Children from row shortcode.
	 * @param array<int, array<string, mixed>> $summary Summary by reference.
	 * @return array<int, array<string, mixed>>
	 */
	private function map_row_columns( $children, &$summary ) {
		$columns   = array();
		$fallbacks = array();

		foreach ( $children as $child ) {
			if ( isset( $child['type'], $child['tag'] ) && 'shortcode' === $child['type'] && $this->is_column_shortcode( (string) $child['tag'] ) ) {
				$columns[] = $this->map_shortcode_node( $child, $summary );
				continue;
			}
			$fallbacks[] = $child;
		}

		if ( empty( $columns ) ) {
			$inner = $this->nodes_to_blocks( $children, $summary );
			return array(
				array(
					'name'       => 'core/column',
					'attributes' => array(
						'width' => '100%',
					),
					'innerBlocks' => $inner,
				),
			);
		}

		if ( ! empty( $fallbacks ) ) {
			$columns[] = array(
				'name'       => 'core/column',
				'attributes' => array(
					'width' => '100%',
				),
				'innerBlocks' => $this->nodes_to_blocks( $fallbacks, $summary ),
			);
		}

		return $columns;
	}

	/**
	 * Extract and vertically stack the inner blocks from all column children of a row.
	 *
	 * @param array<int, array<string, mixed>> $children Children from row shortcode.
	 * @param array<int, array<string, mixed>> $summary Summary by reference.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten_row_columns( $children, &$summary ) {
		$blocks = array();

		foreach ( $children as $child ) {
			if (
				isset( $child['type'], $child['tag'] ) &&
				'shortcode' === $child['type'] &&
				$this->is_column_shortcode( (string) $child['tag'] )
			) {
				$col_children = isset( $child['children'] ) && is_array( $child['children'] ) ? $child['children'] : array();
				$blocks       = array_merge( $blocks, $this->nodes_to_blocks( $col_children, $summary ) );
			} else {
				$blocks = array_merge( $blocks, $this->nodes_to_blocks( array( $child ), $summary ) );
			}
		}

		return $blocks;
	}

	/**
	 * Wrap root-level column blocks in a columns container.
	 *
	 * @param array<int, array<string, mixed>> $blocks Block list.
	 * @return array<int, array<string, mixed>>
	 */
	private function wrap_top_level_columns( $blocks ) {
		$result          = array();
		$pending_columns = array();

		foreach ( $blocks as $block ) {
			$name = isset( $block['name'] ) ? (string) $block['name'] : '';
			if ( 'core/column' === $name ) {
				$pending_columns[] = $block;
				continue;
			}

			if ( ! empty( $pending_columns ) ) {
				$result          = array_merge( $result, $this->make_columns_blocks_for_pending( $pending_columns ) );
				$pending_columns = array();
			}

			$result[] = $block;
		}

		if ( ! empty( $pending_columns ) ) {
			$result = array_merge( $result, $this->make_columns_blocks_for_pending( $pending_columns ) );
		}

		return $result;
	}

	/**
	 * Split pending columns into valid rows by width.
	 *
	 * @param array<int, array<string, mixed>> $columns Columns.
	 * @return array<int, array<string, mixed>>
	 */
	private function make_columns_blocks_for_pending( $columns ) {
		$rows            = array();
		$current_row     = array();
		$current_percent = 0.0;

		foreach ( $columns as $column ) {
			$percent = $this->column_percent_from_block( $column );

			if ( ! empty( $current_row ) && ( $current_percent + $percent ) > 100.01 ) {
				$rows[]          = $this->make_columns_block( $current_row );
				$current_row     = array();
				$current_percent = 0.0;
			}

			$current_row[]   = $column;
			$current_percent += $percent;
		}

		if ( ! empty( $current_row ) ) {
			$rows[] = $this->make_columns_block( $current_row );
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $column Column block.
	 * @return float
	 */
	private function column_percent_from_block( $column ) {
		if (
			isset( $column['attributes'] ) &&
			is_array( $column['attributes'] ) &&
			isset( $column['attributes']['width'] ) &&
			is_string( $column['attributes']['width'] )
		) {
			$raw = trim( $column['attributes']['width'] );
			if ( false !== strpos( $raw, '%' ) ) {
				$value = (float) str_replace( '%', '', $raw );
				if ( $value > 0 ) {
					return $value;
				}
			}
		}

		return 100.0;
	}

	/**
	 * @param array<int, array<string, mixed>> $columns Columns.
	 * @return array<string, mixed>
	 */
	private function make_columns_block( $columns ) {
		$inner_blocks = array();
		foreach ( $columns as $column ) {
			$col_inner    = isset( $column['innerBlocks'] ) && is_array( $column['innerBlocks'] ) ? $column['innerBlocks'] : array();
			$inner_blocks = array_merge( $inner_blocks, $col_inner );
		}

		return array(
			'name'       => 'core/group',
			'attributes' => array(
				'className' => 'ucla-section',
			),
			'innerBlocks' => $inner_blocks,
		);
	}

	/**
	 * @param array<string, mixed> $node av_image node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator.
	 * @return array<string, mixed>
	 */
	private function map_image_node( $node, &$summary ) {
		$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();

		$image_attrs  = array();
		$attrs_mapped = array();
		$warnings     = array();

		// URL — Enfold stores the sized URL in src; fall back to resolving from attachment ID
		$src = trim( $attrs['src'] ?? '' );
		if ( '' === $src && ! empty( $attrs['attachment'] ) && is_numeric( $attrs['attachment'] ) ) {
			$size    = ! empty( $attrs['attachment_size'] ) ? sanitize_key( $attrs['attachment_size'] ) : 'full';
			$img_src = wp_get_attachment_image_src( (int) $attrs['attachment'], $size );
			$src     = $img_src ? $img_src[0] : '';
		}

		if ( '' === $src ) {
			return array();
		}

		$image_attrs['url']  = esc_url_raw( $src );
		$attrs_mapped['src'] = $src;

		// Attachment ID
		if ( ! empty( $attrs['attachment'] ) && is_numeric( $attrs['attachment'] ) ) {
			$image_attrs['id']          = (int) $attrs['attachment'];
			$attrs_mapped['attachment'] = $attrs['attachment'];
		}

		// Size slug
		if ( ! empty( $attrs['attachment_size'] ) ) {
			$image_attrs['sizeSlug']         = sanitize_key( $attrs['attachment_size'] );
			$attrs_mapped['attachment_size'] = $attrs['attachment_size'];
		}

		// Alignment
		$align = strtolower( trim( $attrs['align'] ?? '' ) );
		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$image_attrs['align'] = $align;
			$attrs_mapped['align'] = $align;
		}

		// Caption — `caption='yes'` is the flag; text is stored as the shortcode's inner content
		if ( 'yes' === ( $attrs['caption'] ?? '' ) ) {
			$caption_text = trim( $this->extract_text_content( $children ) );
			if ( '' !== $caption_text ) {
				$image_attrs['caption'] = wp_kses_post( $caption_text );
				$attrs_mapped['caption'] = 'yes';
			}
			foreach ( array( 'overlay_opacity', 'overlay_color', 'overlay_text_color', 'font_size' ) as $overlay_attr ) {
				if ( ! empty( $attrs[ $overlay_attr ] ) ) {
					$warnings[] = 'attr-not-mapped: ' . $overlay_attr . ' (caption overlay)';
				}
			}
		}

		// Link — handle Enfold {type},{url} format and lightbox special value
		$raw_link = trim( $attrs['link'] ?? '' );
		if ( 'lightbox' === $raw_link ) {
			$image_attrs['linkDestination'] = 'media';
			$attrs_mapped['link']           = $raw_link;
			$warnings[]                     = 'link=lightbox — converted to linkDestination=media, verify lightbox behaviour';
		} elseif ( '' !== $raw_link ) {
			$href = $this->parse_enfold_link( $raw_link );
			if ( '' !== $href ) {
				$image_attrs['href']            = $href;
				$image_attrs['linkDestination'] = 'custom';
				$attrs_mapped['link']           = $raw_link;
				if ( '_blank' === ( $attrs['target'] ?? '' ) ) {
					$image_attrs['linkTarget'] = '_blank';
					$image_attrs['rel']        = 'noreferrer noopener';
				}
			}
		}

		// Visual effects that have no block equivalent
		foreach ( array( 'hover', 'styling', 'appearance' ) as $fx_attr ) {
			if ( ! empty( $attrs[ $fx_attr ] ) ) {
				$warnings[] = 'attr-not-mapped: ' . $fx_attr;
			}
		}
		$animation = $attrs['animation'] ?? '';
		if ( '' !== $animation && 'no-animation' !== $animation ) {
			$warnings[] = 'attr-not-mapped: animation';
		}

		$summary[] = array(
			'sourceShortcode'      => 'av_image',
			'targetType'           => 'core-block',
			'targetName'           => 'core/image',
			'attributesMapped'     => $attrs_mapped,
			'warnings'             => $warnings,
			'requiresManualReview' => ! empty( $warnings ),
		);

		return array(
			'name'        => 'core/image',
			'attributes'  => $image_attrs,
			'innerBlocks' => array(),
		);
	}

	/**
	 * @param array<string, mixed> $node av_slideshow / av_slideshow_full node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator.
	 * @return array<string, mixed>
	 */
	private function map_slideshow_node( $node, &$summary ) {
		$tag      = (string) $node['tag'];
		$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();

		$slide_tags = array( 'av_slide', 'av_slide_full' );
		$slides     = array();
		foreach ( $children as $child ) {
			if (
				isset( $child['type'], $child['tag'] ) &&
				'shortcode' === $child['type'] &&
				in_array( strtolower( (string) $child['tag'] ), $slide_tags, true )
			) {
				$slide = $this->map_slide_node( $child, $summary );
				if ( ! empty( $slide ) ) {
					$slides[] = $slide;
				}
			}
		}

		$carousel_attrs = array( 'perPage' => 1 );
		$attrs_mapped   = array();
		$warnings       = array();

		if ( isset( $attrs['autoplay'] ) && 'true' === strtolower( $attrs['autoplay'] ) ) {
			$carousel_attrs['enableAutoPlay'] = true;
			$attrs_mapped['autoplay']         = $attrs['autoplay'];
		}

		if ( isset( $attrs['interval'] ) && is_numeric( $attrs['interval'] ) ) {
			$carousel_attrs['autoPlayDelay'] = (int) round( (float) $attrs['interval'] * 1000 );
			$attrs_mapped['interval']        = $attrs['interval'];
		}

		foreach ( array( 'animation', 'size', 'stretch', 'control_layout', 'transition_speed' ) as $unmapped_attr ) {
			if ( ! empty( $attrs[ $unmapped_attr ] ) ) {
				$warnings[] = 'attr-not-mapped: ' . $unmapped_attr;
			}
		}

		$summary[] = array(
			'sourceShortcode'      => $tag,
			'targetType'           => 'ucla-block',
			'targetName'           => 'ucla-wordpress-plugin/carousel',
			'attributesMapped'     => $attrs_mapped,
			'warnings'             => $warnings,
			'requiresManualReview' => ! empty( $warnings ),
		);

		return array(
			'name'        => 'ucla-wordpress-plugin/carousel',
			'attributes'  => $carousel_attrs,
			'innerBlocks' => $slides,
		);
	}

	/**
	 * @param array<string, mixed> $node av_slide / av_slide_full node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator.
	 * @return array<string, mixed>
	 */
	private function map_slide_node( $node, &$summary ) {
		$tag   = (string) $node['tag'];
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();

		$inner_blocks = array();
		$attrs_mapped = array();
		$warnings     = array();

		$slide_type = isset( $attrs['slide_type'] ) ? strtolower( trim( $attrs['slide_type'] ) ) : '';
		if ( 'video' === $slide_type ) {
			$warnings[] = 'video-slide-not-supported';
		}

		// Image — resolve URL from attachment ID since av_slide_full never stores it inline
		if ( isset( $attrs['id'] ) && '' !== $attrs['id'] && is_numeric( $attrs['id'] ) ) {
			$attachment_id = (int) $attrs['id'];
			$image_attrs   = array( 'id' => $attachment_id );
			$image_url     = wp_get_attachment_url( $attachment_id );
			if ( $image_url ) {
				$image_attrs['url'] = $image_url;
			}
			$inner_blocks[]     = array(
				'name'        => 'core/image',
				'attributes'  => $image_attrs,
				'innerBlocks' => array(),
			);
			$attrs_mapped['id'] = $attrs['id'];
		}

		// Caption title → heading
		if ( isset( $attrs['title'] ) && '' !== trim( $attrs['title'] ) ) {
			$inner_blocks[]         = array(
				'name'        => 'core/heading',
				'attributes'  => array(
					'content' => wp_kses_post( $attrs['title'] ),
					'level'   => 3,
				),
				'innerBlocks' => array(),
			);
			$attrs_mapped['title'] = $attrs['title'];
		}

		// Caption text → paragraph
		if ( isset( $attrs['content'] ) && '' !== trim( $attrs['content'] ) ) {
			$inner_blocks[]           = array(
				'name'        => 'core/paragraph',
				'attributes'  => array(
					'content' => wp_kses_post( $attrs['content'] ),
				),
				'innerBlocks' => array(),
			);
			$attrs_mapped['content'] = $attrs['content'];
		}

		// CTA button 1
		$url1  = $this->parse_enfold_link( $attrs['link1'] ?? '' );
		$label1 = sanitize_text_field( $attrs['button_label'] ?? '' );
		if ( '' !== $url1 && '' !== $label1 ) {
			$btn_attrs = array(
				'url'  => $url1,
				'text' => $label1,
			);
			if ( isset( $attrs['link_target1'] ) && '_blank' === $attrs['link_target1'] ) {
				$btn_attrs['linkTarget'] = '_blank';
				$btn_attrs['rel']        = 'noreferrer noopener';
			}
			$inner_blocks[]               = array(
				'name'        => 'core/button',
				'attributes'  => $btn_attrs,
				'innerBlocks' => array(),
			);
			$attrs_mapped['link1']        = $attrs['link1'];
			$attrs_mapped['button_label'] = $attrs['button_label'] ?? '';
		}

		// CTA button 2
		$url2   = $this->parse_enfold_link( $attrs['link2'] ?? '' );
		$label2 = sanitize_text_field( $attrs['button_label2'] ?? '' );
		if ( '' !== $url2 && '' !== $label2 ) {
			$btn2_attrs = array(
				'url'  => $url2,
				'text' => $label2,
			);
			if ( isset( $attrs['link_target2'] ) && '_blank' === $attrs['link_target2'] ) {
				$btn2_attrs['linkTarget'] = '_blank';
				$btn2_attrs['rel']        = 'noreferrer noopener';
			}
			$inner_blocks[]                = array(
				'name'        => 'core/button',
				'attributes'  => $btn2_attrs,
				'innerBlocks' => array(),
			);
			$attrs_mapped['link2']         = $attrs['link2'];
			$attrs_mapped['button_label2'] = $attrs['button_label2'];
		}

		// Whole-slide link has no direct equivalent in carousel-slide
		if ( ! empty( $attrs['link'] ) ) {
			$warnings[] = 'attr-not-mapped: link (whole-slide link — add manually)';
		}

		foreach ( array( 'position', 'caption_pos', 'font_color', 'overlay_color', 'overlay_opacity' ) as $unmapped_attr ) {
			if ( ! empty( $attrs[ $unmapped_attr ] ) ) {
				$warnings[] = 'attr-not-mapped: ' . $unmapped_attr;
			}
		}

		$summary[] = array(
			'sourceShortcode'      => $tag,
			'targetType'           => 'ucla-block',
			'targetName'           => 'ucla-wordpress-plugin/carousel-slide',
			'attributesMapped'     => $attrs_mapped,
			'warnings'             => $warnings,
			'requiresManualReview' => ! empty( $warnings ),
		);

		if ( empty( $inner_blocks ) ) {
			return array();
		}

		return array(
			'name'        => 'ucla-wordpress-plugin/carousel-slide',
			'attributes'  => array(),
			'innerBlocks' => $inner_blocks,
		);
	}

	/**
	 * @param array<string, mixed> $node Shortcode node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator.
	 * @return array<string, mixed>
	 */
	private function map_textblock_node( $node, &$summary ) {
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		$content  = $this->extract_text_content( $children );
		$content  = trim( $content );

		if ( '' === $content ) {
			return array();
		}

		$is_rich_markup = (bool) preg_match( '/<\s*(img|h[1-6]|p|div|figure|ul|ol|table|blockquote)\b/i', $content );
		$target_name    = $is_rich_markup ? 'core/html' : 'core/paragraph';
		if ( false !== stripos( $content, '<table' ) ) {
			$content = $this->normalize_ucla_table_markup( $content );
		}

		$summary[] = array(
			'sourceShortcode'      => 'av_textblock',
			'targetType'           => 'core-block',
			'targetName'           => $target_name,
			'attributesMapped'     => array(),
			'warnings'             => array(),
			'requiresManualReview' => false,
		);

		if ( $is_rich_markup ) {
			return array(
				'name'       => 'core/html',
				'attributes' => array(
					'content' => $content,
				),
				'innerBlocks' => array(),
			);
		}

		return array(
			'name'       => 'core/paragraph',
			'attributes' => array(
				'content' => wp_kses_post( $content ),
			),
			'innerBlocks' => array(),
		);
	}

	/**
	 * @param array<string, mixed> $node Shortcode node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator.
	 * @return array<string, mixed>
	 */
	private function map_table_node( $node, &$summary ) {
		$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();

		// Collect av_row children; av_table always uses these for tabular data
		$row_nodes = array();
		foreach ( $children as $child ) {
			if ( isset( $child['type'], $child['tag'] ) && 'shortcode' === $child['type'] && 'av_row' === strtolower( (string) $child['tag'] ) ) {
				$row_nodes[] = $child;
			}
		}

		// Fallback: no av_row children means raw HTML was somehow nested — wrap as-is
		if ( empty( $row_nodes ) ) {
			$content = trim( $this->extract_text_content( $children ) );
			if ( '' === $content ) {
				return array();
			}
			$content   = $this->normalize_ucla_table_markup( $content );
			$summary[] = array(
				'sourceShortcode'      => 'av_table',
				'targetType'           => 'html-wrapper',
				'targetName'           => 'core/html',
				'attributesMapped'     => array(),
				'warnings'             => array( 'no av_row children found — converted to raw HTML' ),
				'requiresManualReview' => true,
			);
			return array(
				'name'        => 'core/html',
				'attributes'  => array( 'content' => $content ),
				'innerBlocks' => array(),
			);
		}

		// Build head / body from av_row nodes
		// Mirrors Enfold's own tag logic: avia-heading-row → th, avia-desc-col → th, else td
		$head_rows = array();
		$body_rows = array();

		foreach ( $row_nodes as $row_node ) {
			$row_attrs  = isset( $row_node['attrs'] ) && is_array( $row_node['attrs'] ) ? $row_node['attrs'] : array();
			$row_style  = $row_attrs['row_style'] ?? '';
			$is_heading = 'avia-heading-row' === $row_style;

			$cells         = array();
			$cell_children = isset( $row_node['children'] ) && is_array( $row_node['children'] ) ? $row_node['children'] : array();

			foreach ( $cell_children as $cell_node ) {
				if ( ! isset( $cell_node['type'], $cell_node['tag'] ) || 'shortcode' !== $cell_node['type'] || 'av_cell' !== strtolower( (string) $cell_node['tag'] ) ) {
					continue;
				}
				$cell_attrs = isset( $cell_node['attrs'] ) && is_array( $cell_node['attrs'] ) ? $cell_node['attrs'] : array();
				$col_style  = $cell_attrs['col_style'] ?? '';
				$text_nodes = isset( $cell_node['children'] ) && is_array( $cell_node['children'] ) ? $cell_node['children'] : array();
				$content    = wp_kses_post( trim( $this->extract_text_content( $text_nodes ) ) );

				$tag = ( $is_heading || 'avia-desc-col' === $col_style ) ? 'th' : 'td';

				$cells[] = array(
					'content' => $content,
					'tag'     => $tag,
				);
			}

			if ( empty( $cells ) ) {
				continue;
			}

			$row = array( 'cells' => $cells );
			if ( $is_heading ) {
				$head_rows[] = $row;
			} else {
				$body_rows[] = $row;
			}
		}

		$caption       = ! empty( $attrs['caption'] ) ? sanitize_text_field( $attrs['caption'] ) : '';
		$is_responsive = ! empty( $attrs['responsive_styling'] ) && false !== strpos( $attrs['responsive_styling'], 'avia_responsive' );

		$summary[] = array(
			'sourceShortcode'      => 'av_table',
			'targetType'           => 'core-block',
			'targetName'           => 'core/table',
			'attributesMapped'     => array_filter( array(
				'caption'            => $caption,
				'responsive_styling' => $attrs['responsive_styling'] ?? '',
			) ),
			'warnings'             => array(),
			'requiresManualReview' => false,
		);

		$table_attrs = array(
			'head'    => $head_rows,
			'body'    => $body_rows,
			'foot'    => array(),
			'caption' => $caption,
		);

		if ( $is_responsive ) {
			$table_attrs['tableResponsive'] = true;
		}

		return array(
			'name'        => 'core/table',
			'attributes'  => $table_attrs,
			'innerBlocks' => array(),
		);
	}

	/**
	 * @param array<string, mixed> $node Shortcode node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator.
	 * @return array<string, mixed>
	 */
	private function map_heading_node( $node, &$summary ) {
		$attrs    = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		$content  = isset( $attrs['heading'] ) ? trim( (string) $attrs['heading'] ) : '';

		if ( '' === $content ) {
			$content = wp_strip_all_tags( $this->extract_text_content( $children ) );
		}

		$tag   = isset( $attrs['tag'] ) ? strtolower( (string) $attrs['tag'] ) : 'h2';
		$level = 2;
		if ( preg_match( '/h([1-6])/', $tag, $matches ) ) {
			$level = (int) $matches[1];
		}

		$summary[] = array(
			'sourceShortcode'      => 'av_heading',
			'targetType'           => 'core-block',
			'targetName'           => 'core/heading',
			'attributesMapped'     => $attrs,
			'warnings'             => array(),
			'requiresManualReview' => false,
		);

		return array(
			'name'       => 'core/heading',
			'attributes' => array(
				'content' => $content,
				'level'   => $level,
			),
			'innerBlocks' => array(),
		);
	}

	/**
	 * @param array<string, mixed> $node Shortcode node.
	 * @param array<int, array<string, mixed>> $summary Summary accumulator.
	 * @return array<string, mixed>
	 */
	private function map_separator_node( $node, &$summary ) {
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();

		$summary[] = array(
			'sourceShortcode'      => 'av_hr',
			'targetType'           => 'core-block',
			'targetName'           => 'core/separator',
			'attributesMapped'     => $attrs,
			'warnings'             => array(),
			'requiresManualReview' => false,
		);

		return array(
			'name'       => 'core/separator',
			'attributes' => array(),
			'innerBlocks' => array(),
		);
	}

	/**
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	private function is_column_shortcode( $tag ) {
		return in_array(
			$tag,
			array(
				'av_one_full',
				'av_one_half',
				'av_one_third',
				'av_two_third',
				'av_one_fourth',
				'av_three_fourth',
				'av_flex_column',
			),
			true
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $children Parsed children.
	 * @return string
	 */
	private function extract_text_content( $children ) {
		$parts = array();

		foreach ( $children as $child ) {
			$type = isset( $child['type'] ) ? (string) $child['type'] : '';
			if ( 'text' === $type && isset( $child['text'] ) ) {
				$parts[] = (string) $child['text'];
				continue;
			}

			if ( 'shortcode' === $type && isset( $child['children'] ) && is_array( $child['children'] ) ) {
				$parts[] = $this->extract_text_content( $child['children'] );
			}
		}

		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Add UCLA table classes/wrappers so converted tables match design system.
	 *
	 * @param string $html Raw table html.
	 * @return string
	 */
	private function normalize_ucla_table_markup( $html ) {
		$content = $html;

		$content = preg_replace_callback(
			'/<table\b([^>]*)>/i',
			function ( $matches ) {
				$attrs      = isset( $matches[1] ) ? $matches[1] : '';
				$class_attr = '';
				$classes    = array( 'ucla-table' );

				if ( preg_match( '/\bclass\s*=\s*([\'"])(.*?)\1/i', $attrs, $class_match ) ) {
					$class_attr = isset( $class_match[2] ) ? $class_match[2] : '';
				}

				if ( '' !== $class_attr ) {
					foreach ( preg_split( '/\s+/', trim( $class_attr ) ) as $class_name ) {
						if ( '' !== $class_name && ! in_array( $class_name, $classes, true ) ) {
							$classes[] = $class_name;
						}
					}
					$attrs = preg_replace( '/\bclass\s*=\s*([\'"])(.*?)\1/i', '', $attrs );
				}

				return '<table class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $attrs . '>';
			},
			$content
		);

		$has_responsive_wrapper = (bool) preg_match( '/<div\b[^>]*class=[\'"][^\'"]*ucla-table__responsive/i', $content );
		if ( ! $has_responsive_wrapper && preg_match( '/<table\b/i', $content ) ) {
			$content = '<div class="ucla-table__responsive">' . $content . '</div>';
		}

		return $content;
	}

	/**
	 * @param array<string, string> $attrs Section attrs.
	 * @return string
	 */
	private function map_section_utilities( $attrs ) {
		$classes = array();

		if ( ! empty( $attrs['color'] ) ) {
			$classes[] = 'etu-bg-' . sanitize_html_class( $attrs['color'] );
		}

		if ( ! empty( $attrs['padding'] ) ) {
			$classes[] = 'etu-space-' . sanitize_html_class( $attrs['padding'] );
		}

		if ( ! empty( $attrs['fullwidth'] ) && 'no' !== strtolower( $attrs['fullwidth'] ) ) {
			$classes[] = 'etu-fullwidth';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Enfold stores links as "{type},{url}" (e.g. "manually,https://example.com").
	 * Extracts the URL portion, returns empty string for placeholders like "manually,http://".
	 *
	 * @param string $raw Raw Enfold link value.
	 * @return string Sanitized URL, or empty string if none.
	 */
	private function parse_enfold_link( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Strip the "{type}," prefix if present
		$comma = strpos( $raw, ',' );
		$url   = false !== $comma ? substr( $raw, $comma + 1 ) : $raw;
		$url   = trim( $url );

		// Reject bare protocol placeholders with no real URL
		if ( '' === $url || 'http://' === $url || 'https://' === $url ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * @param string $width Width string from av_flex_column.
	 * @return int
	 */
	private function normalize_width_to_span( $width ) {
		$normalized = trim( $width );

		$map = array(
			'100%'    => 12,
			'75%'     => 9,
			'66.666%' => 8,
			'66.67%'  => 8,
			'50%'     => 6,
			'33.333%' => 4,
			'33.33%'  => 4,
			'25%'     => 3,
		);

		if ( isset( $map[ $normalized ] ) ) {
			return $map[ $normalized ];
		}

		if ( '' !== $normalized && false !== strpos( $normalized, '%' ) ) {
			$percent = (float) str_replace( '%', '', $normalized );
			if ( $percent > 0 ) {
				$span = (int) round( ( $percent / 100 ) * 12 );
				return max( 1, min( 12, $span ) );
			}
		}

		return 12;
	}
}
