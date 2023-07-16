<?php
/**
 * @package Share_On_Mastodon\Notes
 */

namespace Share_On_Mastodon\Notes;

use Share_On_Mastodon\League\HTMLToMarkdown\Converter\HeaderConverter;
use Share_On_Mastodon\League\HTMLToMarkdown\HtmlConverter;

/**
 * Main plugin class.
 */
class Plugin {
	/**
	 * This class's single instance.
	 *
	 * @var Plugin $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * @var \Share_On_Mastodon\League\HTMLToMarkdown\HtmlConverter $converter
	 */
	private $converter;

	/**
	 * Returns the single instance of this class.
	 *
	 * @return Plugin This class's single instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers callback functions.
	 */
	public function register() {
		if ( null === $this->converter ) {
			$this->converter = new HtmlConverter();

			// Omit the two spaces before a line break.
			$this->converter->getConfig()->setOption( 'hard_break', true );
			// Pound signs rather than underlines, for `h2` elements.
			$this->converter->getConfig()->setOption( 'header_style', HeaderConverter::STYLE_ATX );
		}

		add_filter( 'share_on_mastodon_toot_args', array( $this, 'filter_args' ), 100, 2 );
	}

	/**
	 * Filters a status's arguments before it is sent to Mastodon.
	 *
	 * @param  array    $args Status arguments.
	 * @param  \WP_Post $post Post object.
	 * @return array          Altered arguments.
	 */
	public function filter_args( $args, $post ) {
		if ( 'indieblocks_note' !== $post->post_type ) {
			// Do nothing.
			return $args;
		}

		// For notes, replace status with post content.
		if ( class_exists( 'Jetpack_Geo_Location' ) ) {
			// Prevent Jetpack from attaching location details.
			$jp_geo_loc = \Jetpack_Geo_Location::init();
			remove_filter( 'the_content', array( $jp_geo_loc, 'the_content_microformat' ) );
		}

		// Apply `the_content` filters so as to have smart quotes and whatnot.
		$status = apply_filters( 'the_content', $post->post_content );

		if ( $jp_geo_loc ) {
			// Re-add the removed filter.
			add_filter( 'the_content', array( $jp_geo_loc, 'the_content_microformat' ) );
		}

		// Next, attempt to correectly thread replies-to-self.
		if ( preg_match( '~<div class="u-in-reply-to h-cite">.*<a.+?href="' . home_url( '/' ) . '(articles|notes|likes)/(.+?)".*?>.+?</a>.*?</div>~', $status, $matches ) ) {
			// Reply to a post of our own.
			if ( 'articles' === $matches[1] ) {
				$parent = get_page_by_path( rtrim( $matches[2], '/' ), OBJECT, array( 'post' ) );
			} elseif ( 'notes' === $matches[1] ) {
				$parent = get_page_by_path( rtrim( $matches[2], '/' ), OBJECT, array( 'indieblocks_note' ) );
			} elseif ( 'likes' === $matches[1] ) {
				$parent = get_page_by_path( rtrim( $matches[2], '/' ), OBJECT, array( 'indieblocks_like' ) );
			}
			// @todo: Replace the above with `url_to_postid()`? Make it compatible with other permalink setups?

			if ( ! empty( $parent ) ) {
				// If we found a "parent" post, grab its corresponding Mastodon ID.
				$toot_id = basename( get_post_meta( $parent->ID, '_share_on_mastodon_url', true ) );

				if ( ! empty( $toot_id ) ) {
					// We've tooted this parent note. Make Mastodon correctly
					// thread the new reply.
					$args['in_reply_to_id'] = $toot_id;

					// Also, remove introductory line from toot.
					$status = trim( str_replace( $matches[0], '', $status ) );
				}
			} else {
				\Share_On_Mastodon\debug_log( '[Share On Mastodon] Could not convert URL to post ID.' );
			}
		}

		// Now we can convert to Markdown.
		$status = $this->converter->convert( $status );
		// The converter escapes existing "Markdown," and we occasionally use
		// *syntax*, so try to retain that.
		$status = str_replace( '\*', '*', $status );
		$status = trim( $status );

		// Add tags as hashtags.
		$tags = get_the_tags( $post );

		if ( $tags && ! is_wp_error( $tags ) ) {
			$status .= "\n\n";

			foreach ( $tags as $tag ) {
				$tag_name = $tag->name;

				if ( preg_match( '/\s+/', $tag_name ) ) {
					// Try to "CamelCase" multi-word tags.
					$tag_name = preg_replace( '/\s+/', ' ', $tag_name );
					$tag_name = explode( ' ', $tag_name );
					$tag_name = implode( '', array_map( 'ucfirst', $tag_name ) );
				}

				$status .= '#' . $tag_name . ' ';
			}
		}

		// Attach shortlink.
		$short_url = get_post_meta( $post->ID, 'short_url', true );

		if ( ! empty( $short_url ) ) {
			$status .= "\n\n(" . $short_url . ')';
		} else {
			// Use a "regular" permalink instead.
			$status .= "\n\n(" . get_permalink( $post ) . ')';
		}

		// Strip any remaining HTML tags (but leave line breaks intact).
		$status = sanitize_textarea_field( $status );

		// Prevent double-encoded entities.
		$status = html_entity_decode( $status, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		// Remove superfluous line breaks.
		$status = preg_replace( '~\n\n+~', "\n\n", $status );

		$args['status'] = $status;

		return $args;
	}
}
