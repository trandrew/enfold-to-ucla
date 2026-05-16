<?php
/**
 * Parse Enfold shortcode content into a nested node tree.
 *
 * @package EnfoldToUCLA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETU_Layout_Parser {

	/**
	 * @param string $content Raw shortcode content.
	 * @return array<int, array<string, mixed>>
	 */
	public function parse( $content ) {
		$root = array(
			'type'     => 'root',
			'children' => array(),
		);

		$stack   = array( &$root );
		$offset  = 0;
		$pattern = '/\[(\/?)(av_[a-z0-9_]+)([^\]]*)\]/i';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array(
				array(
					'type' => 'text',
					'text' => $content,
				),
			);
		}

		$count = count( $matches[0] );
		for ( $i = 0; $i < $count; $i++ ) {
			$full       = $matches[0][ $i ][0];
			$match_at   = $matches[0][ $i ][1];
			$is_closing = '/' === $matches[1][ $i ][0];
			$tag        = strtolower( $matches[2][ $i ][0] );
			$attrs_raw  = trim( $matches[3][ $i ][0] );

			if ( $match_at > $offset ) {
				$text = substr( $content, $offset, $match_at - $offset );
				if ( '' !== $text ) {
					$this->append_child(
						$stack,
						array(
							'type' => 'text',
							'text' => $text,
						)
					);
				}
			}

			$offset = $match_at + strlen( $full );

			if ( $is_closing ) {
				$this->close_tag( $stack, $tag );
				continue;
			}

			$self_closing = '' !== $attrs_raw && '/' === substr( $attrs_raw, -1 );
			if ( $self_closing ) {
				$attrs_raw = rtrim( substr( $attrs_raw, 0, -1 ) );
			}
			if ( $this->is_implicitly_self_closing_tag( $tag ) ) {
				$self_closing = true;
			}

			$node = array(
				'type'     => 'shortcode',
				'tag'      => $tag,
				'attrs'    => $this->parse_attrs( $attrs_raw ),
				'children' => array(),
			);

			$this->append_child( $stack, $node );

			if ( ! $self_closing ) {
				$last_index = count( $stack[ count( $stack ) - 1 ]['children'] ) - 1;
				$stack[]    = &$stack[ count( $stack ) - 1 ]['children'][ $last_index ];
			}
		}

		if ( $offset < strlen( $content ) ) {
			$tail = substr( $content, $offset );
			if ( '' !== $tail ) {
				$this->append_child(
					$stack,
					array(
						'type' => 'text',
						'text' => $tail,
					)
				);
			}
		}

		return $root['children'];
	}

	/**
	 * @param array<int, array<string, mixed>> $stack Stack by reference.
	 * @param array<string, mixed>              $child Child node.
	 * @return void
	 */
	private function append_child( &$stack, $child ) {
		$top_index = count( $stack ) - 1;
		if ( ! isset( $stack[ $top_index ]['children'] ) || ! is_array( $stack[ $top_index ]['children'] ) ) {
			$stack[ $top_index ]['children'] = array();
		}
		$stack[ $top_index ]['children'][] = $child;
	}

	/**
	 * @param array<int, array<string, mixed>> $stack Stack by reference.
	 * @param string                           $tag Tag name.
	 * @return void
	 */
	private function close_tag( &$stack, $tag ) {
		if ( count( $stack ) <= 1 ) {
			return;
		}

		for ( $i = count( $stack ) - 1; $i >= 1; $i-- ) {
			if ( isset( $stack[ $i ]['tag'] ) && $stack[ $i ]['tag'] === $tag ) {
				$stack = array_slice( $stack, 0, $i );
				return;
			}
		}
	}

	/**
	 * @param string $attrs_raw Raw attributes from shortcode opening tag.
	 * @return array<string, string>
	 */
	private function parse_attrs( $attrs_raw ) {
		$attrs = array();

		if ( '' === $attrs_raw ) {
			return $attrs;
		}

		if ( preg_match_all( '/([a-zA-Z0-9_-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')/', $attrs_raw, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				$value = '';
				if ( isset( $pair[3] ) && '' !== $pair[3] ) {
					$value = $pair[3];
				} elseif ( isset( $pair[4] ) ) {
					$value = $pair[4];
				}
				$attrs[ strtolower( $pair[1] ) ] = $value;
			}
		}

		return $attrs;
	}

	/**
	 * Some Enfold shortcodes are emitted without explicit closing tags.
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	private function is_implicitly_self_closing_tag( $tag ) {
		return in_array(
			strtolower( $tag ),
			array(
				'av_hr',
				'av_team_member', // all data in attrs; closing tag often absent in real content
			),
			true
		);
	}
}
