<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header("Access-Control-Allow-Origin: *");

if (!function_exists('http_response_code')) {
	function http_response_code($code = NULL) {
		if ($code !== NULL) {
			switch ($code) {
			case 100: $text = 'Continue'; break;
			case 101: $text = 'Switching Protocols'; break;
			case 200: $text = 'OK'; break;
			case 201: $text = 'Created'; break;
			case 202: $text = 'Accepted'; break;
			case 203: $text = 'Non-Authoritative Information'; break;
			case 204: $text = 'No Content'; break;
			case 205: $text = 'Reset Content'; break;
			case 206: $text = 'Partial Content'; break;
			case 300: $text = 'Multiple Choices'; break;
			case 301: $text = 'Moved Permanently'; break;
			case 302: $text = 'Moved Temporarily'; break;
			case 303: $text = 'See Other'; break;
			case 304: $text = 'Not Modified'; break;
			case 305: $text = 'Use Proxy'; break;
			case 400: $text = 'Bad Request'; break;
			case 401: $text = 'Unauthorized'; break;
			case 402: $text = 'Payment Required'; break;
			case 403: $text = 'Forbidden'; break;
			case 404: $text = 'Not Found'; break;
			case 405: $text = 'Method Not Allowed'; break;
			case 406: $text = 'Not Acceptable'; break;
			case 407: $text = 'Proxy Authentication Required'; break;
			case 408: $text = 'Request Time-out'; break;
			case 409: $text = 'Conflict'; break;
			case 410: $text = 'Gone'; break;
			case 411: $text = 'Length Required'; break;
			case 412: $text = 'Precondition Failed'; break;
			case 413: $text = 'Request Entity Too Large'; break;
			case 414: $text = 'Request-URI Too Large'; break;
			case 415: $text = 'Unsupported Media Type'; break;
			case 500: $text = 'Internal Server Error'; break;
			case 501: $text = 'Not Implemented'; break;
			case 502: $text = 'Bad Gateway'; break;
			case 503: $text = 'Service Unavailable'; break;
			case 504: $text = 'Gateway Time-out'; break;
			case 505: $text = 'HTTP Version not supported'; break;
			default: exit('Unknown http status code "' . htmlentities($code) . '"'); break;
			}
			$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
			header($protocol . ' ' . $code . ' ' . $text);
			$GLOBALS['http_response_code'] = $code;
		} else {
			$code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);
		}
		return $code;
	}
}
function sizeFilter( $bytes )
{
	$label = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
	for( $i = 0; $bytes >= 1024 && $i < ( count( $label ) -1 ); $bytes /= 1024, $i++ );
	return( number_format( $bytes, 2, '.', '' ) . " " . $label[$i] );
}
function secondsToTime($seconds_time)
{
	if($seconds_time > 345600)
		return '>96:00:00';
	$hours = floor($seconds_time / 3600);
	$minutes = floor(($seconds_time - $hours * 3600) / 60);
	$seconds = floor($seconds_time - ($hours * 3600) - ($minutes * 60));
	return str_pad( $hours, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $minutes, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $seconds, 2, '0', STR_PAD_LEFT );
}

try{
	$lang = 0;
	if( isset( $_REQUEST['lang'] ) ) {
		$lang = intval($_REQUEST['lang']);
	}
	$redis = new Redis();
	$redis->pconnect('192.168.1.2', 8888);
	$count1 = $redis->dbsize();

	$m = new MongoClient();
	$collection = $m->selectDB('cdbqueue')->selectCollection('queuedb');
	$cursor = $collection->find();
	$count2 = $cursor->count();
	$cursor->reset();

	$collection = $m->selectDB('cdbsel')->selectCollection('seldb');
	$cursor = $collection->find();
	$count3 = $cursor->count();
	$cursor->reset();

	$egtb_count_wdl = 0;
	$egtb_size_wdl = 0;
	$egtb_count_dtz = 0;
	$egtb_size_dtz = 0;
	$memcache_obj = new Memcache();
	$memcache_obj->pconnect('localhost', 11211);
	if( !$memcache_obj )
		throw new Exception( 'Memcache error.' );
	$egtbstats = $memcache_obj->get( 'EGTBStats2' );
	if( $egtbstats !== FALSE ) {
		$egtb_count_wdl = $egtbstats[0];
		$egtb_size_wdl = $egtbstats[1];
		$egtb_count_dtz = $egtbstats[2];
		$egtb_size_dtz = $egtbstats[3];
	} else {
		$egtb_dirs = array( '/home/syzygy/3-6men/', '/home/syzygy/7men/4v3_pawnful', '/home/syzygy/7men/4v3_pawnless', '/home/syzygy/7men/5v2_pawnful', '/home/syzygy/7men/5v2_pawnless', '/home/syzygy/7men/6v1_pawnful', '/home/syzygy/7men/6v1_pawnless' );
		foreach( $egtb_dirs as $dir ) {
			$dir_iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach( $dir_iter as $filename => $file ) {
				if( strpos($filename, '.rtbw') !== FALSE )
				{
					$egtb_count_wdl++;
					$egtb_size_wdl += $file->getSize();
				}
				else if( strpos($filename, '.rtbz') !== FALSE )
				{
					$egtb_count_dtz++;
					$egtb_size_dtz += $file->getSize();
				}
			}
		}
		$memcache_obj->set( 'EGTBStats2', array( $egtb_count_wdl, $egtb_size_wdl, $egtb_count_dtz, $egtb_size_dtz ), 0, 86400 );
	}
	$nps = 0;
	$est = 0;
	$memcache_obj = new Memcache();
	$memcache_obj->pconnect('localhost', 11211);
	if( !$memcache_obj )
		throw new Exception( 'Memcache error.' );

	$activelist = $memcache_obj->get('WorkerList2');
	if($activelist !== FALSE) {
		$lastminute = date('i', time() - 60);
		foreach($activelist as $key => $value) {
			$npn = $memcache_obj->get('Worker2::' . $key . 'NC_' . $lastminute);
			if( $npn !== FALSE ) {
				$nps += $npn;
			}
		}
		$nps /= 60 * 1000 * 1000;
		$queue = $memcache_obj->get('QueueCount2::' . $lastminute);
		$sel = $memcache_obj->get('SelCount2::' . $lastminute);
		$est = ( $count2 + $count3 ) / ( $queue + $sel + 1 ) + $count3 / ( $queue + 1 );
	}
	echo '<table class="stats">';
	if($lang == 0) {
		echo '<tr><td>局面数量（近似）：</td><td style="text-align: right;">' . number_format( $count1 ) . '</td></tr>';
		echo '<tr><td>学习队列（评估 / 筛选）：</td><td style="text-align: right;">' . number_format( $count2 ) . ' / ' . number_format( $count3 ) . '</td></tr>';
		echo '<tr><td>后台计算（剩时 / 速度）：</td><td style="text-align: right;">' . secondsToTime( $est * 60 ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
		echo '<tr><td>残局库数量（ WDL / DTZ ）：</td><td style="text-align: right;">' . number_format( $egtb_count_wdl ) . ' / ' . number_format( $egtb_count_dtz ) . '</td></tr>';
		echo '<tr><td>残局库体积（ WDL / DTZ ）：</td><td style="text-align: right;">' . sizeFilter( $egtb_size_wdl ) . ' / ' . sizeFilter( $egtb_size_dtz ) . '</td></tr>';
	} else {
		echo '<tr><td>Position Count ( Approx. ) :</td><td style="text-align: right;">' . number_format( $count1 ) . '</td></tr>';
		echo '<tr><td>Queue ( Scoring / Sieving ) :</td><td style="text-align: right;">' . number_format( $count2 ) . ' / ' . number_format( $count3 ) . '</td></tr>';
		echo '<tr><td>Backend ( Duration / Speed ) :</td><td style="text-align: right;">' . secondsToTime( $est * 60 ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
		echo '<tr><td>EGTB Count ( WDL / DTZ ) :</td><td style="text-align: right;">' . number_format( $egtb_count_wdl ) . ' / ' . number_format( $egtb_count_dtz ) . '</td></tr>';
		echo '<tr><td>EGTB File Size ( WDL / DTZ ) :</td><td style="text-align: right;">' . sizeFilter( $egtb_size_wdl ) . ' / ' . sizeFilter( $egtb_size_dtz ) . '</td></tr>';
	}
	echo '</table>';
}
catch (MongoException $e) {
	echo 'Error: ' . $e->getMessage();
	http_response_code(503);
}
catch (RedisException $e) {
	echo 'Error: ' . $e->getMessage();
	http_response_code(503);
}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
	http_response_code(503);
}

