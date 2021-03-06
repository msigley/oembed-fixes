<?php
/*
Plugin Name: oEmbed Fixes
Plugin URI: http://github.com/msigley/
Description: Fixes common issues with oEmbeds.
Version: 2.0.1
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
		@mkdir( plugin_dir_path( __FILE__ ) . 'temp/', 0655 );
		$this->download_oembed_providers();
	}

	public function deactivation() {
		wp_cache_flush();
	}

	public function embed_oembed_html( $html, $url, $attr ) {
		//Fix src parameters
		$current_pos = 0;
		while( $src_pos = stripos( $html, ' src=', $current_pos ) ) {
			$src = '';
			$src_quote = substr( $html, $src_pos+5, 1 );
			$start_quote_pos = $src_pos + 6;
			$end_quote_pos = strpos( $html, $src_quote, $start_quote_pos );
			$src = substr( $html, $src_pos+6, $end_quote_pos - ( $start_quote_pos ) );

			if( strpos($src, '?') )
				$src .= '&';
			else
				$src .= '?';
			
			//Fix z-index
			$src .= 'wmode=opaque';

			//Mark embed as Do Not Track
			$src .= '&dnt=1';

			if( stripos( $src, '://www.youtube.com/embed' ) ) {
				$src = str_replace( '://www.youtube.com/embed', '://www.youtube-nocookie.com/embed', $src );
				//Removes the video suggestions made by youtube when a video is complete and other fixes from the YouTube Iframe API
				$src .= '&rel=0&modestbranding=1&iv_load_policy=3&playsinline=1';
			}

			//Removes the video suggestions made by youtube when a video is complete and other fixes from the YouTube Iframe API
			if ( stripos( $html, 'feature=oembed' ) !== false && strpos($html, 'youtube' ) !== false ) { 
				$html = str_replace( 'feature=oembed', 'feature=oembed&rel=0&modestbranding=1&iv_load_policy=3&playsinline=1', $html );
			}

			$html = substr( $html, 0, $start_quote_pos ) . $src . substr( $html, $end_quote_pos );

			$current_pos = $end_quote_pos + 1;
		}

		//Sandbox all iframe embeds. This prevents them from setting third party cookies.
		if ( stripos( $html, '<iframe' ) !== false )
			$html = str_replace( '<iframe', '<iframe sandbox="allow-scripts allow-presentation allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="origin-when-cross-origin" allow="autoplay;encrypted-media" ', $html );
		
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
		
		$potential_providers = array();
		foreach( $provider_data as $provider ) {
			foreach( $provider['endpoints'] as $endpoint ) {
				if( empty( $endpoint['schemes'] ) )
					continue;

				foreach( $endpoint['schemes'] as $scheme ) {
					$providers[$scheme] = array( $endpoint['url'], false );
					$wildcard_subdomain_pos = strpos( $scheme, '://*.' );
					if( $wildcard_subdomain_pos === false )
						continue;
					
					$potiential_scheme = substr_replace( $scheme, '://', $wildcard_subdomain_pos, 5 );
					$potential_providers[$potiential_scheme] = $providers[$scheme];
				}
			}
		}
		$providers = $providers + $potential_providers;

		$potential_providers = array();
		foreach( $providers as $scheme => $endpoint ) {
			$domain_pos = strpos( $scheme, '://' );
			if( $domain_pos === false )
				continue;
			
			$potiential_scheme = false;
			$protocol = substr( $scheme, 0, $domain_pos );
			if( 'http' === $protocol )
				$potiential_scheme = 'https' . substr( $scheme, $domain_pos );
			else if( 'https' === $protocol )
				$potiential_scheme = 'http' . substr( $scheme, $domain_pos );
			if( $potiential_scheme === false )
				continue;
			
			$potential_providers[$potiential_scheme] = $endpoint;
		}
		$providers = $providers + $potential_providers;

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