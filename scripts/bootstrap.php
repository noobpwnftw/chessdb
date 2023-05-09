<?php
header("Cache-Control: no-cache");
header("Pragma: no-cache");

function count_pieces( $fen ) {
	@list( $board, $color ) = explode( " ", $fen );
	$pieces = 'rnckabp';
	return strlen( $board ) - strlen( str_ireplace( str_split( $pieces ), '', $board ) );
}
function count_attackers( $fen ) {
	@list( $board, $color ) = explode( " ", $fen );
	$pieces = 'rnc';
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
function getBinFenStorage( $hexfenarr ) {
	asort( $hexfenarr, SORT_STRING );
	$minhexfen = reset( $hexfenarr );
	return array( hex2bin( $minhexfen ), key( $hexfenarr ) );
}
function getAllScores( $redis, $minbinfen, $minindex, $hasLRmirror ) {
	$moves = array();
	$finals = array();
	if( $hasLRmirror ) {
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
		else if( $minindex == 1 ) {
			foreach( $doc as $key => $item ) {
				$item = (int)$item;
				if( $key == 'a0a0' )
					$moves['ply'] = $item;
				else {
					if( abs( $item ) >= 30000 ) {
						if ( $item == -30001 ) {
							$item = 0;
						}
						$finals[ccbgetBWmove( $key )] = 1;
					}
					$moves[ccbgetBWmove( $key )] = -$item;
				}
			}
		}
		else if( $minindex == 2 ) {
			foreach( $doc as $key => $item ) {
				$item = (int)$item;
				if( $key == 'a0a0' )
					$moves['ply'] = $item;
				else {
					if( abs( $item ) >= 30000 ) {
						if ( $item == -30001 ) {
							$item = 0;
						}
						$finals[ccbgetLRmove( $key )] = 1;
					}
					$moves[ccbgetLRmove( $key )] = -$item;
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
						$finals[ccbgetLRBWmove( $key )] = 1;
					}
					$moves[ccbgetLRBWmove( $key )] = -$item;
				}
			}
		}
	}
	else {
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
						if( $key <= ccbgetLRmove( $key ) )
							$finals[$key] = 1;
						else
							$finals[ccbgetLRmove( $key )] = 1;
					}
					if( $key <= ccbgetLRmove( $key ) )
						$moves[$key] = -$item;
					else
						$moves[ccbgetLRmove( $key )] = -$item;
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
						if( ccbgetBWmove( $key ) <= ccbgetLRBWmove( $key ) )
							$finals[ccbgetBWmove( $key )] = 1;
						else
							$finals[ccbgetLRBWmove( $key )] = 1;
					}
					if( ccbgetBWmove( $key ) <= ccbgetLRBWmove( $key ) )
						$moves[ccbgetBWmove( $key )] = -$item;
					else
						$moves[ccbgetLRBWmove( $key )] = -$item;
				}
			}
		}
	}
	return array( $moves, $finals );
}
function updatePly( $redis, $minbinfen, $ply ) {
	if( $redis->hSet( $minbinfen, 'a0a0', $ply ) === FALSE )
		throw new RedisException( 'Server operation error.' );
}
function updateScore( $redis, $minbinfen, $minindex, $hasLRmirror, $updatemoves ) {
	if( $hasLRmirror ) {
		if( $minindex == 0 ) {
			$redis->hMSet( $minbinfen, $updatemoves );
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetBWmove( $key )] = $newscore;
			}
			$redis->hMSet( $minbinfen, $newmoves );
		}
		else if( $minindex == 2 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRmove( $key )] = $newscore;
			}
			$redis->hMSet( $minbinfen, $newmoves );
		}
		else {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRBWmove( $key )] = $newscore;
			}
			$redis->hMSet( $minbinfen, $newmoves );
		}
	}
	else {
		if( $minindex == 0 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				if( $key <= ccbgetLRmove( $key ) )
					$newmoves[$key] = $newscore;
				else
					$newmoves[ccbgetLRmove( $key )] = $newscore;
			}
			$redis->hMSet( $minbinfen, $newmoves );
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				if( ccbgetBWmove( $key ) <= ccbgetLRBWmove( $key ) )
					$newmoves[ccbgetBWmove( $key )] = $newscore;
				else
					$newmoves[ccbgetLRBWmove( $key )] = $newscore;
			}
			$redis->hMSet( $minbinfen, $newmoves );
		}
	}
}
function updateQueue( $row, $key, $priority ) {
	$fq = new FlexibleQueueDBClient( '/tmp/ccqueue.sock' );
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		if( $minindex == 0 ) {
			$fq->push( 0, $minbinfen, $key, $priority, time() + 7200, true, true );
		}
		else if( $minindex == 1 ) {
			$fq->push( 0, $minbinfen, ccbgetBWmove( $key ), $priority, time() + 7200, true, true );
		}
		else if( $minindex == 2 ) {
			$fq->push( 0, $minbinfen, ccbgetLRmove( $key ), $priority, time() + 7200, true, true );
		}
		else {
			$fq->push( 0, $minbinfen, ccbgetLRBWmove( $key ), $priority, time() + 7200, true, true );
		}
	}
	else {
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		if( $minindex == 0 ) {
			if( $key <= ccbgetLRmove( $key ) ) {
				$fq->push( 0, $minbinfen, $key, $priority, time() + 7200, true, true );
			}
			else {
				$fq->push( 0, $minbinfen, ccbgetLRmove( $key ), $priority, time() + 7200, true, true );
			}
		}
		else if( $minindex == 1 ) {
			if( ccbgetBWmove( $key ) <= ccbgetLRBWmove( $key ) ) {
				$fq->push( 0, $minbinfen, ccbgetBWmove( $key ), $priority, time() + 7200, true, true );
			}
			else {
				$fq->push( 0, $minbinfen, ccbgetLRBWmove( $key ), $priority, time() + 7200, true, true );
			}
		}
	}
}
function getMoves( $redis, $row, $depth ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	
	$hasLRmirror = ( $row == $LRfen ? false : true );

	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
	}
	else {
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
	}

	list( $moves1, $finals ) = getAllScores( $redis, $minbinfen, $minindex, $hasLRmirror );

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
				if( $hasLRmirror )
				{
					if( !isset( $GLOBALS['boardtt'][xxhash64( $LRfen ) & PHP_INT_MAX] ) )
					{
						if( !isset( $GLOBALS['boardtt'][xxhash64( $LRBWfen ) & PHP_INT_MAX] ) )
						{
							$recurse = true;
						}
					}
				}
				else
				{
					$recurse = true;
				}
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
				if( $hasLRmirror )
				{
					if( !isset( $GLOBALS['historytt'][$LRfen] ) )
					{
						if( !isset( $GLOBALS['historytt'][$LRBWfen] ) )
						{
							$isloop = false;
						}
						else
						{
							$loop_fen_start = $LRBWfen;
						}
					}
					else
					{
						$loop_fen_start = $LRfen;
					}
				}
				else
				{
					$isloop = false;
				}
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
			if( $depth < 5 )
			{
				$updatemoves = array();
				asort( $moves1 );
				$moves2 = ccbmovegen( $row );
				foreach( $moves1 as $key => $item ) {
					$moves2[ $key ] = $item;
				}
				arsort( $moves2 );
				foreach( $moves2 as $key => $item ) {
					if( $depth == 0 )
						$GLOBALS['curmove'] = $key;
					if( isset( $finals[ $key ] ) )
						continue;
					$nextfen = ccbmovemake( $row, $key );
					$GLOBALS['historytt'][$row]['fen'] = $nextfen;
					$GLOBALS['historytt'][$row]['move'] = $key;
					$nextmoves = getMoves( $redis, $nextfen, $depth + 1 );
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
					else if( count_pieces( $nextfen ) >= 22 && count_attackers( $nextfen ) >= 10 && count( ccbmovegen( $nextfen ) ) > 0 )
					{
						updateQueue( $row, $key, false );
					}
				}
				/*
				if( count( $updatemoves ) > 0 )
					updateScore( $redis, $minbinfen, $minindex, $hasLRmirror, $updatemoves );
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
			while( $loop_fen != $row && $loop_fen != $BWfen && ( !$hasLRmirror || ( $hasLRmirror && $loop_fen != $LRfen && $loop_fen != $LRBWfen ) ) );
			$loopstatus = ccbrulecheck( $loop_fen_start, $loopmoves );
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
				$loopinfo[ccbgetBWmove( $key )] = $entry;
			}
		}
		if( $hasLRmirror )
		{
			if( isset( $GLOBALS['looptt'][$LRfen] ) )
			{
				foreach( $GLOBALS['looptt'][$LRfen] as $key => $entry ) {
					$loopinfo[ccbgetLRmove( $key )] = $entry;
				}
			}
			if( isset( $GLOBALS['looptt'][$LRBWfen] ) )
			{
				foreach( $GLOBALS['looptt'][$LRBWfen] as $key => $entry ) {
					$loopinfo[ccbgetLRBWmove( $key )] = $entry;
				}
			}
		}
		if( count( $loopinfo ) > 0 ) {
			$loopdraws = array();
			$loopmebans = array();
			$loopoppbans = array();
			foreach( $loopinfo as $key => $entry ) {
				if( $entry == 1 )
					$loopdraws[$key] = 1;
				else if( $entry == 2 )
					$loopmebans[$key] = 1;
				else if( $entry == 3 )
					$loopoppbans[$key] = 1;
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
			if( count( $loopmebans ) > 0 ) {
				$moves2 = array_diff_key( $moves1, $loopmebans );
				if( count( $moves2 ) > 0 ) {
					if( $isloop ) {
						asort( $moves2 );
						$bestscore = end( $moves2 );
						foreach( array_keys( array_intersect_key( $moves1, $loopmebans ) ) as $key ) {
							$moves1[$key] = $bestscore;
						}
					}
				}
				else {
					$GLOBALS['loopcheck'] = 3;
				}
			}
			if( count( $loopoppbans ) > 0 ) {
				$GLOBALS['loopcheck'] = 2;
			}

			if( !$isloop ) {
				unset( $GLOBALS['looptt'][$row] );
				unset( $GLOBALS['looptt'][$BWfen] );
				if( $hasLRmirror )
				{
					unset( $GLOBALS['looptt'][$LRfen] );
					unset( $GLOBALS['looptt'][$LRBWfen] );
				}
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
	$redis->connect('dbserver.internal', 8889);
	$GLOBALS['counter'] = 0;
	$GLOBALS['boardtt'] = new Judy( Judy::BITSET );
	getMoves( $redis, 'rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w', 0 );
	echo 'ok' . "\n";

}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
}
