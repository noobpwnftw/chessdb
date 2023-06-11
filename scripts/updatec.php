<?php
header("Cache-Control: no-cache");
header("Pragma: no-cache");
ini_set("memory_limit", "-1");

function count_pieces( $fen ) {
	@list( $board, $color ) = explode( " ", $fen );
	$pieces = 'kqrbnp';
	return strlen( $board ) - strlen( str_ireplace( str_split( $pieces ), '', $board ) );
}
function getthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore - 1;
	}
	else if( $maxscore >= -30 ) {
		$throttle = (int)( $maxscore - 10 / ( 1 + exp( -abs( $maxscore ) / 10 ) ) );
	}
	else {
		$throttle = -50;
	}
	return $throttle;
}
function getHexFenStorage( $hexfenarr ) {
	asort( $hexfenarr );
	$minhexfen = reset( $hexfenarr );
	return array( $minhexfen, key( $hexfenarr ) );
}
function getAllScores( $redis, $row ) {
	$moves = array();
	$finals = array();
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	$doc = $redis->hGetAll( hex2bin( $minhexfen ) );
	if( $doc === FALSE )
		throw new RedisException( 'Server operation error.' );
	if( $minindex == 0 ) {
		foreach( $doc as $key => $item ) {
			if( $key == 'a0a0' )
				$moves['ply'] = $item;
			else {
				if( abs( $item ) >= 30000 ) {
					if ( $item == -30001 ) {
						$item = 0;
					}
					$finals[$key] = 1;
				}
				$moves[$key] = -$item;
			}
		}
	}
	else {
		foreach( $doc as $key => $item ) {
			if( $key == 'a0a0' )
				$moves['ply'] = $item;
			else {
				if( abs( $item ) >= 30000 ) {
					if ( $item == -30001 ) {
						$item = 0;
					}
					$finals[cbgetBWmove( $key )] = 1;
				}
				$moves[cbgetBWmove( $key )] = -$item;
			}
		}
	}
	return array( $moves, $finals );
}
function updatePly( $redis, $row, $ply ) {
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	$redis->hSet( hex2bin($minhexfen), 'a0a0', $ply );
	$GLOBALS['counter_ply']++;
}
function updateScore( $redis, $row, $updatemoves ) {
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	if( $minindex == 0 ) {
		if( $redis->hMSet( hex2bin($minhexfen), $updatemoves ) === FALSE )
			throw new RedisException( 'Server operation error.' );
	}
	else if( $minindex == 1 ) {
		$newmoves = array();
		foreach( $updatemoves as $key => $newscore ) {
			$newmoves[cbgetBWmove( $key )] = $newscore;
		}
		if( $redis->hMSet( hex2bin($minhexfen), $newmoves ) === FALSE )
			throw new RedisException( 'Server operation error.' );
	}
	$GLOBALS['counter_update']++;
}
function delScore( $redis, $row, $updatemoves ) {
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	if( $minindex == 0 ) {
		if( $redis->hDel( hex2bin($minhexfen), $updatemoves ) === FALSE )
			throw new RedisException( 'Server operation error.' );
	}
	else if( $minindex == 1 ) {
		foreach( $updatemoves as $key => $newscore ) {
			if( $redis->hDel( hex2bin($minhexfen), cbgetBWmove( $key ) ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
	}
	$GLOBALS['counter_update']++;
}
function shuffle_assoc(&$array) {
	$keys = array_keys($array);
	shuffle($keys);
	$new = array();
	foreach($keys as $key) {
		$new[$key] = $array[$key];
	}
	$array = $new;
	return true;
}

function getMoves( $redis, $row, $depth ) {
	list( $moves1, $finals ) = getAllScores( $redis, $row );
	$BWfen = cbgetBWfen( $row );
	$current_hash = abs( xxhash64( $row ) );
	$current_hash_bw = abs( xxhash64( $BWfen ) );

	$recurse = false;
	if( isset($moves1['ply']) )
	{
		if( $moves1['ply'] < 0 || $moves1['ply'] > $depth )
			updatePly( $redis, $row, $depth );
	}
	else if( count( $moves1 ) > 0 )
		updatePly( $redis, $row, $depth );
	else
		return $moves1;

	if( !isset($moves1['ply']) || $moves1['ply'] < 0 || $moves1['ply'] >= $depth )
	{
		if( !isset( $GLOBALS['boardtt'][$current_hash] ) )
		{
			if( !isset( $GLOBALS['boardtt'][$current_hash_bw] ) )
			{
				$recurse = true;
			}
		}
	}
	else
	{
		if( isset( $GLOBALS['historytt'][$current_hash] ) || isset( $GLOBALS['historytt'][$current_hash_bw] ) )
		{
			$recurse = true;
		}
	}
	unset( $moves1['ply'] );

	if( $recurse && $depth < 30000 )
	{
/*
		$errors = array_diff_key( $moves1, cbmovegen( $row ) );
		if( count( $errors ) ) {
			echo $row . "\n";
			delScore( $redis, $row, $errors );
			$moves1 = array_intersect_key( $moves1, cbmovegen( $row ) );
		}
*/
		$updatemoves = array();
		$isloop = true;
		if( !isset( $GLOBALS['historytt'][$current_hash] ) )
		{
			if( !isset( $GLOBALS['historytt'][$current_hash_bw] ) )
			{
				$isloop = false;
			}
			else
			{
				$loop_hash_start = $current_hash_bw;
				$loop_fen_start = $BWfen;
			}
		}
		else
		{
			$loop_hash_start = $current_hash;
			$loop_fen_start = $row;
		}

		if( !$isloop )
		{
			//if( $depth < 20 )
			{
				shuffle_assoc( $moves1 );
				foreach( $moves1 as $key => $item ) {
					if( $depth == 0 )
						$GLOBALS['curmove'] = $key;
					if( isset( $finals[ $key ] ) )
						continue;
					$nextfen = cbmovemake( $row, $key );
					if( count_pieces( $nextfen ) <= 7 ) {
						if( abs( $item ) < 10000 ) {
							$egtbresult = json_decode( file_get_contents( 'http://localhost:9000/standard?fen=' . urlencode( $nextfen ) ), TRUE );
							if( $egtbresult !== NULL ) {
								if( $egtbresult['checkmate'] ) {
								}
								else if( $egtbresult['stalemate'] ) {
								}
								else if( $egtbresult['category'] == 'unknown' ) {
								}
								else
								{
									$bestmove = reset( $egtbresult['moves'] );
									if( $bestmove['category'] == 'draw' && $bestmove['category'] == 'draw' ) {
										$moves1[ $key ] = 0;
										$updatemoves[ $key ] = -30001;
									}
									else {
										if( $bestmove['category'] == 'blessed-loss' || $bestmove['category'] == 'maybe-loss' || $bestmove['category'] == 'loss' ) {
											$step = -$bestmove['dtz'];
											if( $bestmove['category'] == 'blessed-loss' || $bestmove['category'] == 'maybe-loss' )
												$nextscore = 20000 - $step;
											else
												$nextscore = 25000 - $step;
										}
										else {
											$step = $bestmove['dtz'];
											if( $bestmove['category'] == 'maybe-win' || $bestmove['category'] == 'cursed-win' )
												$nextscore = $step - 20000;
											else
												$nextscore = $step - 25000;
										}
										$moves1[ $key ] = -$nextscore;
										$updatemoves[ $key ] = $nextscore;
									}
								}
								continue;
							}
						}
						else
							continue;
					}
					$GLOBALS['historytt'][$current_hash]['fen'] = $nextfen;
					$GLOBALS['historytt'][$current_hash]['move'] = $key;
					$nextmoves = getMoves( $redis, $nextfen, $depth + 1 );
					unset( $GLOBALS['historytt'][$current_hash] );
					if( isset( $GLOBALS['loopcheck'] ) ) {
						$GLOBALS['looptt'][$current_hash][$key] = $GLOBALS['loopcheck'];
						unset( $GLOBALS['loopcheck'] );
					}
					if( count( $nextmoves ) > 0 ) {
						arsort( $nextmoves );
						$nextscore = reset( $nextmoves );
						$throttle = getthrottle( $nextscore );
						$nextsum = 0;
						$nextcount = 0;
						$totalvalue = 0;
						foreach( $nextmoves as $record => $score ) {
							if( $score >= $throttle ) {
								$nextcount++;
								$nextsum = $nextsum + $score;
								$totalvalue = $totalvalue + $nextsum;
							}
							else
								break;
						}
						if( abs( $nextscore ) < 10000 ) {
							if( $nextcount > 1 )
								$nextscore = ( int )( ( $nextscore * 3 + $totalvalue / ( ( $nextcount + 1 ) * $nextcount / 2 ) * 2 ) / 5 );
							else if( $nextcount == 1 ) {
								if( count( $nextmoves ) > 1 ) {
									if( $nextscore >= -50 )
										$nextscore = ( int )( ( $nextscore * 2 + $throttle ) / 3 );
								}
								else if( abs( $nextscore ) > 20 && abs( $nextscore ) < 75 ) {
									$nextscore = ( int )( $nextscore * 9 / 10 );
								}
							}
						}
						if( $item != -$nextscore ) {
							$moves1[ $key ] = -$nextscore;
							$updatemoves[ $key ] = $nextscore;
						}
					}
				}
			}
		}
		else
		{
			$loop_hash = $loop_hash_start;
			$loopmoves = array();
			do
			{
				array_push( $loopmoves, $GLOBALS['historytt'][$loop_hash]['move'] );
				$loopfen = $GLOBALS['historytt'][$loop_hash]['fen'];
				$loop_hash = abs( xxhash64( $loopfen ) );
				if( !isset( $GLOBALS['historytt'][$loop_hash] ) )
					break;
			}
			while( $loop_hash != $current_hash && $loop_hash != $current_hash_bw );
			$loopstatus = 1;
			if( $loopstatus > 0 )
				$GLOBALS['looptt'][$loop_hash_start][$GLOBALS['historytt'][$loop_hash_start]['move']] = $loopstatus;
		}

		$loopinfo = array();
		if( isset( $GLOBALS['looptt'][$current_hash] ) )
		{
			foreach( $GLOBALS['looptt'][$current_hash] as $key => $entry ) {
				$loopinfo[$key] = $entry;
			}
		}
		if( isset( $GLOBALS['looptt'][$current_hash_bw] ) )
		{
			foreach( $GLOBALS['looptt'][$current_hash_bw] as $key => $entry ) {
				$loopinfo[cbgetBWmove( $key )] = $entry;
			}
		}
		if( count( $loopinfo ) > 0 ) {
			$loopdraws = array();
			foreach( $loopinfo as $key => $entry ) {
				if( $entry == 1 )
					$loopdraws[$key] = 1;
			}
			if( $isloop && count( $loopdraws ) > 0 ) {
				asort( $moves1 );
				$bestscore = end( $moves1 );
				foreach( array_keys( array_intersect_key( $moves1, $loopdraws ) ) as $key ) {
					if( $moves1[$key] == $bestscore ) {
						$moves1[$key] = 0;
					}
				}
			}

			if( !$isloop ) {
				unset( $GLOBALS['looptt'][$current_hash] );
				unset( $GLOBALS['looptt'][$current_hash_bw] );
			}
		} else if( !$isloop ) {
			$GLOBALS['counter']++;
			$GLOBALS['boardtt'][$current_hash] = 1;
			if( $GLOBALS['counter'] % 10000 == 0 ) {
				gc_collect_cycles();
				echo implode(' ', array( $GLOBALS['counter'], $GLOBALS['counter_dup'], $GLOBALS['counter_update'], $GLOBALS['counter_ply'], $GLOBALS['curmove'], $depth, intval( ( $GLOBALS['counter'] + $GLOBALS['counter_update'] - $GLOBALS['last_counter'] ) / ( time() - $GLOBALS['last_ts'] + 1 ) ), count( $GLOBALS['looptt'] ), $GLOBALS['boardtt']->memoryUsage() ) ) . "\n";
				$GLOBALS['last_counter'] = $GLOBALS['counter'] + $GLOBALS['counter_update'];
				$GLOBALS['last_ts'] = time();
			}
		}
		if( count( $updatemoves ) > 0 )
			updateScore( $redis, $row, $updatemoves );
	}
	else
		$GLOBALS['counter_dup']++;

	foreach( $moves1 as $key => $entry ) {
		if( abs( $moves1[$key] ) > 10000 ) {
			if( $moves1[$key] < 0 ) {
				$moves1[$key] = $moves1[$key] + 1;
			}
			else {
				$moves1[$key] = $moves1[$key] - 1;
			}
		}
	}
	return $moves1;
}

try{
	$redis = new Redis();
	$redis->pconnect('192.168.1.2', 8888);
	$GLOBALS['counter'] = 0;
	$GLOBALS['counter_dup'] = 0;
	$GLOBALS['counter_update'] = 0;
	$GLOBALS['counter_ply'] = 0;
	$GLOBALS['last_ts'] = time();
	$GLOBALS['last_counter'] = 0;
	$GLOBALS['looptt'] = array();
	$GLOBALS['boardtt'] = new Judy( Judy::BITSET );
	getMoves( $redis, 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -', 0 );
	echo 'ok' . "\n";

}
catch (RedisException $e) {
	echo 'Error: ' . $e->getMessage();
}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
}
