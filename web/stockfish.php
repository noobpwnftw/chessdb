<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header('X-Accel-Buffering: no');
header('Content-Type: text/event-stream; charset=utf-8');

function getEngineMove( $row, $frc, $movelist, $maxtime ) {
	$result = array();
	$sock = fsockopen('unix:///run/ceval/ceval.sock');
	if( $sock ) {
		stream_set_blocking( $sock, FALSE );
		fwrite( $sock, 'position fen ' . $row);
		if( count( $movelist ) > 0 ) {
			fwrite( $sock, ' moves ' . implode(' ', $movelist ) );
		}
		fwrite( $sock, PHP_EOL . 'go depth 22' . PHP_EOL );
		$startTime = time();
		$readfd = array( $sock );
		$writefd = NULL;
		$errfd = NULL;
		while( true ) {
			$res = @stream_select( $readfd, $writefd, $errfd, 1 );
			if( $res > 0 && ( $out = fgets( $sock ) ) ) {
				$result[] = $out;
				echo "data: {$out}\n\n";
				@ob_flush(); @flush();
				if( $move = strstr( $out, 'bestmove' ) ) {
					fwrite( $sock, 'DONE' . PHP_EOL );
					break;
				}
			}
			else if( $res > 0 )
				break;
			if( time() - $startTime >= $maxtime ) {
				$startTime++;
				if( fwrite( $sock, 'stop' . PHP_EOL ) === FALSE )
					break;
			}
			$readfd = array( $sock );
		}
		fclose( $sock );
	}
	return $result;
}

try{
	$memcache_obj = new Memcache();
	if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
		throw new Exception( 'Memcache error.' );
	$ratekey = 'QLimit2::' . $_SERVER['REMOTE_ADDR'];
	$memcache_obj->add( $ratekey, 0, 0, 1 );
	$querylimit = $memcache_obj->increment( $ratekey );
	if( $querylimit === FALSE || $querylimit <= 5 )
	{
		if( isset( $_REQUEST['board'] ) && !empty( $_REQUEST['board'] ) ) {
			list( $row, $frc ) = cbgetfen( $_REQUEST['board'] );

			if( isset( $row ) && !empty( $row ) ) {
				$movelist = array();
				$isvalid = true;
				$lastfen = $row;
				if( isset( $_REQUEST['movelist'] ) && !empty( $_REQUEST['movelist'] ) ) {
					$movelist = explode( "|", $_REQUEST['movelist'] );
					$nextfen = $row;
					$nextfrc = $frc;
					$movecount = count( $movelist );
					if( $movecount > 0 && $movecount < 2047 ) {
						foreach( $movelist as $entry ) {
							$validmoves = cbmovegen( $nextfen, $nextfrc );
							if( isset( $validmoves[$entry] ) )
								list( $nextfen, $nextfrc ) = cbmovemake( $nextfen, $entry, $nextfrc );
							else {
								$isvalid = false;
								break;
							}
							$lastfen = $nextfen;
						}
					}
					else
						$isvalid = false;
				}
				if( $isvalid ) {
					$cachekey = 'Stockfish::' . $lastfen . hash( 'md5', $row . implode( $movelist ) );
					$cached = $memcache_obj->get( $cachekey );
					if( $cached !== FALSE ) {
						if( is_array( $cached ) ) {
							foreach ($cached as $c) {
								echo "data: " . $c . "\n\n";
								@ob_flush(); @flush();
								usleep(20 * 1000);
							}
						}
						else
							echo "data: " . $cached . "\n\n";
					}
					else {
						$memcache_obj->add( 'EngineCount2', 0 );
						$engcount = $memcache_obj->increment( 'EngineCount2' );
						$result = getEngineMove( $row, $frc, $movelist, 30 - min( 25, $engcount / 2 ) );
						$memcache_obj->decrement( 'EngineCount2' );
						if( count( $result ) ) {
							$memcache_obj->add( $cachekey, $result, 0, 300 );
						}
					}
				}
			}
		}
		echo "data: [DONE]\n\n";
	} else {
		echo "retry: 1000\n\n";
	}
}
catch (Exception $e) {
	error_log( get_class($e) . ': ' . $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
