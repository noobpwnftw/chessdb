<?php
header("Cache-Control: no-cache");
header("Pragma: no-cache");

function count_pieces( $fen ) {
	@list( $board, $color ) = explode( " ", $fen );
	$pieces = 'kqrbnp';
	return strlen( $board ) - strlen( str_ireplace( str_split( $pieces ), '', $board ) );
}
function count_attackers( $fen ) {
	@list( $board, $color ) = explode( " ", $fen );
	$pieces = 'qrbn';
	return strlen( $board ) - strlen( str_ireplace( str_split( $pieces ), '', $board ) );
}
function round_stochastic( $x, $key ) {
	$t = ( int )$x;
	if ( $x == $t )
		return $t;
	if ( abs( $t ) <= 2 )
		return ( int )round( $x );
	$b = floor( $x );
	$f = $x - $b;
	$u = ( xxhash64( $key ) & PHP_INT_MAX ) / ( PHP_INT_MAX + 1.0 );
	return ( int )( $u < $f ? $b + 1 : $b );
}
function getthrottle( $maxscore ) {
	$upper_max = 75;
	$upper_min = 50;
	$lower_max = -30;
	$lower_min = -50;
	if( $maxscore >= $upper_max ) {
		$throttle = $maxscore;
	}
	else if( $maxscore >= $lower_max ) {
		$throttle = $maxscore - 10 / ( 1 + exp( -abs( $maxscore ) / 10 ) );
		if( $maxscore > $upper_min ) {
			$throttle = $throttle + ( ( $maxscore - $upper_min ) / ( $upper_max - $upper_min ) ) * ( $maxscore - $throttle );
		}
	}
	else if( $maxscore > $lower_min ) {
		$throttle = $lower_max - 10 / ( 1 + exp( -abs( $lower_max ) / 10 ) );
		$throttle = $lower_min + ( $maxscore - $lower_min ) / ( $lower_max - $lower_min ) * ( $throttle - $lower_min );
	}
	else {
		$throttle = $lower_min;
	}
	return $throttle;
}
function getbestthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore;
	}
	else if( $maxscore >= -30 ) {
		$throttle = $maxscore - 5 / ( 1 + exp( -abs( $maxscore ) / 20 ) );
		if( $maxscore > 0 )
			$throttle = max( 1, $throttle );
	}
	else {
		$throttle = $maxscore;
	}
	return $throttle;
}
function getBinFenStorage( $hexfenarr ) {
	asort( $hexfenarr, SORT_STRING );
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
			$item = (int)$item;
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
			$item = (int)$item;
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
}
function updateQueue( $row, $key, $priority ) {
	$fq = new FlexibleQueueDBClient( '/tmp/cqueue.sock' );
	$BWfen = cbgetBWfen( $row );
	list( $minbinfen, $minindex ) = getBinFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	if( $minindex == 0 ) {
		$fq->push( 0, $minbinfen, $key, $priority, time() + 7200, true, true );
	}
	else if( $minindex == 1 ) {
		$fq->push( 0, $minbinfen, cbgetBWmove( $key ), $priority, time() + 7200, true, true );
	}
}
function updateSel( $row, $priority ) {
	$fq = new FlexibleQueueDBClient( '/tmp/csel.sock' );
	$BWfen = cbgetBWfen( $row );
	list( $minbinfen, $minindex ) = getBinFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	$fq->push( 0, $minbinfen, '', $priority, time() + 7200, true, false );
}
function getMoves( $redis, $row, $frc, $depth ) {
	$BWfen = cbgetBWfen( $row );
	list( $minbinfen, $minindex ) = getBinFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	list( $moves1, $finals ) = getAllScores( $redis, $minbinfen, $minindex );

	$recurse = false;
	if( isset($moves1['ply']) )
	{
		if( $moves1['ply'] < 0 || $moves1['ply'] > $depth )
			updatePly( $redis, $minbinfen, $depth );
	}
	else if( count( $moves1 ) > 0 )
		updatePly( $redis, $minbinfen, $depth );

	//if( !isset($moves1['ply']) || $moves1['ply'] < 0 || $moves1['ply'] >= $depth )
	{
		if( !isset( $GLOBALS['boardtt'][xxhash64( $row ) & PHP_INT_MAX] ) )
		{
			if( !isset( $GLOBALS['boardtt'][xxhash64( $BWfen ) & PHP_INT_MAX] ) )
			{
				$recurse = true;
			}
		}
	}
	unset( $moves1['ply'] );

	if( $recurse && $depth < 30000 )
	{
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
			//if( $depth < 12 )
			{
				$updatemoves = array();
				asort( $moves1 );
				$throttle = getbestthrottle( end( $moves1 ) );
				if( $depth == 0 ) {
					$throttle = -200;
				}
				$knownmoves = array();
				$moves2 = array();
				foreach( $moves1 as $key => $item ) {
					if( $item >= $throttle ) {
						$moves2[ $key ] = $item;
					}
					$knownmoves[$key] = 0;
				}
				arsort( $moves2 );
				foreach( $moves2 as $key => $item ) {
					if( $depth == 0 )
						$GLOBALS['curmove'] = $key;
					if( isset( $finals[ $key ] ) )
						continue;
					list( $nextfen, $nextfrc ) = cbmovemake( $row, $key, $frc );
					$GLOBALS['historytt'][$row]['fen'] = $nextfen;
					$GLOBALS['historytt'][$row]['move'] = $key;
					$nextmoves = getMoves( $redis, $nextfen, $nextfrc, $depth + 1 );
					unset( $GLOBALS['historytt'][$row] );
					if( isset( $GLOBALS['loopcheck'] ) ) {
						$GLOBALS['looptt'][$row][$key] = $GLOBALS['loopcheck'];
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
								$nextscore = ( int )round_stochastic( ( $nextscore * 3 + $totalvalue / ( ( $nextcount + 1 ) * $nextcount / 2 ) * 2 ) / 5, $minbinfen . $key . $nextscore );
							else {
								if( count( $nextmoves ) > 1 ) {
									if( $nextscore > 0 && $nextscore < 50 )
										$nextscore = ( int )round_stochastic( ( $nextscore * 4 + $throttle ) / 5, $minbinfen . $key . $nextscore );
									else if( $nextscore >= 50 && $nextscore < 75 )
										$nextscore = ( int )round_stochastic( ( $nextscore * 13 + $throttle * 2 ) / 15, $minbinfen . $key . $nextscore );
								}
								else if( abs( $nextscore ) > 20 && abs( $nextscore ) < 100 ) {
									$t = max( 0, ( abs( $nextscore ) - 50 ) / ( 100 - 50 ) );
									$nextscore = ( int )round_stochastic( $nextscore * 0.9, $minbinfen . $key . $nextscore ) + ( int )round_stochastic( $nextscore * 0.1 * $t, $minbinfen . $key . $nextscore );
								}
							}
						}
						if( $item != -$nextscore ) {
							$moves1[ $key ] = -$nextscore;
							$updatemoves[ $key ] = $nextscore;
						}
					}
					else if( count_pieces( $nextfen ) >= 22 && count_attackers( $nextfen ) >= 10 && count( cbmovegen( $nextfen, $nextfrc ) ) > 0 )
					{
						updateQueue( $row, $key, false );
					}
				}
				if( count( $updatemoves ) > 0 )
					updateScore( $redis, $minbinfen, $minindex, $updatemoves );
				$allmoves = cbmovegen( $row, $frc );
				if( count( $allmoves ) > count( $knownmoves ) ) {
					if( count( $knownmoves ) < 5 ) {
						updateSel( $row, false );
					}
				}
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
			$GLOBALS['boardtt'][xxhash64( $row ) & PHP_INT_MAX] = 1;
			if( $GLOBALS['counter'] % 10000 == 0) {
				echo $GLOBALS['counter'] . ' ' . $GLOBALS['curmove'] . ' ' . $depth . "\n";
			}
		}
	}

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
	$redis->connect('dbserver.internal', 8888);
	$GLOBALS['counter'] = 0;
	$GLOBALS['boardtt'] = new Judy( Judy::BITSET );
	getMoves( $redis, 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -', false, 0 );
	echo 'ok' . "\n";

}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
}
