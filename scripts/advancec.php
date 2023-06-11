<?php
header("Cache-Control: no-cache");
header("Pragma: no-cache");

class MyRWLock {
	private $handle;
	private $locked;
	private $iswrite;

	public function __construct( $name ) {
		$this->handle = fopen( sys_get_temp_dir() . '/' . $name . '.lock', 'c' );
	}

	public function readlock () {
		if( $this->locked )
			throw new Exception( 'Read lock error.' );

		if( !flock( $this->handle, LOCK_SH ) )
			throw new Exception( 'Lock operation failed.' );

		$this->iswrite = false;
		$this->locked = true;
	}

	public function writelock () {
		if( $this->locked )
			throw new Exception( 'Write lock error.' );

		if( !flock( $this->handle, LOCK_EX ) )
			throw new Exception( 'Lock operation failed.' );

		$this->iswrite = true;
		$this->locked = true;
	}

	public function readunlock () {
		if( !$this->locked || $this->iswrite )
			throw new Exception( 'Read unlock error.' );

		if( !flock( $this->handle, LOCK_UN ) )
			throw new Exception( 'Unlock operation error.' );

		$this->iswrite = false;
		$this->locked = false;
	}

	public function writeunlock () {
		if( !$this->locked || !$this->iswrite )
			throw new Exception( 'Write unlock error.' );

		if( !flock( $this->handle, LOCK_UN ) )
			throw new Exception( 'Unlock operation error.' );
		
		$this->iswrite = false;
		$this->locked = false;
	}

	public function __destruct () {
		fclose( $this->handle );
	}
}

$readwrite_queue = new MyRWLock( "ChessDBLockQueue2" );

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
function getbestthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore - 1;
	}
	else if( $maxscore >= -30 ) {
		$throttle = (int)( $maxscore - 5 / ( 1 + exp( -abs( $maxscore ) / 20 ) ) );
	}
	else {
		$throttle = $maxscore;
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
}
function updateQueue( $row, $key, $priority ) {
	global $readwrite_queue;
	$m = new MongoClient('mongodb://localhost');
	$collection = $m->selectDB('cdbqueue')->selectCollection('queuedb');
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	if( $minindex == 0 ) {
		$readwrite_queue->readlock();
		do {
			try {
				$tryAgain = false;
				if( $priority ) {
					$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, $key => 0 ) ), array( 'upsert' => true ) );
				} else {
					$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( $key => 0 ) ), array( 'upsert' => true ) );
				}
			}
			catch( MongoDuplicateKeyException $e ) {
				$tryAgain = true;
			}
		} while($tryAgain);
		$readwrite_queue->readunlock();
	}
	else if( $minindex == 1 ) {
		$readwrite_queue->readlock();
		do {
			try {
				$tryAgain = false;
				if( $priority ) {
					$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, cbgetBWmove( $key ) => 0 ) ), array( 'upsert' => true ) );
				} else {
					$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( cbgetBWmove( $key ) => 0 ) ), array( 'upsert' => true ) );
				}
			}
			catch( MongoDuplicateKeyException $e ) {
				$tryAgain = true;
			}
		} while($tryAgain);
		$readwrite_queue->readunlock();
	}
}
function updateSel( $row, $priority ) {
	$m = new MongoClient('mongodb://localhost');
	$collection = $m->selectDB('cdbsel')->selectCollection('seldb');
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	if( $priority ) {
		$doc = array( '$set' => array( 'p' => 1 ) );
	} else {
		$doc = array();
	}
	do {
		try {
			$tryAgain = false;
			$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), $doc, array( 'upsert' => true ) );
		}
		catch( MongoDuplicateKeyException $e ) {
			$tryAgain = true;
		}
	} while($tryAgain);
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

	//if( !isset($moves1['ply']) || $moves1['ply'] < 0 || $moves1['ply'] >= $depth )
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
			//if( $depth < 12 )
			{
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
					$nextfen = cbmovemake( $row, $key );
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
					else if( count_pieces( $nextfen ) >= 22 && count_attackers( $nextfen ) >= 10 && count( cbmovegen( $nextfen ) ) > 0 )
					{
						updateQueue( $row, $key, false );
					}
				}
				$allmoves = cbmovegen( $row );
				if( count( $allmoves ) > count( $knownmoves ) ) {
					if( count( $knownmoves ) < 5 ) {
						updateSel( $row, false );
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
			if( $GLOBALS['counter'] % 10000 == 0) {
				echo $GLOBALS['counter'] . ' ' . $GLOBALS['curmove'] . ' ' . $depth . "\n";
			}
		}
		if( count( $updatemoves ) > 0 )
			updateScore( $redis, $row, $updatemoves );
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
	$redis->pconnect('192.168.1.2', 8888);
	$GLOBALS['counter'] = 0;
	$GLOBALS['boardtt'] = new Judy( Judy::BITSET );
	getMoves( $redis, 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -', 0 );
	echo 'ok' . "\n";

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
