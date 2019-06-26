<?php
header("Cache-Control: no-cache");
header("Pragma: no-cache");

function getthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore;
	}
	else if( $maxscore >= -30 ) {
		$throttle = (int)( $maxscore - 20 / ( 1 + exp( -abs( $maxscore ) / 10 ) ) );
	}
	else {
		$throttle = -50;
	}
	return $throttle;
}
function getbestthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore;
	}
	else if( $maxscore >= -30 ) {
		$throttle = (int)( $maxscore - 10 / ( 1 + exp( -abs( $maxscore ) / 20 ) ) );
	}
	else {
		$throttle = $maxscore;
	}
	return $throttle;
}
function getadvancethrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore;
	}
	else if( $maxscore >= -30 ) {
		$throttle = (int)( $maxscore - 40 / ( 1 + exp( -abs( $maxscore ) / 10 ) ) );
	}
	else {
		$throttle = -75;
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
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	$doc = $redis->hGetAll( hex2bin( $minhexfen ) );
	if( $doc === FALSE )
		throw new RedisException( 'Server operation error.' );
	if( $minindex == 0 ) {
		foreach( $doc as $key => $item ) {
			if( $key == 'a0a0' )
				$moves['ply'] = $item;
			else
				$moves[$key] = -$item;
		}
	}
	else {
		foreach( $doc as $key => $item ) {
			if( $key == 'a0a0' )
				$moves['ply'] = $item;
			else
				$moves[cbgetBWmove( $key )] = -$item;
		}
	}
	return $moves;
}
function getMoves( $redis, $row, $depth ) {
	$moves1 = getAllScores( $redis, $row );
	$BWfen = cbgetBWfen( $row );
	$current_hash = crc32( $row );
	$current_hash_bw = crc32( $BWfen );

	$recurse = false;
	if( !isset($moves1['ply']) || $moves1['ply'] < 0 || $moves1['ply'] > $depth )
		updatePly( $redis, $row, $depth );

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
	unset( $moves1['ply'] );

	if( $recurse && $depth < 30000 )
	{
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
			asort( $moves1 );
			$throttle = getadvancethrottle( end( $moves1 ) );
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

				$nextfen = cbmovemake( $row, $key );
				$GLOBALS['historytt'][$current_hash]['fen'] = $nextfen;
				$GLOBALS['historytt'][$current_hash]['move'] = $key;
				$nextmoves = getMoves( $redis, $nextfen, $depth + 1 );
				unset( $GLOBALS['historytt'][$current_hash] );
				if( isset( $GLOBALS['loopcheck'] ) ) {
					$GLOBALS['looptt'][$current_hash][$key] = $GLOBALS['loopcheck'];
					unset( $GLOBALS['loopcheck'] );
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
				$loop_hash = crc32( $loopfen );
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
			$loopmebans = array();
			$loopoppbans = array();
			foreach( $loopinfo as $key => $entry ) {
				if( $entry == 1 )
					$loopdraws[$key] = 1;
			}
			if( $isloop && count( $loopdraws ) > 0 ) {
				asort( $moves1 );
				$bestscore = end( $moves1 );
				foreach( array_keys( array_intersect_key( $moves1, $loopdraws ) ) as $key ) {
					if( $moves1[$key] == $bestscore && abs( $bestscore ) < 10000 ) {
						$moves1[$key] = 0;
					}
				}
			}

			unset( $GLOBALS['looptt'][$current_hash] );
			unset( $GLOBALS['looptt'][$current_hash_bw] );
		} else {
			$GLOBALS['counter']++;
			$GLOBALS['boardtt'][$current_hash] = 1;
			arsort( $moves1 );
			$maxscore = reset( $moves1 );
			$throttle = getthrottle( $maxscore );
			foreach( $moves1 as $move => $score ) {
				if( $score >= $throttle && $score >= getbestthrottle( $maxscore ) )
					echo $row . " " . $move . " 2\n";
				else if( $score >= $throttle )
					echo $row . " " . $move . " 1\n";
				else
					break;
			}
		}
	}
	return $moves1;
}

try{
	$redis = new Redis();
	$redis->pconnect('localhost', 8888);
	$GLOBALS['counter'] = 0;
	$GLOBALS['boardtt'] = new Judy( Judy::BITSET );
	getMoves( $redis, 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -', 0 );
}
catch (MongoException $e) {
	echo 'Error: ' . $e->getMessage();
}
catch (RedisException $e) {
	echo 'Error: ' . $e->getMessage();
}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
}
