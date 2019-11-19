<?php
/*
Plugin Name: oEmbed Fixes
Plugin URI: http://github.com/msigley/
Description: Fixes common issues with oEmbeds.
Version: 1.0.0
Author: Matthew Sigley
License: GPL2
*/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

class oEmbed_Fixes {
	private static $object = null;
	private $providers_file = '';

	private function __construct () {
		$this->providers_file = plugin_dir_path( __FILE__ ) . 'temp/providers.json';

		//Plugin activation
		register_activation_hook( __FILE__, array($this, 'activation') );
		register_deactivation_hook( __FILE__, array($this, 'deactivation') );

		add_filter( 'embed_oembed_html', array( $this, 'embed_oembed_html' ), 10, 3);
		add_filter( 'oembed_providers', array( $this, 'oembed_providers' ), 1);

		if( is_admin() ) {
			add_action( 'admin_init', array($this, 'handle_oembed_providers_update_request') );
			add_action( 'after_plugin_row_' . plugin_basename( __FILE__ ), array( $this, 'after_plugin_row' ) );
		}
	}

	/*
	 * Singleton instance static method
	 */
	static function &object () {
		if ( ! self::$object instanceof oEmbed_Fixes ) {
			self::$object = new oEmbed_Fixes();
		}
		return self::$object;
	}

	public function activation() {
		mkdir( plugin_dir_path( __FILE__ ) . 'temp/', 0655 );
		$this->download_oembed_providers();
	}

	public function deactivation() {
		wp_cache_flush();
	}

	public function embed_oembed_html( $html, $url, $attr ) {
		//Fix z-index on oEmbed
		if ( strpos( $html, '<embed src=' ) !== false ) { 
			$html = str_replace('</param><embed', '</param><param name="wmode" value="opaque"></param><embed wmode="opaque" ', $html); 
		} elseif ( strpos ( $html, 'feature=oembed' ) !== false ) { 
			$html = str_replace( 'feature=oembed', 'feature=oembed&wmode=opaque', $html ); 
		}

		//Removes the video suggestions made by youtube when a video is complete and other fixes from the YouTube Iframe API
		if ( strpos( $html, 'feature=oembed' ) !== false && strpos($html, 'youtube' ) !== false ) { 
			$html = str_replace( 'feature=oembed', 'feature=oembed&rel=0&modestbranding=1&iv_load_policy=3&playsinline=1', $html );
		}

		//Sandbox all iframe embeds. This prevents them from setting third party cookies.
		if ( strpos( $html, "<iframe " ) !== false ) {
			if( strpos($html, '://www.youtube.com/embed' ) !== false ) {
				$html = str_replace( '://www.youtube.com/embed', '://www.youtube-nocookie.com/embed', $html );
			} else {
				$html = str_replace( '<iframe ', '<iframe sandbox="allow-scripts" referrerpolicy="origin-when-cross-origin" ', $html );
			}
		}
		return $html;
	}

	private function download_oembed_providers() {
		$response = wp_remote_get( 'https://oembed.com/providers.json' );
		if( is_wp_error( $response ) || 200 != wp_remote_retrieve_response_code( $response ) )
			return;
		$response_body = wp_remote_retrieve_body( $response );
		if( empty( json_decode( $response_body, true ) ) )
			return;
		
		wp_cache_delete( 'oembed_providers', 'oembed_fixes' );
		file_put_contents( $this->providers_file, $response_body );
	}
	
	public function oembed_providers( $providers ) {
		$allowed_providers = apply_filters( 'allowed_oembed_providers', array() );
		$cache_key = md5( serialize( $allowed_providers ) );
		$cached_providers = wp_cache_get( 'oembed_providers', 'oembed_fixes' );
		if( false !== $cached_providers && isset( $cached_providers[$cache_key] ) )
			return $cached_providers[$cache_key];

		if( !file_exists( $this->providers_file ) )
			return $providers;

		$provider_data = file_get_contents( $this->providers_file );
		if( false === $provider_data )
			return $providers;

		if( !is_array( $cached_providers ) )
			$cached_providers = array();

		$provider_data = json_decode( $provider_data, true );
		$provider_names = array();
		if( function_exists( 'array_column' ) )
			$provider_names = array_column( $provider_data, 'provider_name' );
		else
			$provider_names = array_map( function( $element ) { return $element['provider_name']; }, $provider_data );
		
		$provider_data = array_combine( $provider_names, $provider_data );
		if( !empty( $allowed_providers ) )
			$provider_data = array_intersect_key( $provider_data, array_flip( $allowed_providers ) );

		$providers = array();
		foreach( $provider_data as $provider ) {
			foreach( $provider['endpoints'] as $endpoint ) {
				foreach( $endpoint['schemes'] as $scheme ) {
					$providers[$scheme] = array( $endpoint['url'], false );
				}
			}
		}

		$cached_providers[$cache_key] = $providers;
		wp_cache_set( 'oembed_providers', $cached_providers, 'oembed_fixes' ); // Cache oembed providers for a week
		return $providers;
	}

	public function after_plugin_row( $plugin_file ) {
		if( !is_plugin_active( $plugin_file ) )
			return;
		
		?>
		<tr class="plugin-update-tr">
			<td colspan="3" class="plugin-update">
				<div class="update-message">
					<?php if( file_exists( $this->providers_file ) ) : ?>
						oEmbed provider list file last updated on <?php echo date( 'F j, Y \a\t g:ia', filemtime( $this->providers_file ) + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>.
					<?php else : ?>
						oEmbed provider list file doesn't exist.
					<?php endif; ?>
					&nbsp;<a href="<?php echo admin_url( '?update_oembed_providers=1', 'https' ); ?>">Update oEmbed provider list file</a>.
				</div>
			</td>
		</tr>
		<?php
	}

	public function handle_oembed_providers_update_request() {
		if( empty( $_GET['update_oembed_providers'] ) )
			return;

		$this->download_oembed_providers();

		wp_redirect( admin_url( 'plugins.php#oembed-fixes', 'https'), 307 );
		die();
	}
}

$oEmbed_Fixes = oEmbed_Fixes::object();