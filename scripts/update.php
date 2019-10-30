<?php
header("Cache-Control: no-cache");
header("Pragma: no-cache");
ini_set("memory_limit", "-1");

function getthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore - 1;
	}
	else if( $maxscore >= -30 ) {
		$throttle = (int)( $maxscore - 20 / ( 1 + exp( -abs( $maxscore ) / 10 ) ) );
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
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		$doc = $redis->hGetAll( hex2bin( $minhexfen ) );
		if( $minindex == 0 ) {
			foreach( $doc as $key => $item ) {
				if( $key == 'a0a0' )
					$moves['ply'] = $item;
				else
					$moves[$key] = -$item;
			}
		}
		else if( $minindex == 1 ) {
			foreach( $doc as $key => $item ) {
				if( $key == 'a0a0' )
					$moves['ply'] = $item;
				else
					$moves[ccbgetBWmove( $key )] = -$item;
			}
		}
		else if( $minindex == 2 ) {
			foreach( $doc as $key => $item ) {
				if( $key == 'a0a0' )
					$moves['ply'] = $item;
				else
					$moves[ccbgetLRmove( $key )] = -$item;
			}
		}
		else {
			foreach( $doc as $key => $item ) {
				if( $key == 'a0a0' )
					$moves['ply'] = $item;
				else
					$moves[ccbgetLRBWmove( $key )] = -$item;
			}
		}
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		$doc = $redis->hGetAll( hex2bin( $minhexfen ) );
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
					$moves[ccbgetBWmove( $key )] = -$item;
			}
		}
	}
	return $moves;
}
function updatePly( $redis, $row, $ply ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		$redis->hSet( hex2bin($minhexfen), 'a0a0', $ply );
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		$redis->hSet( hex2bin($minhexfen), 'a0a0', $ply );
	}
	$GLOBALS['counter_ply']++;
}
function updateScore( $redis, $row, $updatemoves ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		if( $minindex == 0 ) {
			$redis->hMSet( hex2bin($minhexfen), $updatemoves );
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetBWmove( $key )] = $newscore;
			}
			$redis->hMSet( hex2bin($minhexfen), $newmoves );
		}
		else if( $minindex == 2 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRmove( $key )] = $newscore;
			}
			$redis->hMSet( hex2bin($minhexfen), $newmoves );
		}
		else {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRBWmove( $key )] = $newscore;
			}
			$redis->hMSet( hex2bin($minhexfen), $newmoves );
		}
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		if( $minindex == 0 ) {
			$redis->hMSet( hex2bin($minhexfen), $updatemoves );

			foreach( $updatemoves as $key => $newscore ) {
				if( $key != ccbgetLRmove( $key ) )
				{
					$redis->hDel( hex2bin($minhexfen), ccbgetLRmove( $key ) );
				}
			}
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetBWmove( $key )] = $newscore;
			}
			$redis->hMSet( hex2bin($minhexfen), $newmoves );

			foreach( array_keys( $updatemoves ) as $key ) {
				if( $key != ccbgetLRmove( $key ) )
				{
					$redis->hDel( hex2bin($minhexfen), ccbgetLRBWmove( $key ) );
				}
			}
		}
	}
	$GLOBALS['counter_update']++;
}
function delScore( $redis, $row, $updatemoves ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		if( $minindex == 0 ) {
			foreach( $updatemoves as $key => $newscore ) {
				$redis->hDel( hex2bin($minhexfen), $key );
			}
		}
		else if( $minindex == 1 ) {
			foreach( $updatemoves as $key => $newscore ) {
				$redis->hDel( hex2bin($minhexfen), ccbgetBWmove( $key ) );
			}
		}
		else if( $minindex == 2 ) {
			foreach( $updatemoves as $key => $newscore ) {
				$redis->hDel( hex2bin($minhexfen), ccbgetLRmove( $key ) );
			}
		}
		else {
			foreach( $updatemoves as $key => $newscore ) {
				$redis->hDel( hex2bin($minhexfen), ccbgetLRBWmove( $key ) );
			}
		}
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		if( $minindex == 0 ) {
			foreach( $updatemoves as $key => $newscore ) {
				$redis->hDel( hex2bin($minhexfen), $key );
				if( $key != ccbgetLRmove( $key ) )
				{
					$redis->hDel( hex2bin($minhexfen), ccbgetLRmove( $key ) );
				}
			}
		}
		else if( $minindex == 1 ) {
			foreach( $updatemoves as $key => $newscore ) {
				$redis->hDel( hex2bin($minhexfen), ccbgetBWmove( $key ) );
				if( $key != ccbgetLRmove( $key ) )
				{
					$redis->hDel( hex2bin($minhexfen), ccbgetLRBWmove( $key ) );
				}
			}
		}
	}
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
	$moves1 = getAllScores( $redis, $row );
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$current_hash = abs( xxhash64( $row ) );
	$current_hash_bw = abs( xxhash64( $BWfen ) );
	
	$hasLRmirror = ( $row == $LRfen ? false : true );

	if( $hasLRmirror )
	{
		$LRBWfen = ccbgetLRfen( $BWfen );
		$current_hash_lr = abs( xxhash64( $LRfen ) );
		$current_hash_lrbw = abs( xxhash64( $LRBWfen ) );
	}

	$recurse = false;
	if( isset($moves1['ply']) )
	{
		if( $moves1['ply'] < 0 || $moves1['ply'] > $depth )
			updatePly( $redis, $row, $depth );
	}
	else if( count( $moves1 ) > 0 )
		updatePly( $redis, $row, $depth );

	if( !isset($moves1['ply']) || $moves1['ply'] < 0 || $moves1['ply'] >= $depth )
	{	
		if( !isset( $GLOBALS['boardtt'][$current_hash] ) )
		{
			if( !isset( $GLOBALS['boardtt'][$current_hash_bw] ) )
			{
				if( $hasLRmirror )
				{
					if( !isset( $GLOBALS['boardtt'][$current_hash_lr] ) )
					{
						if( !isset( $GLOBALS['boardtt'][$current_hash_lrbw] ) )
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
/*
		$errors = array_diff_key( $moves1, ccbmovegen( $row ) );
		if( count( $errors ) ) {
			echo $row . "\n";
			delScore( $redis, $row, $errors );
			$moves1 = array_intersect_key( $moves1, ccbmovegen( $row ) );
		}
*/
		$updatemoves = array();
		$isloop = true;
		if( !isset( $GLOBALS['historytt'][$current_hash] ) )
		{
			if( !isset( $GLOBALS['historytt'][$current_hash_bw] ) )
			{
				if( $hasLRmirror )
				{
					if( !isset( $GLOBALS['historytt'][$current_hash_lr] ) )
					{
						if( !isset( $GLOBALS['historytt'][$current_hash_lrbw] ) )
						{
							$isloop = false;
						}
						else
						{
							$loop_hash_start = $current_hash_lrbw;
							$loop_fen_start = $LRBWfen;
						}
					}
					else
					{
						$loop_hash_start = $current_hash_lr;
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
			if( $depth < 5 )
			{
				shuffle_assoc( $moves1 );
				foreach( $moves1 as $key => $item ) {

					if( $depth == 0 )
						$GLOBALS['curmove'] = $key;

					$nextfen = ccbmovemake( $row, $key );
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
			while( $loop_hash != $current_hash && $loop_hash != $current_hash_bw && ( !$hasLRmirror || ( $hasLRmirror && $loop_hash != $current_hash_lr && $loop_hash != $current_hash_lrbw ) ) );
			$loopstatus = ccrulecheck( $loop_fen_start, $loopmoves );
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
				$loopinfo[ccbgetBWmove( $key )] = $entry;
			}
		}
		if( $hasLRmirror )
		{
			if( isset( $GLOBALS['looptt'][$current_hash_lr] ) )
			{
				foreach( $GLOBALS['looptt'][$current_hash_lr] as $key => $entry ) {
					$loopinfo[ccbgetLRmove( $key )] = $entry;
				}
			}
			if( isset( $GLOBALS['looptt'][$current_hash_lrbw] ) )
			{
				foreach( $GLOBALS['looptt'][$current_hash_lrbw] as $key => $entry ) {
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
					if( $moves1[$key] == $bestscore && abs( $bestscore ) < 10000 ) {
						$moves1[$key] = 0;
						//if( !$isloop )
						//	$updatemoves[$key] = 0;
					}
				}
			}
			if( count( $loopmebans ) > 0 ) {
				$moves2 = array_diff_key( $moves1, $loopmebans );
				if( count( $moves2 ) > 0 ) {
					asort( $moves2 );
					$bestscore = end( $moves2 );
					foreach( array_keys( array_intersect_key( $moves1, $loopmebans ) ) as $key ) {
						$moves1[$key] = $bestscore;
						if( !$isloop )
							$updatemoves[$key] = -$bestscore;
					}
				}
				else {
					$allmoves = ccbmovegen( $row );
					$moves3 = array_diff_key( $allmoves, $loopmebans );
					if( count( $moves3 ) > 0 ) {
						$GLOBALS['loopcheck'] = 3;
					}
					else {
						foreach( array_keys( array_intersect_key( $moves1, $loopmebans ) ) as $key ) {
							$moves1[$key] = -30000;
							if( !$isloop )
								$updatemoves[$key] = 30000;
						}
					}
				}
			}
			if( count( $loopoppbans ) > 0 ) {
				$GLOBALS['loopcheck'] = 2;
			}

			unset( $GLOBALS['looptt'][$current_hash] );
			unset( $GLOBALS['looptt'][$current_hash_bw] );
			if( $hasLRmirror )
			{
				unset( $GLOBALS['looptt'][$current_hash_lr] );
				unset( $GLOBALS['looptt'][$current_hash_lrbw] );
			}
		} else if( !$isloop ) {
			$GLOBALS['counter']++;
			$GLOBALS['boardtt'][$current_hash] = 1;
			if( $GLOBALS['counter'] % 10000 == 0) {
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
	getMoves( $redis, 'rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w', 0 );
	echo 'ok' . "\n";

}
catch (RedisException $e) {
	echo 'Error: ' . $e->getMessage();
}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
}
