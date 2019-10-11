<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");

$MASTER_PASSWORD = '123456';

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

	public function trywritelock () {
		if( $this->locked )
			throw new Exception( 'Write lock error.' );

		if( !flock( $this->handle, LOCK_EX | LOCK_NB, $wouldblock ) ) {
			if( $wouldblock )
				return false;
			else
				throw new Exception( 'Lock operation failed.' );
		}
		$this->iswrite = true;
		$this->locked = true;
		return true;
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
$readwrite_sel = new MyRWLock( "ChessDBLockSel2" );

function getWinRate( $score ) {
	return number_format( 100 / ( 1 + exp( -$score / 330 ) ), 2 );
}
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
function getlearnthrottle( $maxscore ) {
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
function setOverrides( $row, &$moves ) {
	$oldscores = array();
	if( $row == 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -' )
	{
		$overrides = array( 'e2e4' => 15, 'd2d4' => 15, 'g1f3' => 15, 'c2c4' => 10, 'g2g3' => 10 );
		foreach( $overrides as $key => $value ) {
			if( isset( $moves[$key] ) ) {
				$oldscores[$key] = $moves[$key];
				$moves[$key] = $value;
			}
		}
	}
	else if( $row == 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR b KQkq -' )
	{
		$overrides = array( 'e7e5' => 15, 'd7d5' => 15, 'g8f6' => 15, 'c7c5' => 10, 'g7g6' => 10 );
		foreach( $overrides as $key => $value ) {
			if( isset( $moves[$key] ) ) {
				$oldscores[$key] = $moves[$key];
				$moves[$key] = $value;
			}
		}
	}
	return $oldscores;
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
function scoreExists( $redis, $row, $move ) {
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	if( $minindex == 0 ) {
		return $redis->hExists( hex2bin( $minhexfen ), $move );
	}
	else {
		return $redis->hExists( hex2bin( $minhexfen ), cbgetBWmove( $move ) );
	}
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
		$readwrite_queue->writelock();
		if( $priority ) {
			$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, $key => 0 ) ), array( 'upsert' => true ) );
		} else {
			$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( $key => 0 ) ), array( 'upsert' => true ) );
		}
		$readwrite_queue->writeunlock();
	}
	else if( $minindex == 1 ) {
		$readwrite_queue->writelock();
		if( $priority ) {
			$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, cbgetBWmove( $key ) => 0 ) ), array( 'upsert' => true ) );
		} else {
			$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( cbgetBWmove( $key ) => 0 ) ), array( 'upsert' => true ) );
		}
		$readwrite_queue->writeunlock();
	}
}
function updateSel( $row, $priority ) {
	global $readwrite_sel;
	$m = new MongoClient('mongodb://localhost');
	$collection = $m->selectDB('cdbsel')->selectCollection('seldb');
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	if( $priority ) {
		$doc = array( '$set' => array( 'p' => 1 ) );
	} else {
		$doc = array();
	}
	$readwrite_sel->writelock();
	$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), $doc, array( 'upsert' => true ) );
	$readwrite_sel->writeunlock();
}
function updatePly( $redis, $row, $ply ) {
	$BWfen = cbgetBWfen( $row );
	list( $minhexfen, $minindex ) = getHexFenStorage( array( cbfen2hexfen($row), cbfen2hexfen($BWfen) ) );
	$redis->hSet( hex2bin($minhexfen), 'a0a0', $ply );
}
function getMoves( $redis, $row, $update, $learn, $depth ) {
	$moves1 = getAllScores( $redis, $row );
	$moves2 = array();

	if( isset($moves1['ply']) )
	{
		if( $depth > 0 && ( $moves1['ply'] < 0 || $moves1['ply'] > $depth ) )
		{
			updatePly( $redis, $row, $depth );
			$depth++;
		}
		else
			$depth = $moves1['ply'] + 1;
	}
	else if( count( $moves1 ) > 0 && $depth > 0 )
	{
		updatePly( $redis, $row, $depth );
		$depth++;
	}
	unset( $moves1['ply'] );

	if( $update )
	{
		$knownmoves = array();
		$updatemoves = array();
		foreach( $moves1 as $key => $item ) {
			$knownmoves[$key] = 0;
			$nextfen = cbmovemake( $row, $key );
			list( $nextmoves, $variations ) = getMoves( $redis, $nextfen, false, false, $depth );
			$moves2[ $key ][0] = 0;
			$moves2[ $key ][1] = 0;
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
				$moves2[ $key ][0] = count( $nextmoves );
				$moves2[ $key ][1] = $nextcount;
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
			else if( count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) > 4 && count( cbmovegen( $nextfen ) ) > 0 )
			{
				updateQueue( $row, $key, true );
			}
		}
		if( count( $updatemoves ) > 0 )
			updateScore( $redis, $row, $updatemoves );
		$memcache_obj = new Memcache();
		$memcache_obj->pconnect('localhost', 11211);
		if( !$memcache_obj )
			throw new Exception( 'Memcache error.' );
		if( count_pieces( $row ) >= 10 && count_attackers( $row ) > 4 ) {
			$allmoves = cbmovegen( $row );
			if( count( $allmoves ) > count( $knownmoves ) ) {
				if( count( $knownmoves ) < 5 ) {
					updateSel( $row, false );
				}
				$allmoves = array_diff_key( $allmoves, $knownmoves );
				$findmoves = array();
				foreach( $allmoves as $key => $item ) {
					$findmoves[$key] = $item;
				}
				foreach( $findmoves as $key => $item ) {
					$nextfen = cbmovemake( $row, $key );
					list( $nextmoves, $variations ) = getMoves( $redis, $nextfen, false, false, $depth );
					if( count( $nextmoves ) > 0 ) {
						updateQueue( $row, $key, true );
					}
					else if( $learn ) {
						$memcache_obj->set( 'Learn2::' . $nextfen, array( $row, $key ), 0, 300 );
					}
				}
			}
		}
		$autolearn = $memcache_obj->get( 'Learn2::' . $row );
		if( $autolearn !== FALSE ) {
			$memcache_obj->delete( 'Learn2::' . $row );
			updateQueue( $autolearn[0], $autolearn[1], $learn );
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
	return array( $moves1, $moves2 );
}
function getMovesWithCheck( $redis, $row, $ply, $enumlimit, $resetlimit, $learn, $depth ) {
	$moves1 = getAllScores( $redis, $row );
	$BWfen = cbgetBWfen( $row );

	if( isset($moves1['ply']) )
	{
		if( $depth > 0 && ( $moves1['ply'] < 0 || $moves1['ply'] > $depth ) )
		{
			updatePly( $redis, $row, $depth );
			$depth++;
		}
		else
			$depth = $moves1['ply'] + 1;
	}
	else if( count( $moves1 ) > 0 && $depth > 0 )
	{
		updatePly( $redis, $row, $depth );
		$depth++;
	}
	unset( $moves1['ply'] );

	if( $GLOBALS['counter'] < $enumlimit )
	{
		$recurse = false;
		$current_hash = hash( 'md5', $row );
		$current_hash_bw = hash( 'md5', $BWfen );
		if( !isset( $GLOBALS['boardtt'][$current_hash] ) )
		{
			if( !isset( $GLOBALS['boardtt'][$current_hash_bw] ) )
			{
				$recurse = true;
			}
		}
		if( $recurse )
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
				asort( $moves1 );
				$throttle = getthrottle( end( $moves1 ) );
				$moves2 = array();
				$knownmoves = array();
				foreach( $moves1 as $key => $item ) {
					if( $ply == 0 ) {
						$knownmoves[$key] = 0;
					}
					if( ( $ply == 0 && $resetlimit && $item > -150 ) || ( $item >= $throttle || $item == end( $moves1 ) ) ) {
						$moves2[ $key ] = $item;
					}
				}
				arsort( $moves2 );

				if( $ply == 0 ) {
					$GLOBALS['movecnt'] = array();
				}
				foreach( $moves2 as $key => $item ) {
					$nextfen = cbmovemake( $row, $key );
					$GLOBALS['historytt'][$current_hash]['fen'] = $nextfen;
					$GLOBALS['historytt'][$current_hash]['move'] = $key;
					if( $resetlimit )
						$GLOBALS['counter'] = 0;
					else
						$GLOBALS['counter']++;

					if( $ply == 0 )
						$GLOBALS['counter1'] = 1;
					else
						$GLOBALS['counter1']++;

					$nextmoves = getMovesWithCheck( $redis, $nextfen, $ply + 1, $enumlimit, false, false, $depth );
					unset( $GLOBALS['historytt'][$current_hash] );
					if( isset( $GLOBALS['loopcheck'] ) ) {
						$GLOBALS['looptt'][$current_hash][$key] = $GLOBALS['loopcheck'];
						unset( $GLOBALS['loopcheck'] );
					}
					if( $ply == 0 )
						$GLOBALS['movecnt'][$key] = $GLOBALS['counter1'];

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
					else if( count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) > 4 && count( cbmovegen( $nextfen ) ) > 0 )
					{
						updateQueue( $row, $key, true );
					}
				}
				
				if( $ply == 0 ) {
					if( count( $moves2 ) > 0 ) {
						arsort( $moves2 );
						$bestscore = reset( $moves2 );
						$GLOBALS['counter2'] = 0;
						foreach( $moves2 as $key => $value ) {
							if( $value >= getbestthrottle( $bestscore ) )
								$GLOBALS['counter2'] = max( $GLOBALS['counter2'], $GLOBALS['movecnt'][$key] );
							else
								break;
						}
					}
					else
						$GLOBALS['counter2'] = 0;

					$memcache_obj = new Memcache();
					$memcache_obj->pconnect('localhost', 11211);
					if( !$memcache_obj )
						throw new Exception( 'Memcache error.' );
					if( count_pieces( $row ) >= 10 && count_attackers( $row ) > 4 ) {
						$allmoves = cbmovegen( $row );
						if( count( $allmoves ) > count( $knownmoves ) ) {
							if( count( $knownmoves ) < 5 ) {
								updateSel( $row, false );
							}
							$allmoves = array_diff_key( $allmoves, $knownmoves );
							$findmoves = array();
							foreach( $allmoves as $key => $item ) {
								$findmoves[$key] = $item;
							}
							foreach( $findmoves as $key => $item ) {
								$nextfen = cbmovemake( $row, $key );
								if( $learn ) {
									$memcache_obj->set( 'Learn2::' . $nextfen, array( $row, $key ), 0, 300 );
								}
							}
						}
					}
					$autolearn = $memcache_obj->get( 'Learn2::' . $row );
					if( $autolearn !== FALSE ) {
						$memcache_obj->delete( 'Learn2::' . $row );
						updateQueue( $autolearn[0], $autolearn[1], $learn );
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
					$loop_hash = hash( 'md5', $loopfen );
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
						if( $moves1[$key] == $bestscore && abs( $bestscore ) < 10000 ) {
							$moves1[$key] = 0;
							//if( !$isloop )
							//	$updatemoves[$key] = 0;
						}
					}
				}

				unset( $GLOBALS['looptt'][$current_hash] );
				unset( $GLOBALS['looptt'][$current_hash_bw] );
			}
			else
				$GLOBALS['boardtt'][$current_hash] = 1;

			if( count( $updatemoves ) > 0 )
				updateScore( $redis, $row, $updatemoves );
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
function getAnalysisPath( $redis, $row, $ply, $enumlimit, $isbest, $learn, $depth, &$pv ) {
	$moves1 = getAllScores( $redis, $row );
	$BWfen = cbgetBWfen( $row );

	if( isset($moves1['ply']) )
	{
		if( $depth > 0 && ( $moves1['ply'] < 0 || $moves1['ply'] > $depth ) )
		{
			updatePly( $redis, $row, $depth );
			$depth++;
		}
		else
			$depth = $moves1['ply'] + 1;
	}
	else if( count( $moves1 ) > 0 && $depth > 0 )
	{
		updatePly( $redis, $row, $depth );
		$depth++;
	}
	unset( $moves1['ply'] );

	if( $GLOBALS['counter'] < $enumlimit )
	{
		$recurse = false;
		$current_hash = hash( 'md5', $row );
		$current_hash_bw = hash( 'md5', $BWfen );
		if( !isset( $GLOBALS['boardtt'][$current_hash] ) )
		{
			if( !isset( $GLOBALS['boardtt'][$current_hash_bw] ) )
			{
				$recurse = true;
			}
		}
		if( $recurse )
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
				asort( $moves1 );
				$throttle = getthrottle( end( $moves1 ) );
				$moves2 = array();
				$knownmoves = array();
				foreach( $moves1 as $key => $item ) {
					if( $ply == 0 ) {
						$knownmoves[$key] = 0;
					}
					if( ( $ply == 0 && $item > -150 ) || ( $item >= $throttle || $item == end( $moves1 ) ) ) {
						$moves2[ $key ] = $item;
					}
				}
				arsort( $moves2 );
				foreach( $moves2 as $key => $item ) {
					$nextfen = cbmovemake( $row, $key );
					$GLOBALS['historytt'][$current_hash]['fen'] = $nextfen;
					$GLOBALS['historytt'][$current_hash]['move'] = $key;
					$GLOBALS['counter']++;

					if( $isbest ) {
						array_push( $pv, $key );
					}
					$nextmoves = getAnalysisPath( $redis, $nextfen, $ply + 1, $enumlimit, $isbest, false, $depth, $pv );
					$isbest = false;
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
					else if( count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) > 4 && count( cbmovegen( $nextfen ) ) > 0 )
					{
						updateQueue( $row, $key, true );
					}
				}
				if( $ply == 0 ) {
					$memcache_obj = new Memcache();
					$memcache_obj->pconnect('localhost', 11211);
					if( !$memcache_obj )
						throw new Exception( 'Memcache error.' );
					if( count_pieces( $row ) >= 10 && count_attackers( $row ) > 4 ) {
						$allmoves = cbmovegen( $row );
						if( count( $allmoves ) > count( $knownmoves ) ) {
							if( count( $knownmoves ) < 5 ) {
								updateSel( $row, false );
							}
							$allmoves = array_diff_key( $allmoves, $knownmoves );
							$findmoves = array();
							foreach( $allmoves as $key => $item ) {
								$findmoves[$key] = $item;
							}
							foreach( $findmoves as $key => $item ) {
								$nextfen = cbmovemake( $row, $key );
								if( $learn ) {
									$memcache_obj->set( 'Learn2::' . $nextfen, array( $row, $key ), 0, 300 );
								}
							}
						}
					}
					$autolearn = $memcache_obj->get( 'Learn2::' . $row );
					if( $autolearn !== FALSE ) {
						$memcache_obj->delete( 'Learn2::' . $row );
						updateQueue( $autolearn[0], $autolearn[1], $learn );
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
					$loop_hash = hash( 'md5', $loopfen );
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
						if( $moves1[$key] == $bestscore && abs( $bestscore ) < 10000 ) {
							$moves1[$key] = 0;
							//if( !$isloop )
							//	$updatemoves[$key] = 0;
						}
					}
				}
				unset( $GLOBALS['looptt'][$current_hash] );
				unset( $GLOBALS['looptt'][$current_hash_bw] );
			}
			else
				$GLOBALS['boardtt'][$current_hash] = 1;

			if( count( $updatemoves ) > 0 )
				updateScore( $redis, $row, $updatemoves );
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

function getEngineMove( $row, $movelist ) {
	$result = '';
	$descriptorspec = array( 0 => array("pipe", "r"),1 => array("pipe", "w") );
	$process = proc_open( '/home/apache/enginec', $descriptorspec, $pipes, NULL, NULL );
	if( is_resource( $process ) ) {
		fwrite( $pipes[0], 'position fen ' . $row);
		if( count( $movelist ) > 0 ) {
			fwrite( $pipes[0], ' moves ' . implode(' ', $movelist ) );
		}
		fwrite( $pipes[0], PHP_EOL . 'go depth 22' . PHP_EOL );
		$startTime = time();
		$readfd = array( $pipes[1] );
		$writefd = NULL;
		$errfd = NULL;
		stream_set_blocking( $pipes[1], FALSE );
		while( ( $res = stream_select( $readfd, $writefd, $errfd, 1 ) ) !== FALSE ) {
			if( $res > 0 && ( $out = fgets( $pipes[1] ) ) ) {
				if( $move = strstr( $out, 'bestmove' ) ) {
					$result = rtrim( substr( $move, 9, 5 ) );
					break;
				}
			}
			else if( time() - $startTime >= 5 ) {
				fwrite( $pipes[0], 'stop' . PHP_EOL );
			}
			$readfd = array( $pipes[1] );
		}		
		fclose( $pipes[0] );
		fclose( $pipes[1] );
		proc_close( $process );
	}
	return $result;
}

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

function is_true( $val ) {
	return ( is_string( $val ) ? filter_var( $val, FILTER_VALIDATE_BOOLEAN ) : ( bool ) $val );
}

try{
	if( !isset( $_REQUEST['action'] ) ) {
		exit(0);
	}
	$action = $_REQUEST['action'];
	if( isset( $_REQUEST['json'] ) ) {
		$isJson = is_true( $_REQUEST['json'] );
	}
	else {
		$isJson = false;
	}
	if( $isJson )
		header('Content-type: application/json');

	if( isset( $_REQUEST['board'] ) && !empty( $_REQUEST['board'] ) ) {
		if( $isJson )
			echo '{';

		$row = cbgetfen( $_REQUEST['board'] );
		if( isset( $row ) && !empty( $row ) ) {
			if( isset( $_REQUEST['endgame'] ) ) {
				$endgame = is_true( $_REQUEST['endgame'] );
			}
			else {
				$endgame = false;
			}
			if( isset( $_REQUEST['showall'] ) ) {
				$showall = is_true( $_REQUEST['showall'] );
			}
			else {
				$showall = false;
			}
			
			if( isset( $_REQUEST['learn'] ) ) {
				$learn = is_true( $_REQUEST['learn'] );
			}
			else {
				$learn = true;
			}

			if( $action == 'store' ) {
				if( isset( $_REQUEST['move'] ) && !empty( $_REQUEST['move'] ) && count_pieces( $row ) >= 10 && count_attackers( $row ) > 4 ) {
					$moves = cbmovegen( $row );
					$move = $_REQUEST['move'];
					if( isset( $moves[$move] ) && isset( $_REQUEST['score'] ) ) {
						if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) . $_REQUEST['board'] . $_REQUEST['move'] . $_REQUEST['score'] ) ) {
							if( isset( $_REQUEST['nodes'] ) ) {
								$nodes = intval($_REQUEST['nodes']);
								$memcache_obj = new Memcache();
								$memcache_obj->pconnect('localhost', 11211);
								if( !$memcache_obj )
									throw new Exception( 'Memcache error.' );
								$activelist = $memcache_obj->get( 'WorkerList2' );
								if( $activelist === FALSE ) {
									$activelist = array();
									$memcache_obj->add( 'WorkerList2', $activelist, 0, 0 );
								}
								if( !isset( $activelist[$_SERVER['REMOTE_ADDR']] ) ) {
									$activelist[$_SERVER['REMOTE_ADDR']] = 1;
									$memcache_obj->set( 'WorkerList2', $activelist, 0, 0 );
								}
								$thisminute = date('i');
								$memcache_obj->add( 'Worker2::' . $_SERVER['REMOTE_ADDR'] . 'PC_' . $thisminute, 0, 0, 150 );
								$memcache_obj->add( 'Worker2::' . $_SERVER['REMOTE_ADDR'] . 'NC_' . $thisminute, 0, 0, 150 );
								$memcache_obj->increment( 'Worker2::' . $_SERVER['REMOTE_ADDR'] . 'PC_' . $thisminute );
								$memcache_obj->increment( 'Worker2::' . $_SERVER['REMOTE_ADDR'] . 'NC_' . $thisminute, $nodes );
							}
							$score = intval($_REQUEST['score']);
							if( $score == 0 )
							{
								if( count( cbmovegen( cbmovemake( $row, $move ) ) ) == 0 )
								{
									$score = 0;
								}
							}
							else if( abs( $score ) > 10000 )
							{
								if( $score < 0 && $score > -30000 )
									$score = $score - 1;
								else if( $score > 0 && $score < 30000 )
									$score = $score + 1;
								else
									$score = 0;
							}
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							if( !scoreExists( $redis, $row, $move ) ) {
								updateScore( $redis, $row, array( $move => $score ) );
								echo 'ok';
							}
						}
						else {
							echo 'tokenerror';
							//error_log($_SERVER['REMOTE_ADDR'], 0 );
						}
					}
					else
					{
						$move = substr( $move, 5 );
						if( isset( $moves[$move] ) ) {
							$priority = false;
							if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) . $move ) ) {
								$priority = true;
							}
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							if( !scoreExists( $redis, $row, $move ) ) {
								updateQueue( $row, $move, $priority );
								echo 'ok';
							}
						}
					}
				}
			}
			else
			{
				$memcache_obj = new Memcache();
				$memcache_obj->pconnect('localhost', 11211);
				if( !$memcache_obj )
					throw new Exception( 'Memcache error.' );
				$querylimit = $memcache_obj->get( 'QLimit::' . $_SERVER['REMOTE_ADDR'] );
				if( $querylimit === FALSE || $querylimit < 100000 )
				{
					if( $action != 'querylearn' && $action != 'queue' ) {
						$memcache_obj->add( 'QLimit::' . $_SERVER['REMOTE_ADDR'], 0, 0, 86400 );
						$memcache_obj->increment( 'QLimit::' . $_SERVER['REMOTE_ADDR'] );
					}
					if( count_pieces( $row ) <= 7 && $action != 'queue' ) {
						$result = json_decode( file_get_contents( 'http://localhost:9000/standard?fen=' . urlencode( $row ) ), TRUE );
						if( $action == 'queryall' ) {
							if( $result['checkmate'] ) {
								if( $isJson )
									echo '"status":"checkmate"';
								else
									echo 'checkmate';
							}
							else if( $result['stalemate'] ) {
								if( $isJson )
									echo '"status":"stalemate"';
								else
									echo 'stalemate';
							}
							else
							{
								if( $isJson )
									echo '"status":"ok","moves":[{';
								$bestmove = reset( $result['moves'] );
								$isfirst = true;
								foreach( $result['moves'] as $move ) {
									if( !$isfirst ) {
										if( $move['wdl'] < 0 ) {
											$step = -$move['dtz'];
											if( $move['wdl'] == -1 )
												$score = 20000 - $step;
											else
												$score = 30000 - $step;
											if( $move['zeroing'] || $move['checkmate'] )
												$step = 0;
											if( $move['dtz'] == $bestmove['dtz'] && $move['zeroing'] == $bestmove['zeroing'] && $move['checkmate'] == $bestmove['checkmate'] ) {
												if( $isJson )
													echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":2,"note":"! (W-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:2,note:! (W-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
											else {
												if( $isJson )
													echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":1,"note":"* (W-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:1,note:* (W-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
										}
										else if( $move['wdl'] == 0 ) {
											$step = 0;
											$score = 0;
											if( $bestmove['wdl'] == 0 ) {
												if( $move['zeroing'] == $bestmove['zeroing'] && $move['checkmate'] == $bestmove['checkmate'] ) {
													if( $isJson )
														echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":2,"note":"! (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:2,note:! (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
												}
												else {
													if( $isJson )
														echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":1,"note":"* (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:1,note:* (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
												}
											}
											else {
												if( $isJson )
													echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":0,"note":"? (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:0,note:? (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
										}
										else {
											$step = $move['dtz'];
											if( $move['wdl'] == 1 )
												$score = $step - 20000;
											else
												$score = $step - 30000;
											if( $move['zeroing'] || $move['checkmate'] )
												$step = 0;
											if( $move['dtz'] == $bestmove['dtz'] && $move['zeroing'] == $bestmove['zeroing'] && $move['checkmate'] == $bestmove['checkmate'] ) {
												if( $isJson )
													echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":2,"note":"! (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:2,note:! (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
											else if( $bestmove['wdl'] > 0 ) {
												if( $isJson )
													echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":1,"note":"* (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:1,note:* (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
											else {
												if( $isJson )
													echo '},{"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":0,"note":"? (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo '|move:' . $move['uci'] . ',score:' . $score . ',rank:0,note:? (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
										}
									}
									else {
										$isfirst = false;
										if( $bestmove['wdl'] == 0 && $move['wdl'] == 0 ) {
											$step = 0;
											$score = 0;
											if( $isJson )
												echo '"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":2,"note":"! (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
											else
												echo 'move:' . $move['uci'] . ',score:' . $score . ',rank:2,note:! (D-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
										}
										else {
											if( $move['wdl'] < 0 ) {
												$step = -$move['dtz'];
												if( $move['wdl'] == -1 )
													$score = 20000 - $step;
												else
													$score = 30000 - $step;
												if( $move['zeroing'] || $move['checkmate'] )
													$step = 0;
												if( $isJson )
													echo '"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":2,"note":"! (W-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
												echo 'move:' . $move['uci'] . ',score:' . $score . ',rank:2,note:! (W-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
											else {
												$step = $move['dtz'];
												if( $move['wdl'] == 1 )
													$score = $step - 20000;
												else
													$score = $step - 30000;
												if( $move['zeroing'] || $move['checkmate'] )
													$step = 0;
												if( $isJson )
													echo '"uci":"' . $move['uci'] . '","san":"' . $move['san'] . '","score":' . $score . ',"rank":2,"note":"! (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo 'move:' . $move['uci'] . ',score:' . $score . ',rank:2,note:! (L-' . str_pad( $step, 4, '0', STR_PAD_LEFT ) . ')';
											}
										}
									}
								}
								if( $isJson )
									echo '}]';
							}
						}
						else if( $action == 'query' || $action == 'querybest' || $action == 'querylearn' || $action == 'querysearch' )
						{
							if( $result['checkmate'] || $result['stalemate'] ) {
								if( $isJson )
									echo '"status":"nobestmove"';
								else
									echo 'nobestmove';
							}
							else {
								$bestmove = reset( $result['moves'] );
								if( $bestmove['wdl'] != 0 ) {
									$finals = array();
									$finalcount = 0;
									foreach( $result['moves'] as $move ) {
										if( $move['dtz'] == $bestmove['dtz'] && $move['zeroing'] == $bestmove['zeroing'] && $move['checkmate'] == $bestmove['checkmate'] )
											$finals[$finalcount++] = $move['uci'];
										else
											break;
									}
									shuffle( $finals );
									if( $isJson )
										echo '"status":"ok","egtb":"' . end( $finals ) . '"';
									else
										echo 'egtb:' . end( $finals );
								}
								else if( $bestmove['wdl'] == 0 )
								{
									if( $isJson )
										echo '"status":"ok","search_moves":[{';
									$isfirst = true;
									foreach( $result['moves'] as $move ) {
										if( $move['wdl'] == 0 ) {
											if( !$isfirst ) {
												if( $isJson )
													echo '},{"uci":"' . $key . '","san":"' . $move['san'];
												else
													echo '|search:' . $move['uci'];
											}
											else {
												$isfirst = false;
												if( $isJson )
													echo '"uci":"' . $key . '","san":"' . $move['san'];
												else
													echo 'search:' . $move['uci'];
											}
										}
										else
											break;
									}
									if( $isJson )
										echo '}]';
								}
								else {
									if( $isJson )
										echo '"status":"nobestmove"';
									else
										echo 'nobestmove';
								}
							}
						}
						else if( $action == 'querypv' ) {
							if( $isJson )
								echo '"status":"unknown"';
							else
								echo 'unknown';
						}
						else if( $action == 'queryscore' ) {
							if( $result['checkmate'] || $result['stalemate'] ) {
								if( $isJson )
									echo '"status":"unknown"';
								else
									echo 'unknown';
							}
							else {
								$bestmove = reset( $result['moves'] );
								if( $bestmove['wdl'] < 0 ) {
									$step = -$bestmove['dtz'];
									if( $bestmove['wdl'] == -1 )
										$score = 20000 - $step;
									else
										$score = 30000 - $step;
								}
								else if( $bestmove['wdl'] == 0 ) {
									$score = 0;
								}
								else {
									$step = $bestmove['dtz'];
									if( $move['wdl'] == 1 )
										$score = $step - 20000;
									else
										$score = $step - 30000;
								}
								if( $isJson )
									echo '"status":"ok","eval":' . $score;
								else
									echo 'eval:' . $score;
							}
						}
					}
					else if( !$endgame || $action == 'queryall' || $action == 'queryscore' || $action == 'querypv' || $action == 'queue' ) {
						if( $action == 'querybest' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							$statmoves = getMovesWithCheck( $redis, $row, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								setOverrides( $row, $statmoves );
								if( count( $statmoves ) > 1 ) {
									$finals = array();
									$finalcount = 0;
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -50 ) {
										foreach( $statmoves as $key => $entry ) {
											if( $entry >= getbestthrottle( $maxscore ) ) {
												$finals[$finalcount++] = $key;
											}
											else
												break;
										}
										shuffle( $finals );
										if( $isJson )
											echo '"status":"ok","move":"' . end( $finals ) . '"';
										else
											echo 'move:' . end( $finals );
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
											echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -50 ) {
										if( $isJson )
											echo '"status":"ok","move":"' . end( array_keys( $statmoves ) ) . '"';
										else
											echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
											echo 'nobestmove';
									}
								}
							}
							else {
								if( $isJson )
									echo '"status":"nobestmove"';
								else
									echo 'nobestmove';
							}
						}
						else if( $action == 'query' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							$statmoves = getMovesWithCheck( $redis, $row, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								setOverrides( $row, $statmoves );
								if( count( $statmoves ) > 1 ) {
									$finals = array();
									$finalcount = 0;
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -50 ) {
										$throttle = getthrottle( $maxscore );
										foreach( $statmoves as $key => $entry ) {
											if( $entry >= $throttle ) {
												$finals[$finalcount++] = $key;
											}
											else
												break;
										}
										shuffle( $finals );
										if( $isJson )
											echo '"status":"ok","move":"' . end( $finals ) . '"';
										else
											echo 'move:' . end( $finals );
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
											echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -50 ) {
										if( $isJson )
											echo '"status":"ok","move":"' . end( array_keys( $statmoves ) ) . '"';
										else
											echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
											echo 'nobestmove';
									}
								}
							}
							else {
								if( $isJson )
									echo '"status":"nobestmove"';
								else
									echo 'nobestmove';
							}
						}
						else if( $action == 'queryall' ) {
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							list( $statmoves, $variations ) = getMoves( $redis, $row, true, $learn, 0 );
							if( count( $statmoves ) > 0 ) {
								if( $isJson )
									echo '"status":"ok","moves":[{';

								$oldscores = setOverrides( $row, $statmoves );
								arsort( $statmoves );
								$maxscore = reset( $statmoves );
								$throttle = getthrottle( $maxscore );
								$isfirst = true;

								foreach( $statmoves as $record => $score ) {
									$winrate = '';
									if( abs( $score ) < 10000 ) {
										if( $isJson )
											$winrate = ',"winrate":"' . getWinRate( $score ) . '"';
										else
											$winrate = ',winrate:' . getWinRate( $score );
									}

									if( !$isfirst && ( $learn || ( $score >= $throttle && $score >= getbestthrottle( $maxscore ) ) ) ) {
										if( $isJson )
											echo '},{';
										else
											echo '|';
									}
									if( $score >= $throttle && $score >= getbestthrottle( $maxscore ) ) {
										if( isset( $oldscores[$record] ) ) {
											if( $isJson ) {
												$score = $oldscores[$record];
												$winrate = ',"winrate":"' . getWinRate( $oldscores[$record] ) . '"';
											}
											else {
												$score = $score . ' (' . $oldscores[$record] . ')';
												$winrate = ',winrate:' . getWinRate( $oldscores[$record] );
											}
										}
										if( $isJson )
											echo '"uci":"' . $record . '","san":"' . cbmovesan( $row, array( $record ) )[0] . '","score":' . $score . ',"rank":2,"note":"! (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')"' . $winrate;
										else
											echo 'move:' . $record . ',score:' . $score . ',rank:2,note:! (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
									}
									else if( $score >= $throttle ) {
										if( $isfirst || $learn ) {
											if( isset( $oldscores[$record] ) ) {
												if( $isJson ) {
													$score = $oldscores[$record];
													$winrate = ',"winrate":"' . getWinRate( $oldscores[$record] ) . '"';
												}
												else {
													$score = $score . ' (' . $oldscores[$record] . ')';
													$winrate = ',winrate:' . getWinRate( $oldscores[$record] );
												}
											}
											if( $isJson )
												echo '"uci":"' . $record . '","san":"' . cbmovesan( $row, array( $record ) )[0] . '","score":' . $score . ',"rank":1,"note":"* (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')"' . $winrate;
											else
												echo 'move:' . $record . ',score:' . $score . ',rank:1,note:* (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
										}
										else
											unset( $statmoves[$record] );
									}
									else {
										if( $isfirst || $learn ) {
											if( isset( $oldscores[$record] ) ) {
												if( $isJson ) {
													$score = $oldscores[$record];
													$winrate = ',"winrate":"' . getWinRate( $oldscores[$record] ) . '"';
												}
												else {
													$score = $score . ' (' . $oldscores[$record] . ')';
													$winrate = ',winrate:' . getWinRate( $oldscores[$record] );
												}
											}
											if( $isJson )
												echo '"uci":"' . $record . '","san":"' . cbmovesan( $row, array( $record ) )[0] . '","score":' . $score . ',"rank":0,"note":"? (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')"' . $winrate;
											else
												echo 'move:' . $record . ',score:' . $score . ',rank:0,note:? (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
										}
										else
											unset( $statmoves[$record] );
									}
									$isfirst = false;
								}
								if( $showall || !$learn ) {
									$allmoves = cbmovegen( $row );
									foreach( $allmoves as $record => $score ) {
										if( !isset( $statmoves[$record] ) ) {
											if( $isJson )
												echo '},{"uci":"' . $record . '","san":"' . cbmovesan( $row, array( $record ) )[0] . '","score":"??","rank":0,"note":"? (??-??)"';
											else
												echo '|move:' . $record . ',score:??,rank:0,note:? (??-??)';
										}
									}
								}
								if( $isJson )
									echo '}]';
							}
							else {
								$allmoves = cbmovegen( $row );
								if( count( $allmoves ) > 0 ) {
									if( $showall ) {
										if( $isJson )
											echo '"status":"ok","moves":[{';
										$isfirst = true;
										foreach( $allmoves as $record => $score ) {
											if( !$isfirst ) {
												if( $isJson )
													echo '},{';
												else
													echo '|';
											}
											else
												$isfirst = false;
											if( $isJson )
												echo '"uci":"' . $record . '","san":"' . cbmovesan( $row, array( $record ) )[0] . '","score":"??","rank":0,"note":"? (??-??)"';
											else
												echo 'move:' . $record . ',score:??,rank:0,note:? (??-??)';
										}
										if( $isJson )
											echo '}]';
									}
									else {
										if( $isJson )
											echo '"status":"unknown"';
										else
											echo 'unknown';
									}
								}
								else {
									if( cbincheck( $row ) ) {
										if( $isJson )
											echo '"status":"checkmate"';
										else
											echo 'checkmate';
									}
									else {
										if( $isJson )
											echo '"status":"stalemate"';
										else
											echo 'stalemate';
									}
								}
							}
						}
						else if( $action == 'querylearn' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							$statmoves = getMovesWithCheck( $redis, $row, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								setOverrides( $row, $statmoves );
								if( count( $statmoves ) > 1 ) {
									$finals = array();
									$finalcount = 0;
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -75 ) {
										$throttle = getlearnthrottle( $maxscore );
										foreach( $statmoves as $key => $entry ) {
											if( $entry >= $throttle )
												$finals[$finalcount++] = $key;
											else
												break;
										}
										shuffle( $finals );
										if( $isJson )
											echo '"status":"ok","move":"' . end( $finals ) . '"';
										else
											echo 'move:' . end( $finals );
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
											echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -75 ) {
										if( $isJson )
											echo '"status":"ok","move":"' . end( array_keys( $statmoves ) ) . '"';
										else
											echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
										echo 'nobestmove';
									}
								}
							}
							else {
								if( $isJson )
									echo '"status":"nobestmove"';
								else
									echo 'nobestmove';
							}
						}
						else if( $action == 'querysearch' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							$statmoves = getMovesWithCheck( $redis, $row, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								setOverrides( $row, $statmoves );
								if( count( $statmoves ) > 1 ) {
									if( $isJson )
										echo '"status":"ok","search_moves":[{';
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -50 ) {
										$throttle = getthrottle( $maxscore );
										$isfirst = true;
										foreach( $statmoves as $key => $entry ) {
											if( $entry >= $throttle ) {
												if( !$isfirst ) {
													if( $isJson )
														echo '},{"uci":"' . $key . '","san":"' . cbmovesan( $row, array( $key ) )[0];
													else
														echo '|search:' . $key;
												}
												else {
													$isfirst = false;
													if( $isJson )
														echo '"uci":"' . $key . '","san":"' . cbmovesan( $row, array( $key ) )[0];
													else
														echo 'search:' . $key;
												}
											}
											else
												break;
										}
										if( $isJson )
											echo '}]';
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
											echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -50 ) {
										if( $isJson )
											echo '"status":"ok","move":"' . end( array_keys( $statmoves ) ) . '"';
										else
											echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										if( $isJson )
											echo '"status":"nobestmove"';
										else
											echo 'nobestmove';
									}
								}
							}
							else {
								if( $isJson )
									echo '"status":"nobestmove"';
								else
									echo 'nobestmove';
							}
						}
						else if( $action == 'querypv' ) {
							$pv = array();
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							$statmoves = getAnalysisPath( $redis, $row, 0, 50, true, $learn, 0, $pv );
							if( count( $statmoves ) > 0 ) {
								if( $isJson )
									echo '"status":"ok","score":' . $statmoves[$pv[0]] . ',"depth":' . count( $pv ) . ',"pv":["' . implode( '","', $pv ) . '"],"pvSAN":["' . implode( '","', cbmovesan( $row, $pv ) ) . '"]';
								else
									echo 'score:' . $statmoves[$pv[0]] . ',depth:' . count( $pv ) . ',pv:' . implode( '|', $pv );
							}
							else {
								if( $isJson )
									echo '"status":"unknown"';
								else
									echo 'unknown';
							}
						}
						else if( $action == 'queryscore' ) {
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							list( $statmoves, $variations ) = getMoves( $redis, $row, true, true, 0 );
							if( count( $statmoves ) > 0 ) {
								arsort( $statmoves );
								$maxscore = reset( $statmoves );
								if( $isJson )
									echo '"status":"ok","eval":' . $maxscore;
								else
									echo 'eval:' . $maxscore;
							}
							else {
								if( $isJson )
									echo '"status":"unknown"';
								else
									echo 'unknown';
							}
						}
						else if( $action == 'queue' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('localhost', 8888);
							$statmoves = getMovesWithCheck( $redis, $row, 0, 100, true, true, 0 );
							if( count( $statmoves ) >= 5 ) {
								if( $isJson )
									echo '"status":"ok"';
								else
									echo 'ok';
							}
							else if( count_pieces( $row ) >= 10 && count_attackers( $row ) > 4 && count( cbmovegen( $row ) ) > 0 ) {
								updateSel( $row, true );
								if( $isJson )
									echo '"status":"ok"';
								else
									echo 'ok';
							}
						}
						else if( $action == 'queryengine' ) {
							$movelist = array();
							$isvalid = true;
							if( isset( $_REQUEST['movelist'] ) && !empty( $_REQUEST['movelist'] ) ) {
								$movelist = explode( "|", $_REQUEST['movelist'] );
								$nextfen = $row;
								$movecount = count( $movelist );
								if( $movecount > 0 && $movecount < 2047 ) {
									foreach( $movelist as $entry ) {
										$validmoves = cbmovegen( $nextfen );
										if( isset( $validmoves[$entry] ) )
											$nextfen = cbmovemake( $nextfen, $entry );
										else {
											$isvalid = false;
											break;
										}
									}
								}
								else
									$isvalid = false;
							}
							if( $isvalid ) {
								$result = getEngineMove( $row, $movelist );
								if( !empty( $result ) ) {
									echo 'move:' . $result;
								}
								else {
									echo 'nobestmove';
								}
							}
							else
								echo 'invalid movelist';
						}
					}
				}
				else {
					//$memcache_obj->delete( 'QLimit::' . $_SERVER['REMOTE_ADDR'] );
					if( $isJson )
						echo '"status":"rate limit exceeded"';
					else
						echo 'rate limit exceeded';
				}
			}
		}
		else {
			if( $isJson )
				echo '"status":"invalid board"';
			else
				echo 'invalid board';
		}
		if( $isJson )
			echo '}';
		else
			echo "\0";
	}
	else if( $action == 'getqueue' ) {
		if( isset( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) ) {
			$memcache_obj = new Memcache();
			$memcache_obj->pconnect('localhost', 11211);
			if( !$memcache_obj )
				throw new Exception( 'Memcache error.' );
			if( $readwrite_queue->trywritelock() )
			{
				//$readwrite_queue->writelock();
				$docs = array();
				$queueout = '';
				$m = new MongoClient('mongodb://localhost');
				$collection = $m->selectDB('cdbqueue')->selectCollection('queuedb');
				$cursor = $collection->find()->sort( array( 'p' => -1 ) )->limit(20);
				foreach( $cursor as $doc ) {
					$fen = cbhexfen2fen(bin2hex($doc['_id']->bin));
					if( count_pieces( $fen ) >= 10 && count_attackers( $fen ) > 4 ) {
						$moves = array();
						foreach( $doc as $key => $item ) {
							if( $key == '_id' )
								continue;
							else if( $key == 'p' )
								continue;
							else if( $memcache_obj->add( 'QueueHistory2::' . $fen . $key, 1, 0, 300 ) )
								$moves[] = $key;
						}
						if( count( $moves ) > 0 ) {
							$queueout .= $fen . "\n";
							foreach( $moves as $move )
								$queueout .= $fen . ' moves ' . $move . "\n";
						}
					}
					$docs[] = $doc['_id'];
				}
				$cursor->reset();
				if( count( $docs ) > 0 ) {
					$collection->remove( array( '_id' => array( '$in' => $docs ) ) );
				}
				$readwrite_queue->writeunlock();
				$collection2 = $m->selectDB('cdbackqueue')->selectCollection('ackqueuedb');
				if( strlen($queueout) > 0 ) {
					$collection2->update( array( '_id' => new MongoBinData(hex2bin(hash( 'md5', $queueout ))) ), array( 'data' => $queueout, 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ), array( 'upsert' => true ) );
					echo $queueout;
				}
				else {
					$doc = $collection2->findAndModify( array( 'ts' => array( '$lt' => new MongoDate( time() - 3600 ) ) ), array( '$set' => array( 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ) ) );
					if( !empty( $doc ) && isset( $doc['data'] ) ) {
						echo $doc['data'];
					}
				}
			}
		}
		else {
			echo 'tokenerror';
			//error_log($_SERVER['REMOTE_ADDR'], 0 );
		}
	}
	else if( $action == 'ackqueue' ) {
		if( isset( $_REQUEST['key'] ) && isset( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) . $_REQUEST['key'] ) ) {
			$m = new MongoClient('mongodb://localhost');
			$collection = $m->selectDB('cdbackqueue')->selectCollection('ackqueuedb');
			$collection->remove( array( '_id' => new MongoBinData(hex2bin($_REQUEST['key'])) ) );
			echo 'ok';
		}
		else {
			echo 'tokenerror';
		}
	}
	else if( $action == 'getsel' ) {
		if( isset( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) ) {
			$memcache_obj = new Memcache();
			$memcache_obj->pconnect('localhost', 11211);
			if( !$memcache_obj )
				throw new Exception( 'Memcache error.' );
			if( $readwrite_sel->trywritelock() )
			{
				//$readwrite_sel->writelock();
				$m = new MongoClient('mongodb://localhost');
				$collection = $m->selectDB('cdbsel')->selectCollection('seldb');
				$cursor = $collection->find()->sort( array( 'p' => -1 ) )->limit(20);
				$docs = array();
				$selout = '';
				foreach( $cursor as $doc ) {
					$fen = cbhexfen2fen(bin2hex($doc['_id']->bin));
					if( count_pieces( $fen ) >= 10 && count_attackers( $fen ) > 4 && $memcache_obj->add( 'SelHistory2::' . $fen, 1, 0, 300 ) )
					{
						if( isset( $doc['p'] ) && $doc['p'] > 0 )
							$selout .= '!' . $fen . "\n";
						else
							$selout .=  $fen . "\n";
					}
					$docs[] = $doc['_id'];
				}
				$cursor->reset();
				if( count( $docs ) > 0 ) {
					$collection->remove( array( '_id' => array( '$in' => $docs ) ) );
				}
				$readwrite_sel->writeunlock();
				$collection2 = $m->selectDB('cdbacksel')->selectCollection('ackseldb');
				if( strlen($selout) > 0 ) {
					$collection2->update( array( '_id' => new MongoBinData(hex2bin(hash( 'md5', $selout ))) ), array( 'data' => $selout, 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ), array( 'upsert' => true ) );
					echo $selout;
				}
				else {
					$doc = $collection2->findAndModify( array( 'ts' => array( '$lt' => new MongoDate( time() - 3600 ) ) ), array( '$set' => array( 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ) ) );
					if( !empty( $doc ) && isset( $doc['data'] ) ) {
						echo $doc['data'];
					}
				}
			}
		}
		else {
			echo 'tokenerror';
			//error_log($_SERVER['REMOTE_ADDR'], 0 );
		}
	}
	else if( $action == 'acksel' ) {
		if( isset( $_REQUEST['key'] ) && isset( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) . $_REQUEST['key'] ) ) {
			$m = new MongoClient('mongodb://localhost');
			$collection = $m->selectDB('cdbacksel')->selectCollection('ackseldb');
			$collection->remove( array( '_id' => new MongoBinData(hex2bin($_REQUEST['key'])) ) );
			echo 'ok';
		}
		else {
			echo 'tokenerror';
		}
	}
	else if( $action == 'gettoken' && isset( $_REQUEST['key'] ) ) {
		echo hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $_REQUEST['key'] );
	}
	else if( $action == 'getip' ) {
		echo $_SERVER['REMOTE_ADDR'];
	}
	else {
		if( $isJson )
			echo '{"status":"invalid parameters"}';
		else
			echo 'invalid parameters';
	}
}
catch (MongoException $e) {
	echo 'Error: ' . $e->getMessage();
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
}
catch (RedisException $e) {
	echo 'Error: ' . $e->getMessage();
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
}
catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
}
