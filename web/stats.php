<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header("Access-Control-Allow-Origin: *");
header('Content-Type: text/html; charset=utf-8');

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
	$days = floor($seconds_time / 86400);
	if( $days > 99 )
		return 'INF';
	$hours = floor(($seconds_time - $days * 86400) / 3600);
	$minutes = floor(($seconds_time - ($days * 86400) - ($hours * 3600)) / 60);
	$seconds = floor($seconds_time - ($days * 86400) - ($hours * 3600) - ($minutes * 60));
	return str_pad( $days, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $hours, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $minutes, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $seconds, 2, '0', STR_PAD_LEFT );
}

try{
	$lang = 0;
	if( isset( $_REQUEST['lang'] ) ) {
		$lang = intval($_REQUEST['lang']);
	}
	$redis = new Redis();
	$redis->pconnect('192.168.1.2', 8889, 1.0);
	$count1 = $redis->dbsize();

	$m = new MongoClient();
	$collection = $m->selectDB('ccdbqueue')->selectCollection('queuedb');
	$cursor = $collection->find( array( 'p' => 1 ) );
	$count2 = $cursor->count();
	$cursor->reset();

	$collection = $m->selectDB('ccdbsel')->selectCollection('seldb');
	$cursor = $collection->find( array( 'p' => 1 ) );
	$count3 = $cursor->count();
	$cursor->reset();

	$egtb_count_dtc = 0;
	$egtb_size_dtc = 0;
	$egtb_count_dtm = 0;
	$egtb_size_dtm = 0;
	$memcache_obj = new Memcache();
	$memcache_obj->pconnect('localhost', 11211);
	if( !$memcache_obj )
		throw new Exception( 'Memcache error.' );
	$egtbstats = $memcache_obj->get( 'EGTBStats' );
	if( $egtbstats !== FALSE ) {
		$egtb_count_dtc = $egtbstats[0];
		$egtb_size_dtc = $egtbstats[1];
		$egtb_count_dtm = $egtbstats[2];
		$egtb_size_dtm = $egtbstats[3];
	} else {
		$egtb_dirs_dtc = array( '/home/apache/EGTB_DTC/' );
		foreach( $egtb_dirs_dtc as $dir ) {
			$dir_iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach( $dir_iter as $file ) {
				$egtb_count_dtc++;
				$egtb_size_dtc += $file->getSize();
			}
		}

		$egtb_dirs_dtm = array( '/home/apache/EGTB_DTM/' );
		foreach( $egtb_dirs_dtm as $dir ) {
			$dir_iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach( $dir_iter as $file ) {
				$egtb_count_dtm++;
				$egtb_size_dtm += $file->getSize();
			}
		}
		$memcache_obj->set( 'EGTBStats', array( $egtb_count_dtc, $egtb_size_dtc, $egtb_count_dtm, $egtb_size_dtm ), 0, 86400 );
	}
	$nps = 0;
	$est = 0;
	$activelist = $memcache_obj->get('WorkerList');
	if($activelist !== FALSE) {
		$lastminute = date('i', time() - 60);
		foreach($activelist as $key => $value) {
			$npn = $memcache_obj->get('Worker::' . $key . 'NC_' . $lastminute);
			if( $npn !== FALSE ) {
				$nps += $npn;
			}
		}
		$nps /= 60 * 1000 * 1000;
		$queue = $memcache_obj->get('QueueCount::' . $lastminute);
		$sel = $memcache_obj->get('SelCount::' . $lastminute);
		$est = max( ( $count2 + $count3 ) / ( $queue + 1 ), $count3 / ( $sel + 1 ) );
	}
	echo '<table class="stats">';
	if($lang == 0) {
		echo '<tr><td>局面数量（近似）：</td><td style="text-align: right;">' . number_format( $count1 ) . '</td></tr>';
		echo '<tr><td>学习队列（评估 / 筛选）：</td><td style="text-align: right;">' . number_format( $count2 ) . ' / ' . number_format( $count3 ) . '</td></tr>';
		echo '<tr><td>后台计算（剩时 / 速度）：</td><td style="text-align: right;">' . secondsToTime( $est * 60 ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
		echo '<tr><td>残局库数量（ DTC / DTM ）：</td><td style="text-align: right;">' . number_format( $egtb_count_dtc ) . ' / ' . number_format( $egtb_count_dtm ) . '</td></tr>';
		echo '<tr><td>残局库体积（ DTC / DTM ）：</td><td style="text-align: right;">' . sizeFilter( $egtb_size_dtc ) . ' / ' . sizeFilter( $egtb_size_dtm ) . '</td></tr>';
	} else {
		echo '<tr><td>Position Count ( Approx. ) :</td><td style="text-align: right;">' . number_format( $count1 ) . '</td></tr>';
		echo '<tr><td>Queue ( Scoring / Sieving ) :</td><td style="text-align: right;">' . number_format( $count2 ) . ' / ' . number_format( $count3 ) . '</td></tr>';
		echo '<tr><td>Worker ( Backlog / Speed ) :</td><td style="text-align: right;">' . secondsToTime( $est * 60 ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
		echo '<tr><td>EGTB Count ( DTC / DTM ) :</td><td style="text-align: right;">' . number_format( $egtb_count_dtc ) . ' / ' . number_format( $egtb_count_dtm ) . '</td></tr>';
		echo '<tr><td>EGTB File Size ( DTC / DTM ) :</td><td style="text-align: right;">' . sizeFilter( $egtb_size_dtc ) . ' / ' . sizeFilter( $egtb_size_dtm ) . '</td></tr>';
	}
	echo '</table>';
}
catch (MongoException $e) {
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
catch (RedisException $e) {
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
catch (Exception $e) {
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}

