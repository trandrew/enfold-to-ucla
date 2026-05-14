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
			case 'av_section':
				$class_name  = trim( 'ucla-section ' . $this->map_section_utilities( $attrs ) );
				$target_name = 'core/group';
				break;
			case 'av_row':
			case 'av_flex_row':
				$class_name  = 'ucla-grid';
				$target_name = 'core/columns';
				$inner_blocks = $this->map_row_columns( $children, $summary );
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
		return array(
			'name'       => 'core/columns',
			'attributes' => array(
				'className' => 'ucla-grid',
			),
			'innerBlocks' => $columns,
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
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		$content  = trim( $this->extract_text_content( $children ) );

		if ( '' === $content ) {
			return array();
		}

		$content = $this->normalize_ucla_table_markup( $content );

		$summary[] = array(
			'sourceShortcode'      => 'av_table',
			'targetType'           => 'core-block',
			'targetName'           => 'core/html (ucla-table)',
			'attributesMapped'     => array(),
			'warnings'             => array(),
			'requiresManualReview' => false,
		);

		return array(
			'name'       => 'core/html',
			'attributes' => array(
				'content' => $content,
			),
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
