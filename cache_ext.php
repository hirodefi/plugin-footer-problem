<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

spl_autoload_register(
	function( $class )
	{
		if( strpos( $class, 'seraph_accel\\CloudFlareHooksEx' ) === 0 )
			require_once( __DIR__ . '/cache_ext_CloudFlareHooksEx.php' );
	}
);

function _CacheExt_SockDoRequest( $addr, $method, $url, $headers = null, $port = null )
{

	$urlComps = Net::UrlParse( $url );
	if( !$urlComps )
		return( Gen::E_INVALIDARG );

	$urlComps[ 'scheme' ] = 'http';
	$urlComps[ 'host' ] = $addr;

	if( $port !== null )
		$urlComps[ 'port' ] = $port;
	else
		unset( $urlComps[ 'port' ] );

	return( Net::GetHrFromWpRemoteGet( wp_remote_request( Net::UrlDeParse( $urlComps, 0, array( PHP_URL_USER, PHP_URL_PASS, PHP_URL_QUERY, PHP_URL_FRAGMENT ) ), array( 'method' => $method, 'headers' => $headers, 'sslverify' => false ) ) ) );
}

function _CacheExt_Nginx_GetUrlKey( $url, $method = 'GET' )
{
	$urlComps = Net::UrlParse( $url );
	return( md5( Gen::GetArrField( $urlComps, array( 'scheme' ), '' ) . $method . Gen::GetArrField( $urlComps, array( 'host' ), '' ) . Gen::GetArrField( $urlComps, array( 'path' ), '' ) ) );
}

function _CacheExt_Nginx_GetCacheFiles( $keys, $dir, $levels = '1:2' )
{
	$caches = array();
	$levels = explode( ':', $levels );
	foreach( ( array )$keys as $key )
	{
		$path = array();
		$path[] = $dir;
		$offset = 0;

		foreach( $levels as $l )
		{
			$offset = $offset + $l;
			$path[] = substr( $key, 0 - $offset, $l );
		}

		$path[] = $key;
		$caches[] = join( '/', $path );
	}

	return( $caches );
}

function _CacheExt_Nginx_ClearAll( $dir )
{
	Gen::DelDir( $dir, false );
}

function CacheExt_ClearOnExtRequest( $url = null )
{
	$sett = Plugin::SettGet();

	if( isset( $_SERVER[ 'HTTP_X_LSCACHE' ] ) || @preg_match( '@litespeed@i', (isset($_SERVER[ 'SERVER_SOFTWARE' ])?$_SERVER[ 'SERVER_SOFTWARE' ]:'') ) )
	{
		$logInfo = 'LiteSpeed: ';
		if( !headers_sent() )
		{
			$hdr = 'X-LiteSpeed-Purge: public,';

				$hdr .= '*';

			if( is_string( $hdr ) )
			{

				header( $hdr );
				$logInfo .= 'executed, sent \'' . $hdr . '\' header';
			}
		}
		else
			$logInfo .= 'skipped, headers already sent';

		if( (isset($sett[ 'log' ])?$sett[ 'log' ]:null) && (isset($sett[ 'logScope' ][ 'srvClr' ])?$sett[ 'logScope' ][ 'srvClr' ]:null) )
			LogWrite( $logInfo, Ui::MsgInfo, 'Server/cloud cache update' );
	}
}

function CacheExt_Clear( $url = null )
{
	$sett = Plugin::SettGet();
	$hostname = gethostname();

	if( ( defined( 'O2SWITCH_VARNISH_PURGE_KEY' ) ) )
	{
		if( $url )
		{
            if( $urlComps = Net::UrlParse( $url ) )
			{
				$urlComps[ 'scheme' ] = 'https';
				$urlPurge = Net::UrlDeParse( $urlComps, 0, array( PHP_URL_QUERY, PHP_URL_FRAGMENT ) );
				if( isset( $urlComps[ 'query' ] ) )
					_CacheExt_SockDoRequest( $_SERVER[ 'SERVER_ADDR' ], 'PURGE', $urlPurge, array( 'X-Purge-Regex' => '.*', 'X-VC-Purge-Key' => @constant( 'O2SWITCH_VARNISH_PURGE_KEY' ) ) );
				else
					_CacheExt_SockDoRequest( $_SERVER[ 'SERVER_ADDR' ], 'PURGE', $urlPurge, array( 'X-Purge-Method' => 'default', 'X-VC-Purge-Key' => @constant( 'O2SWITCH_VARNISH_PURGE_KEY' ) ) );
			}
		}
		else
			_CacheExt_SockDoRequest( $_SERVER[ 'SERVER_ADDR' ], 'PURGE', Wp::GetSiteRootUrl(), array( 'X-Purge-Regex' => '.*', 'X-VC-Purge-Key' => @constant( 'O2SWITCH_VARNISH_PURGE_KEY' ) ) );
	}

	if( $dir = trim( Gen::GetArrField( $sett, array( 'cache', 'nginx', 'fastCgiDir' ), '' ) ) )
	{
		if( $url )
		{
			foreach( _CacheExt_Nginx_GetCacheFiles( _CacheExt_Nginx_GetUrlKey( $url ), $dir, Gen::GetArrField( $sett, array( 'cache', 'nginx', 'fastCgiLevels' ), '' ) ) as $cache )
			{

				if( @is_file( $cache ) )
					@unlink( $cache );
			}
		}
		else
			_CacheExt_Nginx_ClearAll( $dir );
	}

	else if( Gen::DoesFuncExist( '\\NginxChampuru::get_instance' ) && Gen::DoesFuncExist( '\\NginxChampuru::get_cache_dir' ) && Gen::DoesFuncExist( '\\NginxChampuru::get_cache_key' ) && Gen::DoesFuncExist( '\\NginxChampuru::get_cache' ) )
	{
		if( $instance = \NginxChampuru::get_instance() )
		{
			if( $url )
			{
				add_filter( 'nginxchampuru_get_reverse_proxy_key', 'seraph_accel\\_CacheExt_Nginx_GetUrlKey', 99999 );

				foreach( ( array )$instance -> get_cache( $instance -> get_cache_key( $url ), $url ) as $cache )
				{

					if( @is_file( $cache ) )
						@unlink( $cache );
				}
			}
			else if( $dir = $instance -> get_cache_dir() )
				_CacheExt_Nginx_ClearAll( $dir );
		}
	}

	else if( Gen::DoesFuncExist( '\\NginxCache::purge_zone_once' ) )
	{

		if( $dir = trim( get_option( 'nginx_cache_path' ) ) )
		{
			if( $url )
			{
				foreach( _CacheExt_Nginx_GetCacheFiles( _CacheExt_Nginx_GetUrlKey( $url ), $dir ) as $cache )
				{

					if( @is_file( $cache ) )
						@unlink( $cache );
				}
			}
			else
				_CacheExt_Nginx_ClearAll( $dir );
		}
	}

	else if( Gen::DoesFuncExist( '\\Purger::purge_all' ) && Gen::DoesFuncExist( '\\Purger::purge_url' ) )
	{
		global $nginx_purger;
		if( $nginx_purger )
		{
			if( $url )
				$nginx_purger -> purge_url( $url );
			else
				$nginx_purger -> purge_all();
		}
	}

	if( Gen::DoesFuncExist( '\\CF\\WordPress\\Hooks::purgeCacheEverything' ) )
	{
		if( $url )
			( new CloudFlareHooksEx() ) -> purgeUrl( $url );
		else
			( new CloudFlareHooksEx() ) -> purgeCacheEverything();
	}

	if( Gen::DoesFuncExist( '\\WPNCEasyWP\\Http\\Varnish\\VarnishCache::boot' ) )
	{
		if( $varnish = \WPNCEasyWP\Http\Varnish\VarnishCache::boot() )
		{

			    $varnish -> clearAll();
		}

		wp_cache_flush();
	}

	if( ( isset( $_SERVER[ 'cw_allowed_ip' ] ) || @preg_match( '@/home/.*?cloudways.*@', __DIR__ ) ) )
	{
		if( !$url )
			$url = Wp::GetSiteRootUrl( '.*' );

		$urlComps = Net::UrlParse( $url );
		if( $urlComps )
			_CacheExt_SockDoRequest( '127.0.0.1', 'PURGE', $url, array( 'Host' => $urlComps[ 'host' ], 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36' ), 8080 );
	}

	if( ( @preg_match( '@^dp-.+@', $hostname ) ) )
	{
		if( $url )
		{
            if( $urlComps = Net::UrlParse( $url ) )
			{
				$urlComps[ 'scheme' ] = 'https';
				$urlPurge = Net::UrlDeParse( $urlComps, 0, array( PHP_URL_QUERY, PHP_URL_FRAGMENT ) );
				if( isset( $urlComps[ 'query' ] ) )
					_CacheExt_SockDoRequest( $_SERVER[ 'SERVER_ADDR' ], 'PURGE', $urlPurge . '.*', array( 'x-purge-method' => 'regex' ) );
				else
					_CacheExt_SockDoRequest( $_SERVER[ 'SERVER_ADDR' ], 'PURGE', $urlPurge, array( 'x-purge-method' => 'default' ) );
			}
		}
		else
			_CacheExt_SockDoRequest( $_SERVER[ 'SERVER_ADDR' ], 'PURGE', Wp::GetSiteRootUrl( '.*' ), array( 'x-purge-method' => 'regex' ) );
	}

	if( Gen::DoesFuncExist( '\\WPaaS\\Plugin::vip' ) )
	{
		$urlPurge = $url;
		if( !$urlPurge )
			$urlPurge = Wp::GetSiteRootUrl();
		$urlPurge = preg_replace( '@^https://@', 'http://', $urlPurge );

		update_option( 'gd_system_last_cache_flush', time() );
		_CacheExt_SockDoRequest( \WPaaS\Plugin::vip(), 'BAN', $urlPurge );
	}

	if( ( defined( 'KINSTAMU_VERSION' ) ) )
	{
		if( $url )
		{
            if( $urlComps = Net::UrlParse( $url ) )
				wp_remote_get( Net::UrlDeParse( $urlComps, 0, array( PHP_URL_PATH, PHP_URL_QUERY, PHP_URL_FRAGMENT ) ) . '/kinsta-clear-cache' . ( isset( $urlComps[ 'path' ] ) ? $urlComps[ 'path' ] : '/' ), array( 'timeout' => 5, 'sslverify' => false ) );

		}
		else
			wp_remote_get( 'https://localhost/kinsta-clear-cache-all', array( 'timeout' => 5, 'sslverify' => false ) );
	}

	if( Gen::DoesFuncExist( '\\PagelyCachePurge::purgePath' ) )
	{
		if( $url )
		{
            if( $urlComps = Net::UrlParse( $url ) )
				if( isset( $urlComps[ 'path' ] ) )
					( new \PagelyCachePurge() ) -> purgePath( $urlComps[ 'path' ] . '(.*)' );
		}
		else
			( new \PagelyCachePurge() ) -> purgeAll();
	}

	if( ( isset( $_SERVER[ 'PRESSABLE_PROXIED_REQUEST' ] ) || strpos( $hostname, 'atomicsites.net' ) !== false ) )
	{
		if( $url )
		{
			global $batcache;

			if( $batcache )
			{
				$urlComps = Net::UrlParse( $url, Net::URLPARSE_F_QUERY );
				if( $urlComps && isset( $urlComps[ 'host' ] ) )
				{
					if( isset( $batcache -> ignored_query_args ) )
						foreach( $batcache -> ignored_query_args as $arg )
							unset( $urlComps[ 'query' ][ $arg ] );
					ksort( $urlComps[ 'query' ] );

					$keys = array(
						'host' => (isset($urlComps[ 'host' ])?$urlComps[ 'host' ]:''),
						'method' => 'GET',
						'path' => (isset($urlComps[ 'path' ])?$urlComps[ 'path' ]:''),
						'query' => (isset($urlComps[ 'query' ])?$urlComps[ 'query' ]:''),
						'extra' => array()
					);

					if( isset( $batcache -> origin ) )
						$keys[ 'origin' ] = $batcache -> origin;

					if( (isset($urlComps[ 'scheme' ])?$urlComps[ 'scheme' ]:'') == 'https' )
						$keys[ 'ssl' ] = true;

					wp_cache_init();
					$batcache -> configure_groups();

					foreach( array( 'mobile', 'tablet', 'desktop' ) as $deviceType )
					{
						$keys[ 'extra' ] = array( $deviceType );
						wp_cache_delete( md5( serialize( $keys ) ), $batcache -> group );
					}
				}
			}
		}
		else
			wp_cache_flush();
	}

	if( Gen::DoesFuncExist( '\\CDN_Clear_Cache_Api::cache_api_call' ) )
	{
		if( $url )
		{
            if( $urlComps = Net::UrlParse( $url ) )
				\CDN_Clear_Cache_Api::cache_api_call( array( Net::UrlDeParse( $urlComps, 0, array(), array( PHP_URL_PATH, PHP_URL_QUERY ) ) ), 'purge' );
		}
		else if( Gen::DoesFuncExist( 'CDN_Clear_Cache_Hooks::purge_cache' ) )
			\CDN_Clear_Cache_Hooks::purge_cache();
	}

	if( ( strpos( (isset($_SERVER[ 'WARPDRIVE_API' ])?$_SERVER[ 'WARPDRIVE_API' ]:''), '//api.savvii.services' ) !== false ) )
	{
		if( $url )
		{
            if( $urlComps = Net::UrlParse( $url ) )
				wp_remote_request( Net::UrlDeParse( $urlComps ) . 'purge', array( 'method' => 'PURGE', 'sslverify' => false, 'headers' => array( 'X-PURGE-HOST' => (isset($urlComps[ 'host' ])?$urlComps[ 'host' ]:null), 'X-PURGE-PATH-REGEX' => (isset($urlComps[ 'path' ])?$urlComps[ 'path' ]:'') . '.*' ) ) );
		}
		else
		{
            if( $urlComps = Net::UrlParse( Wp::GetSiteRootUrl() ) )
				wp_remote_request( Net::UrlDeParse( $urlComps ) . 'purge', array( 'method' => 'PURGE', 'sslverify' => false, 'headers' => array( 'X-PURGE-HOST' => (isset($urlComps[ 'host' ])?$urlComps[ 'host' ]:null) ) ) );
		}
	}

	if( ( defined( 'SiteGround_Optimizer\\VERSION' ) || strpos( $hostname, 'siteground.eu' ) !== false ) )
	{
		if( function_exists( 'sg_cachepress_purge_cache' ) )
		{
			if( Gen::DoesFuncExist( '\\SiteGround_Optimizer\\Supercacher\\Supercacher::get_instance' ) && Gen::DoesFuncExist( '\\SiteGround_Optimizer\\Supercacher\\Supercacher::purge_cache_request' ) && Gen::DoesFuncExist( '\\SiteGround_Optimizer\\Supercacher\\Supercacher::purge_cache' ) )
			{
				if( $url )
					\SiteGround_Optimizer\Supercacher\Supercacher::get_instance() -> purge_cache_request( $url, false );
				else
					\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
			}

			if( $url )
				sg_cachepress_purge_cache( $url );
			else if( function_exists( 'sg_cachepress_purge_everything' ) )
				sg_cachepress_purge_everything();

			wp_cache_flush();

			if( Gen::DoesFuncExist( '\\SiteGround_Optimizer\\Supercacher\\Supercacher::delete_assets' ) )
				\SiteGround_Optimizer\Supercacher\Supercacher::delete_assets();
		}
		else if( $url )
		{
            if( $urlComps = Net::UrlParse( $url ) )
			{
				$urlComps[ 'scheme' ] = 'http';
				$urlPurge = Net::UrlDeParse( $urlComps, 0, array( PHP_URL_QUERY, PHP_URL_FRAGMENT ) );
				if( isset( $urlComps[ 'query' ] ) )
					$urlPurge .= '(.*)';
				_CacheExt_SockDoRequest( '127.0.0.1', 'PURGE', $urlPurge );
			}
		}
		else
			_CacheExt_SockDoRequest( '127.0.0.1', 'PURGE', Wp::GetSiteRootUrl( '/(.*)' ) );
	}

	if( ( isset( $_SERVER[ 'HTTP_X_ZXCS_VHOST' ] ) && ( strpos( $hostname, 'zxcs' ) !== false ) ) )
	{
		$urlPurge = $url;
		if( !$urlPurge )
			$urlPurge = Wp::GetSiteRootUrl() . '?purgeAll';
		wp_remote_request( $urlPurge, array( 'method' => 'PURGE', 'sslverify' => false, 'headers' => array( 'X-Purge-ZXCS' => 'true', 'host-ZXCS' => (isset($_SERVER[ 'HTTP_HOST' ])?$_SERVER[ 'HTTP_HOST' ]:'') ) ) );
	}

	if( Gen::DoesFuncExist( '\\WpeCommon::purge_varnish_cache' ) )
	{
		try
		{
			if( $url )
			{
				$ctx = new AnyObj();
				$ctx -> urlComps = Net::UrlParse( $url );

				if( $ctx -> urlComps )
				{
					$ctx -> cb =
						function( $ctx, $paths )
						{
							if( count( $paths ) == 1 && $paths[ 0 ] == '.*' )
								$paths = array( Net::UrlDeParse( $ctx -> urlComps, 0, array(), array( PHP_URL_PATH, PHP_URL_QUERY ) ) );
							return( $paths );
						};

					add_filter( 'wpe_purge_varnish_cache_paths', array( $ctx, 'cb' ) );
					\WpeCommon::purge_varnish_cache();
					remove_filter( 'wpe_purge_varnish_cache_paths', array( $ctx, 'cb' ) );
				}
			}
			else
                \WpeCommon::purge_varnish_cache();
        }
		catch( \Exception $e )
		{
		}
	}

	global $wpaas_cache_class;
	if( ( Gen::DoesFuncExist( '\\WPaaS\\Cache_V2::ban' ) && $wpaas_cache_class ) )
	{
		if( $url )
		{
			$wpaas_cache_class -> purge( array( $url ) );
			$wpaas_cache_class -> flush_cdn();
		}
		else
		{
			$wpaas_cache_class -> ban();
			$wpaas_cache_class -> flush_transients();
			$wpaas_cache_class -> flush_ob();
		}
	}

	if( ( defined( 'WPCOMSH_VERSION' ) ) )
	{
		wp_cache_flush();
	}

	if( Gen::DoesFuncExist( '\\Tenweb_Manager\\Helper::clear_cache' ) )
		\Tenweb_Manager\Helper::clear_cache();
}

