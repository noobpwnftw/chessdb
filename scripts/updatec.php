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
		$throttle = $maxscore;
	}
	else if( $maxscore >= -30 ) {
		$throttle = (int)( $maxscore - 10 / ( 1 + exp( -abs( $maxscore ) / 10 ) ) );
	}
	else {
		$throttle = -50;
	}
	return $throttle;
}
function getBinFenStorage( $hexfenarr ) {
	asort( $hexfenarr );
	$minhexfen = reset( $hexfenarr );
	return array( hex2bin( $minhexfen ), key( $hexfenarr ) );
}
function getAllScores( $redis, $minbinfen, $minindex ) {
	$moves = array();
	$finals = array();
	$doc = $redis->hGetAll( $minbinfen );
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
function updatePly( $redis, $minbinfen, $ply ) {
	if( $redis->hSet( $minbinfen, 'a0a0', $ply ) === FALSE )
		throw new RedisException( 'Server operation error.' );
	$GLOBALS['counter_ply']++;
}
function updateScore( $redis, $minbinfen, $minindex, $updatemoves ) {
	if( $minindex == 0 ) {
		if( $redis->hMSet( $minbinfen, $updatemoves ) === FALSE )
			throw new RedisException( 'Server operation error.' );
	}
	else if( $minindex == 1 ) {
		$newmoves = array();
		foreach( $updatemoves as $key => $newscore ) {
			$newmoves[cbgetBWmove( $key )] = $newscore;
		}
		if( $redis->hMSet( $minbinfen, $newmoves ) === FALSE )
			throw new RedisException( 'Server operation error.' );
	}
	$GLOBALS['counter_update']++;
}
function delScore( $redis, $minbinfen, $minindex, $updatemoves ) {
	if( $minindex == 0 ) {
		if( $redis->hDel( $minbinfen, $updatemoves ) === FALSE )
			throw new RedisException( 'Server operation error.' );
	}
	else if( $minindex == 1 ) {
		foreach( $updatemoves as $key => $newscore ) {
			if( $redis->hDel( $minbinfen, cbgetBWmove( $key ) ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
	}
}
function updateExists( $redis, $minbinfen ) {
	if( $redis->set( $minbinfen, '' ) === FALSE )
		throw new RedisException( 'Server operation error.' );
}
function checkExists( $redis, $minbinfen ) {
	return $redis->get( $minbinfen ) !== FALSE;
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
function getMoves( $redis, $redis2, $row, $depth, $progress, $total ) {
	$BWfen = cbgetBWfen( $row );
	list( $minbinfen, $minindex ) = getBinFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	list( $moves1, $finals ) = getAllScores( $redis, $minbinfen, $minindex );

	$recurse = false;
	$dedup = $depth < 30 ? true : false;
	if( isset($moves1['ply']) )
	{
		if( $moves1['ply'] < 0 || $moves1['ply'] > $depth )
		{
			updatePly( $redis, $minbinfen, $depth );
			$dedup = false;
		}
	}
	else if( count( $moves1 ) > 0 )
		updatePly( $redis, $minbinfen, $depth );
	else
		return $moves1;

	if( !isset($moves1['ply']) || $moves1['ply'] < 0 || $moves1['ply'] >= $depth )
	{
		if( !isset( $GLOBALS['boardtt'][abs( xxhash64( $row ) )] ) )
		{
			if( !isset( $GLOBALS['boardtt'][abs( xxhash64( $BWfen ) )] ) )
			{
				$recurse = true;
			}
		}
	}
	else
	{
		if( isset( $GLOBALS['historytt'][$row] ) || isset( $GLOBALS['historytt'][$BWfen] ) )
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
			delScore( $redis, $minbinfen, $minindex, $errors );
			$moves1 = array_intersect_key( $moves1, cbmovegen( $row ) );
		}
*/
		$updatemoves = array();
		$isloop = true;
		if( !isset( $GLOBALS['historytt'][$row] ) )
		{
			if( !isset( $GLOBALS['historytt'][$BWfen] ) )
			{
				$isloop = false;
			}
			else
			{
				$loop_fen_start = $BWfen;
			}
		}
		else
		{
			$loop_fen_start = $row;
		}

		if( !$isloop )
		{
			if( $depth == 0 || !$dedup || !checkExists( $redis2, $minbinfen ) )
			{
				if( $depth < 12 ) {
					$progress = $progress * count( $moves1 );
					$total = ( $total + 1 ) * count( $moves1 );
				}
				//asort( $moves1 );
				shuffle_assoc( $moves1 );
				foreach( $moves1 as $key => $item ) {
					if( $depth == 0 ) {
/*
						$redis->close();
						$redis2->close();
						$pid = pcntl_fork();
						if( $pid > 0 ) {
							continue;
						} else {
							$redis->pconnect('192.168.1.2', 8888);
							$redis2->pconnect('192.168.1.2', 8890);
						}
						$total = 1;
*/
						$GLOBALS['curmove'] = $key;
					}
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
					$GLOBALS['historytt'][$row]['fen'] = $nextfen;
					$GLOBALS['historytt'][$row]['move'] = $key;
					$nextmoves = getMoves( $redis, $redis2, $nextfen, $depth + 1, $progress, $total );
					unset( $GLOBALS['historytt'][$row] );
					if( isset( $GLOBALS['loopcheck'] ) ) {
						$GLOBALS['looptt'][$row][$key] = $GLOBALS['loopcheck'];
						unset( $GLOBALS['loopcheck'] );
					}
					if( $depth < 12 ) {
						$progress++;
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
								$nextscore = ( int )round( ( $nextscore * 3 + $totalvalue / ( ( $nextcount + 1 ) * $nextcount / 2 ) * 2 ) / 5 );
							else {
								if( count( $nextmoves ) > 1 ) {
									if( $nextscore > 0 && $nextscore < 50 )
										$nextscore = ( int )round( ( $nextscore * 4 + $throttle ) / 5 );
								}
								else if( abs( $nextscore ) > 20 && abs( $nextscore ) < 75 ) {
									$nextscore = ( int )round( $nextscore * 9 / 10 );
								}
							}
						}
						if( $item != -$nextscore ) {
							$moves1[ $key ] = -$nextscore;
							$updatemoves[ $key ] = $nextscore;
						}
					}
/*
					if( $depth == 0 ) {
						if( $pid <= 0 ) {
							$fp = fopen( 'chess_' . $GLOBALS['curmove'] . '.txt', 'w' );
							fwrite( $fp, implode("\t", array( $GLOBALS['counter'], number_format( $progress * 100 / $total, 2, '.', '' ) . "%", $GLOBALS['counter_dup'], number_format( $GLOBALS['counter_dup'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['counter_update'], number_format( $GLOBALS['counter_update'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['counter_ply'], number_format( $GLOBALS['counter_ply'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['curmove'] ) ) . "\tdone\n" );
							fclose( $fp );
							break;
						}
					}
*/
				}
/*
				if( $depth == 0 ) {
					if( $pid > 0 ) {
						while( pcntl_wait($status) >= 0 ) {
						}
					}
				}
				if( $dedup )
					updateExists( $redis2, $minbinfen );
*/
			}
		}
		else
		{
			$loop_fen = $loop_fen_start;
			$loopmoves = array();
			do
			{
				array_push( $loopmoves, $GLOBALS['historytt'][$loop_fen]['move'] );
				$loop_fen = $GLOBALS['historytt'][$loop_fen]['fen'];
				if( !isset( $GLOBALS['historytt'][$loop_fen] ) )
					break;
			}
			while( $loop_fen != $row && $loop_fen != $BWfen );
			$loopstatus = 1;
			if( $loopstatus > 0 )
				$GLOBALS['looptt'][$loop_fen_start][$GLOBALS['historytt'][$loop_fen_start]['move']] = $loopstatus;
		}
		$loopinfo = array();
		if( isset( $GLOBALS['looptt'][$row] ) )
		{
			foreach( $GLOBALS['looptt'][$row] as $key => $entry ) {
				$loopinfo[$key] = $entry;
			}
		}
		if( isset( $GLOBALS['looptt'][$BWfen] ) )
		{
			foreach( $GLOBALS['looptt'][$BWfen] as $key => $entry ) {
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
				unset( $GLOBALS['looptt'][$row] );
				unset( $GLOBALS['looptt'][$BWfen] );
			}
		} else if( !$isloop ) {
			$GLOBALS['counter']++;
			$GLOBALS['boardtt'][abs( xxhash64( $row ) )] = 1;
			if( $GLOBALS['counter'] % 10000 == 0 ) {
				gc_collect_cycles();
				echo implode(' ', array( $GLOBALS['counter'], number_format( $progress * 100 / $total, 2, '.', '' ) . "%", $GLOBALS['counter_dup'], number_format( $GLOBALS['counter_dup'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['counter_update'], number_format( $GLOBALS['counter_update'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['counter_ply'], number_format( $GLOBALS['counter_ply'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['curmove'], $depth, intval( ( $GLOBALS['counter'] + $GLOBALS['counter_dup'] - $GLOBALS['last_counter'] ) / ( time() - $GLOBALS['last_ts'] + 1 ) ), count( $GLOBALS['looptt'] ) ) ) . "\n";
/*
				$fp = fopen( 'chess_' . $GLOBALS['curmove'] . '.txt', 'w' );
				fwrite( $fp, implode("\t", array( $GLOBALS['counter'], number_format( $progress * 100 / $total, 2, '.', '' ) . "%", $GLOBALS['counter_dup'], number_format( $GLOBALS['counter_dup'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['counter_update'], number_format( $GLOBALS['counter_update'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['counter_ply'], number_format( $GLOBALS['counter_ply'] * 100 / $GLOBALS['counter'], 2, '.', '' ) . "%", $GLOBALS['curmove'], $depth, intval( ( $GLOBALS['counter'] + $GLOBALS['counter_dup'] - $GLOBALS['last_counter'] ) / ( time() - $GLOBALS['last_ts'] + 1 ) ), count( $GLOBALS['looptt'] ) ) ) . "\n" );
				fclose( $fp );
*/
				$GLOBALS['last_counter'] = $GLOBALS['counter'] + $GLOBALS['counter_dup'];
				$GLOBALS['last_ts'] = time();
/*
				if( $GLOBALS['boardtt']->memoryUsage() > 2147483648 )
					$GLOBALS['boardtt']->free();
*/
			}
		}
		if( count( $updatemoves ) > 0 )
			updateScore( $redis, $minbinfen, $minindex, $updatemoves );
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
	$redis2 = new Redis();
	//$redis2->pconnect('192.168.1.2', 8890);
	$GLOBALS['counter'] = 0;
	$GLOBALS['counter_dup'] = 0;
	$GLOBALS['counter_update'] = 0;
	$GLOBALS['counter_ply'] = 0;
	$GLOBALS['last_ts'] = time();
	$GLOBALS['last_counter'] = 0;
	$GLOBALS['looptt'] = array();
	$GLOBALS['boardtt'] = new Judy( Judy::BITSET );
	getMoves( $redis, $redis2, 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -', 0, 0, 0 );
	echo 'ok' . "\n";

}
catch (RedisException $e) {
	echo 'Error: ' . $e->getMessage();
}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
}
