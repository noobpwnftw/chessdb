<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header("Access-Control-Allow-Origin: *");

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

$readwrite_queue = new MyRWLock( "ChessDBLockQueue" );
$readwrite_sel = new MyRWLock( "ChessDBLockSel" );

function getWinRate( $score ) {
	return number_format( 100 / ( 1 + exp( -$score / 330 ) ), 2 );
}
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
function count_attacker_pieces( $fen ) {
	@list( $board, $color ) = explode( " ", $fen );
	$pieces = ( $color == 'b' ? 'rncp' : 'RNCP' );
	return strlen( $board ) - strlen( str_replace( str_split( $pieces ), '', $board ) );
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
function getlearnthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore - 1;
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
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
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
					$moves[ccbgetBWmove( $key )] = -$item;
			}
		}
	}
	return $moves;
}
function countAllScores( $redis, $row ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		return $redis->hLen( hex2bin( $minhexfen ) );
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		return $redis->hLen( hex2bin( $minhexfen ) );
	}
}
function scoreExists( $redis, $row, $move ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		if( $minindex == 0 ) {
			return $redis->hExists( hex2bin( $minhexfen ), $move );
		}
		else if( $minindex == 1 ) {
			return $redis->hExists( hex2bin( $minhexfen ), ccbgetBWmove( $move ) );
		}
		else if( $minindex == 2 ) {
			return $redis->hExists( hex2bin( $minhexfen ), ccbgetLRmove( $move ) );
		}
		else {
			return $redis->hExists( hex2bin( $minhexfen ), ccbgetLRBWmove( $move ) );
		}
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		if( $minindex == 0 ) {
			if( $move != ccbgetLRmove( $move ) )
			{
				return ( $redis->hExists( hex2bin( $minhexfen ), $move ) || $redis->hExists( hex2bin( $minhexfen ), ccbgetLRmove( $move ) ) );
			}
			else {
				return $redis->hExists( hex2bin( $minhexfen ), $move );
			}
		}
		else {
			if( $move != ccbgetLRmove( $move ) )
			{
				return ( $redis->hExists( hex2bin( $minhexfen ), ccbgetBWmove( $move ) ) || $redis->hExists( hex2bin( $minhexfen ), ccbgetLRBWmove( $move ) ) );
			}
			else {
				return $redis->hExists( hex2bin( $minhexfen ), ccbgetBWmove( $move ) );
			}
		}
	}
}
function updateScore( $redis, $row, $updatemoves ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		if( $minindex == 0 ) {
			if( $redis->hMSet( hex2bin($minhexfen), $updatemoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetBWmove( $key )] = $newscore;
			}
			if( $redis->hMSet( hex2bin($minhexfen), $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
		else if( $minindex == 2 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRmove( $key )] = $newscore;
			}
			if( $redis->hMSet( hex2bin($minhexfen), $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
		else {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRBWmove( $key )] = $newscore;
			}
			if( $redis->hMSet( hex2bin($minhexfen), $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		if( $minindex == 0 ) {
			if( $redis->hMSet( hex2bin($minhexfen), $updatemoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
			foreach( $updatemoves as $key => $newscore ) {
				if( $key < ccbgetLRmove( $key ) )
				{
					if( $redis->hDel( hex2bin($minhexfen), ccbgetLRmove( $key ) ) === FALSE )
						throw new RedisException( 'Server operation error.' );
				}
			}
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetBWmove( $key )] = $newscore;
			}
			if( $redis->hMSet( hex2bin($minhexfen), $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
			foreach( array_keys( $updatemoves ) as $key ) {
				if( ccbgetBWmove( $key ) < ccbgetLRBWmove( $key ) )
				{
					if( $redis->hDel( hex2bin($minhexfen), ccbgetLRBWmove( $key ) ) === FALSE )
						throw new RedisException( 'Server operation error.' );
				}
			}
		}
	}
}
function updateQueue( $row, $key, $priority ) {
	global $readwrite_queue;
	$m = new MongoClient('mongodb://localhost');
	$collection = $m->selectDB('ccdbqueue')->selectCollection('queuedb');
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		if( $minindex == 0 ) {
			$readwrite_queue->readlock();
			do {
				try {
					$tryAgain = false;
					if( $priority ) {
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, $key => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
					} else {
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( $key => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
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
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, ccbgetBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
					} else {
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( ccbgetBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
					}
				}
				catch( MongoDuplicateKeyException $e ) {
					$tryAgain = true;
				}
			} while($tryAgain);
			$readwrite_queue->readunlock();
		}
		else if( $minindex == 2 ) {
			$readwrite_queue->readlock();
			do {
				try {
					$tryAgain = false;
					if( $priority ) {
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, ccbgetLRmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
					} else {
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( ccbgetLRmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
					}
				}
				catch( MongoDuplicateKeyException $e ) {
					$tryAgain = true;
				}
			} while($tryAgain);
			$readwrite_queue->readunlock();
		}
		else {
			$readwrite_queue->readlock();
			do {
				try {
					$tryAgain = false;
					if( $priority ) {
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, ccbgetLRBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
					} else {
						$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( ccbgetLRBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
					}
				}
				catch( MongoDuplicateKeyException $e ) {
					$tryAgain = true;
				}
			} while($tryAgain);
			$readwrite_queue->readunlock();
		}
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		if( $minindex == 0 ) {
			if( $key != ccbgetLRmove( $key ) ) {
				$readwrite_queue->readlock();
				do {
					try {
						$tryAgain = false;
						if( $priority ) {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$unset' => array( ccbgetLRmove( $key ) => 0 ), '$set' => array( 'p' => 1, $key => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						} else {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$unset' => array( ccbgetLRmove( $key ) => 0 ), '$set' => array( $key => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						}
					}
					catch( MongoDuplicateKeyException $e ) {
						$tryAgain = true;
					}
				} while($tryAgain);
				$readwrite_queue->readunlock();
			}
			else {
				$readwrite_queue->readlock();
				do {
					try {
						$tryAgain = false;
						if( $priority ) {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, $key => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						} else {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( $key => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						}
					}
					catch( MongoDuplicateKeyException $e ) {
						$tryAgain = true;
					}
				} while($tryAgain);
				$readwrite_queue->readunlock();
			}
		}
		else if( $minindex == 1 ) {
			if( $key != ccbgetLRmove( $key ) ) {
				$readwrite_queue->readlock();
				do {
					try {
						$tryAgain = false;
						if( $priority ) {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$unset' => array( ccbgetLRBWmove( $key ) => 0 ), '$set' => array( 'p' => 1, ccbgetBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						} else {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$unset' => array( ccbgetLRBWmove( $key ) => 0 ), '$set' => array( ccbgetBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						}
					}
					catch( MongoDuplicateKeyException $e ) {
						$tryAgain = true;
					}
				} while($tryAgain);
				$readwrite_queue->readunlock();
			}
			else {
				$readwrite_queue->readlock();
				do {
					try {
						$tryAgain = false;
						if( $priority ) {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( 'p' => 1, ccbgetBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						} else {
							$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), array( '$set' => array( ccbgetBWmove( $key ) => 0, 'e' => new MongoDate() ) ), array( 'upsert' => true ) );
						}
					}
					catch( MongoDuplicateKeyException $e ) {
						$tryAgain = true;
					}
				} while($tryAgain);
				$readwrite_queue->readunlock();
			}
		}
	}
}
function updateSel( $row, $priority ) {
	global $readwrite_sel;
	$m = new MongoClient('mongodb://localhost');
	$collection = $m->selectDB('ccdbsel')->selectCollection('seldb');
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		if( $priority ) {
			$doc = array( '$set' => array( 'p' => 1, 'e' => new MongoDate() ) );
		} else {
			$doc = array( 'e' => new MongoDate() );
		}
		$readwrite_sel->readlock();
		do {
			try {
				$tryAgain = false;
				$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), $doc, array( 'upsert' => true ) );
			}
			catch( MongoDuplicateKeyException $e ) {
				$tryAgain = true;
			}
		} while($tryAgain);
		$readwrite_sel->readunlock();
	}
	else {
		list( $minhexfen, $minindex ) = getHexFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		if( $priority ) {
			$doc = array( '$set' => array( 'p' => 1, 'e' => new MongoDate() ) );
		} else {
			$doc = array( 'e' => new MongoDate() );
		}
		$readwrite_sel->readlock();
		do {
			try {
				$tryAgain = false;
				$collection->update( array( '_id' => new MongoBinData(hex2bin($minhexfen)) ), $doc, array( 'upsert' => true ) );
			}
			catch( MongoDuplicateKeyException $e ) {
				$tryAgain = true;
			}
		} while($tryAgain);
		$readwrite_sel->readunlock();
	}
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
function getMoves( $redis, $row, $banmoves, $update, $mirror, $learn, $depth ) {
	$hasLRmirror = ( $row == ccbgetLRfen( $row ) ? false : true );
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
			if( !$hasLRmirror && $key != ccbgetLRmove( $key ) ) {
				$knownmoves[ccbgetLRmove( $key )] = 0;
			}
			$nextfen = ccbmovemake( $row, $key );
			list( $nextmoves, $variations ) = getMoves( $redis, $nextfen, array(), false, false, false, $depth );
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
			else if( count( ccbmovegen( $nextfen ) ) == 0 )
			{
				$nextscore = -30000;
				if( $item != -$nextscore ) {
					$moves1[ $key ] = -$nextscore;
					$updatemoves[ $key ] = $nextscore;
				}
			}
			else if( count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) >= 4 )
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
		if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
			$allmoves = ccbmovegen( $row );
			if( count( $allmoves ) > count( $knownmoves ) ) {
				if( count( $knownmoves ) < 5 ) {
					updateSel( $row, false );
				}
				$allmoves = array_diff_key( $allmoves, $knownmoves );
				$findmoves = array();
				foreach( $allmoves as $key => $item ) {
					if( !$hasLRmirror && $key != ccbgetLRmove( $key ) && isset( $findmoves[ccbgetLRmove( $key )] ) )
						continue;
					$findmoves[$key] = $item;
				}
				foreach( $findmoves as $key => $item ) {
					$nextfen = ccbmovemake( $row, $key );
					list( $nextmoves, $variations ) = getMoves( $redis, $nextfen, array(), false, false, false, $depth );
					if( count( $nextmoves ) > 0 ) {
						updateQueue( $row, $key, true );
					}
					else if( $learn ) {
						$memcache_obj->set( 'Learn::' . $nextfen, array( $row, $key ), 0, 300 );
					}
				}
			}
		}
		$autolearn = $memcache_obj->get( 'Learn::' . $row );
		if( $autolearn !== FALSE ) {
			$memcache_obj->delete( 'Learn::' . $row );
			updateQueue( $autolearn[0], $autolearn[1], $learn );
		}
	}

	if( $mirror && !$hasLRmirror ) {
		foreach( $moves1 as $key => $item ) {
			if( $key != ccbgetLRmove( $key ) )
				$moves1[ ccbgetLRmove( $key ) ] = $item;
		}
		foreach( $moves2 as $key => $item ) {
			if( $key != ccbgetLRmove( $key ) )
				$moves2[ ccbgetLRmove( $key ) ] = $item;
		}
	}

	$moves1 = array_diff_key( $moves1, $banmoves );
	$moves2 = array_diff_key( $moves2, $banmoves );

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
function getMovesWithCheck( $redis, $row, $banmoves, $ply, $enumlimit, $resetlimit, $learn, $depth ) {
	$moves1 = getAllScores( $redis, $row );
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror )
		$LRBWfen = ccbgetLRfen( $BWfen );

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
		if( $hasLRmirror )
		{
			$current_hash_lr = hash( 'md5', $LRfen );
			$current_hash_lrbw = hash( 'md5', $LRBWfen );
		}
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
		if( $recurse )
		{
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
				asort( $moves1 );
				$throttle = getthrottle( end( $moves1 ) );
				$moves2 = array();
				$knownmoves = array();
				foreach( $moves1 as $key => $item ) {
					if( $ply == 0 ) {
						$knownmoves[$key] = 0;
						if( !$hasLRmirror && $key != ccbgetLRmove( $key ) ) {
							$knownmoves[ccbgetLRmove( $key )] = 0;
						}
					}
					if( ( $ply == 0 && $resetlimit && $item > -150 ) || ( $item >= $throttle || $item == end( $moves1 ) ) ) {
						$moves2[ $key ] = $item;
					}
				}
				shuffle_assoc( $moves2 );
				arsort( $moves2 );

				if( $ply == 0 ) {
					$GLOBALS['movecnt'] = array();
				}
				foreach( $moves2 as $key => $item ) {
					$nextfen = ccbmovemake( $row, $key );
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

					$nextmoves = getMovesWithCheck( $redis, $nextfen, array(), $ply + 1, $enumlimit, false, false, $depth );
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
						if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
							$allmoves = ccbmovegen( $row );
							if( count( $allmoves ) > count( $nextmoves ) ) {
								if( count( $nextmoves ) < 5 ) {
									updateSel( $row, false );
								}
							}
						}
					}
					else if( count( ccbmovegen( $nextfen ) ) == 0 )
					{
						$nextscore = -30000;
						if( $item != -$nextscore ) {
							$moves1[ $key ] = -$nextscore;
							$updatemoves[ $key ] = $nextscore;
						}
					}
					else if( $ply == 0 || (count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) >= 4) )
					{
						if( $ply == 0 )
						{
							updateQueue( $row, $key, true );
						}
						else {
							updateQueue( $row, $key, false );
						}
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
					if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
						$allmoves = ccbmovegen( $row );
						if( count( $allmoves ) > count( $knownmoves ) ) {
							$allmoves = array_diff_key( $allmoves, $knownmoves );
							$findmoves = array();
							foreach( $allmoves as $key => $item ) {
								if( !$hasLRmirror && $key != ccbgetLRmove( $key ) && isset( $findmoves[ccbgetLRmove( $key )] ) )
									continue;
								$findmoves[$key] = $item;
							}
							foreach( $findmoves as $key => $item ) {
								$nextfen = ccbmovemake( $row, $key );
								if( $learn ) {
									$memcache_obj->set( 'Learn::' . $nextfen, array( $row, $key ), 0, 300 );
								}
							}
						}
					}
					$autolearn = $memcache_obj->get( 'Learn::' . $row );
					if( $autolearn !== FALSE ) {
						$memcache_obj->delete( 'Learn::' . $row );
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
				while( $loop_hash != $current_hash && $loop_hash != $current_hash_bw && ( !$hasLRmirror || ( $hasLRmirror && $loop_hash != $current_hash_lr && $loop_hash != $current_hash_lrbw ) ) );
				$loopstatus = ccbrulecheck( $loop_fen_start, $loopmoves );
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
			}
			else if( !$isloop )
				$GLOBALS['boardtt'][$current_hash] = 1;

			if( count( $updatemoves ) > 0 )
				updateScore( $redis, $row, $updatemoves );
		}
	}

	$moves1 = array_diff_key( $moves1, $banmoves );

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
function getAnalysisPath( $redis, $row, $banmoves, $ply, $enumlimit, $isbest, $learn, $depth, &$pv ) {
	$moves1 = getAllScores( $redis, $row );
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror )
		$LRBWfen = ccbgetLRfen( $BWfen );

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
		if( $hasLRmirror )
		{
			$current_hash_lr = hash( 'md5', $LRfen );
			$current_hash_lrbw = hash( 'md5', $LRBWfen );
		}
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
		if( $recurse )
		{
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
				asort( $moves1 );
				$throttle = getthrottle( end( $moves1 ) );
				$moves2 = array();
				$knownmoves = array();
				foreach( $moves1 as $key => $item ) {
					if( $ply == 0 ) {
						$knownmoves[$key] = 0;
						if( !$hasLRmirror && $key != ccbgetLRmove( $key ) ) {
							$knownmoves[ccbgetLRmove( $key )] = 0;
						}
					}
					if( ( $ply == 0 && $item > -150 ) || ( $item >= $throttle || $item == end( $moves1 ) ) ) {
						$moves2[ $key ] = $item;
					}
				}
				shuffle_assoc( $moves2 );
				arsort( $moves2 );
				foreach( $moves2 as $key => $item ) {
					$nextfen = ccbmovemake( $row, $key );
					$GLOBALS['historytt'][$current_hash]['fen'] = $nextfen;
					$GLOBALS['historytt'][$current_hash]['move'] = $key;
					$GLOBALS['counter']++;

					if( $isbest ) {
						array_push( $pv, $key );
					}
					$nextmoves = getAnalysisPath( $redis, $nextfen, array(), $ply + 1, $enumlimit, $isbest, false, $depth, $pv );
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
						if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
							$allmoves = ccbmovegen( $row );
							if( count( $allmoves ) > count( $nextmoves ) ) {
								if( count( $nextmoves ) < 5 ) {
									updateSel( $row, false );
								}
							}
						}
					}
					else if( count( ccbmovegen( $nextfen ) ) == 0 )
					{
						$nextscore = -30000;
						if( $item != -$nextscore ) {
							$moves1[ $key ] = -$nextscore;
							$updatemoves[ $key ] = $nextscore;
						}
					}
					else if( $ply == 0 || (count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) >= 4) )
					{
						if( $ply == 0 )
						{
							updateQueue( $row, $key, true );
						}
						else {
							updateQueue( $row, $key, false );
						}
					}
				}
				if( $ply == 0 ) {
					$memcache_obj = new Memcache();
					$memcache_obj->pconnect('localhost', 11211);
					if( !$memcache_obj )
						throw new Exception( 'Memcache error.' );
					if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
						$allmoves = ccbmovegen( $row );
						if( count( $allmoves ) > count( $knownmoves ) ) {
							$allmoves = array_diff_key( $allmoves, $knownmoves );
							$findmoves = array();
							foreach( $allmoves as $key => $item ) {
								if( !$hasLRmirror && $key != ccbgetLRmove( $key ) && isset( $findmoves[ccbgetLRmove( $key )] ) )
									continue;
								$findmoves[$key] = $item;
							}
							foreach( $findmoves as $key => $item ) {
								$nextfen = ccbmovemake( $row, $key );
								if( $learn ) {
									$memcache_obj->set( 'Learn::' . $nextfen, array( $row, $key ), 0, 300 );
								}
							}
						}
					}
					$autolearn = $memcache_obj->get( 'Learn::' . $row );
					if( $autolearn !== FALSE ) {
						$memcache_obj->delete( 'Learn::' . $row );
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
				while( $loop_hash != $current_hash && $loop_hash != $current_hash_bw && ( !$hasLRmirror || ( $hasLRmirror && $loop_hash != $current_hash_lr && $loop_hash != $current_hash_lrbw ) ) );
				$loopstatus = ccbrulecheck( $loop_fen_start, $loopmoves );
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
			}
			else if( !$isloop )
				$GLOBALS['boardtt'][$current_hash] = 1;

			if( count( $updatemoves ) > 0 )
				updateScore( $redis, $row, $updatemoves );
		}
	}

	$moves1 = array_diff_key( $moves1, $banmoves );

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

function getEngineMove( $row, $movelist, $maxtime ) {
	$result = '';
	$descriptorspec = array( 0 => array("pipe", "r"),1 => array("pipe", "w") );
	$process = proc_open( '/home/apache/engine', $descriptorspec, $pipes, NULL, NULL );
	if( is_resource( $process ) ) {
		fwrite( $pipes[0], 'fen ' . $row);
		if( count( $movelist ) > 0 ) {
			fwrite( $pipes[0], ' moves ' . implode(' ', $movelist ) );
		}
		fwrite( $pipes[0], PHP_EOL . 'go' . PHP_EOL );
		$startTime = time();
		$readfd = array( $pipes[1] );
		$writefd = NULL;
		$errfd = NULL;
		stream_set_blocking( $pipes[1], FALSE );
		while( ( $res = stream_select( $readfd, $writefd, $errfd, 1 ) ) !== FALSE ) {
			if( $res > 0 && ( $out = fgets( $pipes[1] ) ) ) {
				if( $move = strstr( $out, 'bestmove' ) ) {
					$result = substr( $move, 9, 4 );
					break;
				}
			}
			else if( time() - $startTime >= $maxtime ) {
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

function dtccmp($a, $b) {
	if( $GLOBALS['score'] != 0 ) {
		if( ( $a['order'] == $GLOBALS['order'] && $a['score'] >= $GLOBALS['score'] ) || ( $b['order'] == $GLOBALS['order'] && $b['score'] >= $GLOBALS['score'] ) ) {
			if( $a['order'] != $b['order'] )
				return $a['order'] == $GLOBALS['order'] ? -1 : 1;
			else if( $a['cap'] != $b['cap'] )
				return $a['score'] >= $GLOBALS['score'] ? -1 : 1;
		}
	}
	if( $a['cap'] == $b['cap'] ) {
		if( $a['order'] == $b['order'] ) {
			if( $a['score'] == $b['score'] )
			{
				if( $a['check'] != $b['check'] )
					return ( $a['check'] > $b['check'] ) ? 1 : -1;
				return 0;
			}
			return ( $a['score'] > $b['score'] ) ? -1 : 1;
		}
		if( ( $a['score'] >= 0 && $b['score'] <= 0 ) || ( $a['score'] <= 0 && $b['score'] >= 0 ) ) {
			if( $a['score'] == $b['score'] )
				return ( $a['order'] > $b['order'] ) ? -1 : 1;
			return ( $a['score'] > $b['score'] ) ? -1 : 1;
		}
		if( $a['score'] > 0 )
			return ( $a['order'] > $b['order'] ) ? 1 : -1;
		else
			return ( $a['order'] > $b['order'] ) ? -1 : 1;
	}
	if( ( $a['score'] >= 0 && $b['score'] <= 0 ) || ( $a['score'] <= 0 && $b['score'] >= 0 ) ) {
		if( $a['score'] == $b['score'] )
		{
			if( $a['check'] != $b['check'] )
				return ( $a['check'] > $b['check'] ) ? 1 : -1;
			return $a['cap'] ? ( ( $a['check'] == 0 ) ? 1 : -1 ) : ( ( $a['check'] == 0 ) ? -1 : 1 );
		}
		return ( $a['score'] > $b['score'] ) ? -1 : 1;
	}
	if( $a['score'] > 0 )
		return $a['cap'] ? -1 : 1;
	else
		return $a['cap'] ? 1 : -1;
}
function dtmcmp($a, $b) {
	if( $GLOBALS['score'] < 0 ) {
		if( ( $a['score'] >= $GLOBALS['score'] && $b['score'] >= $GLOBALS['score'] - 2 ) || ( $b['score'] >= $GLOBALS['score'] && $a['score'] >= $GLOBALS['score'] - 2 ) ) {
			return $a['score'] >= $GLOBALS['score'] ? 1 : -1;
		}
	}
	if( $a['score'] == $b['score'] ) {
		if( $a['cap'] == $b['cap'] ) {
			if( $a['check'] != $b['check'] ) {
				if( $a['score'] > 0 )
					return ( $a['check'] > $b['check'] ) ? 1 : -1;
				else
					return ( $a['check'] > $b['check'] ) ? ( ( $a['check'] == 0 ) ? -1 : 1 ) : ( ( $b['check'] == 0 ) ? 1 : -1 );
			}
			return 0;
		}
		if( $a['score'] > 0 )
			return $a['cap'] ? -1 : 1;
		else {
			if( $a['check'] != $b['check'] )
				return ( $a['check'] > $b['check'] ) ? ( ( $a['check'] == 0 ) ? -1 : 1 ) : ( ( $b['check'] == 0 ) ? 1 : -1 );
			return $a['cap'] ? ( ( $a['check'] == 0 ) ? 1 : -1 ) : ( ( $a['check'] == 0 ) ? -1 : 1 );
		}
	}
	return ( $a['score'] > $b['score'] ) ? -1 : 1;
}

function is_true( $val ) {
	return ( is_string( $val ) ? filter_var( $val, FILTER_VALIDATE_BOOLEAN ) : ( bool ) $val );
}

try{
	if( !isset( $_REQUEST['action'] ) ) {
		exit(0);
	}
	$action = $_REQUEST['action'];

	if( isset( $_REQUEST['board'] ) && !empty( $_REQUEST['board'] ) ) {
		$row = ccbgetfen( $_REQUEST['board'] );
		if( isset( $row ) && !empty( $row ) ) {
			$banmoves = array();
			if( isset( $_REQUEST['ban'] ) && !empty( $_REQUEST['ban'] ) ) {
				$banlist = explode( "|", $_REQUEST['ban'] );
				foreach( $banlist as $key => $entry ) {
					$banmoves[substr( $entry, 5 )] = 0;
				}
			}
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
			if( isset( $_REQUEST['egtbmetric'] ) ) {
				$dtmtb = strcasecmp( $_REQUEST['egtbmetric'], 'dtc' ) == 0 ? false : true;
			}
			else {
				$dtmtb = true;
			}

			if( isset( $_REQUEST['learn'] ) ) {
				$learn = is_true( $_REQUEST['learn'] );
			}
			else {
				$learn = true;
			}

			if( $action == 'store' ) {
				if( isset( $_REQUEST['move'] ) && !empty( $_REQUEST['move'] ) && count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
					$moves = ccbmovegen( $row );
					$move = $_REQUEST['move'];
					if( isset( $moves[$move] ) && isset( $_REQUEST['score'] ) ) {
						if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) . $row . $_REQUEST['move'] . $_REQUEST['score'] ) ) {
							if( isset( $_REQUEST['nodes'] ) ) {
								$nodes = intval($_REQUEST['nodes']);
								$memcache_obj = new Memcache();
								$memcache_obj->pconnect('localhost', 11211);
								if( !$memcache_obj )
									throw new Exception( 'Memcache error.' );
								$thisminute = date('i');
								$memcache_obj->add( 'Worker::' . $_SERVER['REMOTE_ADDR'] . 'NC_' . $thisminute, 0, 0, 150 );
								$memcache_obj->increment( 'Worker::' . $_SERVER['REMOTE_ADDR'] . 'NC_' . $thisminute, $nodes );
							}
							$score = intval($_REQUEST['score']);
							if( $score == 0 )
							{
								if( count( ccbmovegen( ccbmovemake( $row, $move ) ) ) == 0 )
								{
									$score = -30000;
								}
							}
							else if( abs( $score ) > 10000 )
							{
								if( $score < 0 && $score > -30000 )
									$score = $score - 1;
								else if( $score > 0 && $score < 30000 )
									$score = $score + 1;
							}
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							if( !scoreExists( $redis, $row, $move ) || countAllScores( $redis, ccbmovemake( $row, $move ) ) == 0 ) {
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
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							if( !scoreExists( $redis, $row, $move ) || countAllScores( $redis, ccbmovemake( $row, $move ) ) == 0 ) {
								updateQueue( $row, $move, $priority );
								echo 'ok';
							}
						}
					}
				}
			}
			else if( $action == 'rulecheck' ) {
				if( isset( $_REQUEST['movelist'] ) && !empty( $_REQUEST['movelist'] ) ) {
					$movelist = explode( "|", $_REQUEST['movelist'] );
					if( count( $movelist ) < 2048 )
						echo ccbrulecheck( $row, $movelist, true );
				}
			}
			else if( $action == 'queryrule' ) {
				if( isset( $_REQUEST['reptimes'] ) && !empty( $_REQUEST['reptimes'] ) ) {
					$reptimes = intval( $_REQUEST['reptimes'] );
					if( $reptimes < 1 || $reptimes > 10 ) {
						$reptimes = 1;
					}
				}
				else {
					$reptimes = 1;
				}
				if( isset( $_REQUEST['movelist'] ) && !empty( $_REQUEST['movelist'] ) ) {
					$movelist = explode( "|", $_REQUEST['movelist'] );
					$nextfen = $row;
					$isvalid = true;
					$movecount = count( $movelist );
					if( $movecount >= 3 && $movecount < 2047 ) {
						foreach( $movelist as $entry ) {
							$validmoves = ccbmovegen( $nextfen );
							if( isset( $validmoves[$entry] ) )
								$nextfen = ccbmovemake( $nextfen, $entry );
							else {
								$isvalid = false;
								break;
							}
						}
					}
					else
						$isvalid = false;

					if( $isvalid ) {
						$allmoves = ccbmovegen( $nextfen );
						if( count( $allmoves ) > 0 ) {
							$isfirst = true;
							foreach( $allmoves as $key => $entry ) {
								array_push( $movelist, $key );
								$ruleresult = ccbrulecheck( $row, $movelist, false, $reptimes );
								array_pop( $movelist );
								$rulestr = 'none';
								if( $ruleresult == 1 ) {
									$rulestr = 'draw';
								}
								else if( $ruleresult == 3 ) {
									$rulestr = 'ban';
								}
								if( !$isfirst )
									echo '|move:' . $key . ',rule:' . $rulestr;
								else {
									echo 'move:' . $key . ',rule:' . $rulestr;
									$isfirst = false;
								}
							}
						}
						else {
							if( ccbincheck( $nextfen ) )
								echo 'checkmate';
							else
								echo 'stalemate';
						}
					}
					else
						echo 'invalid movelist';
				}
			}
			else
			{
				$memcache_obj = new Memcache();
				$memcache_obj->pconnect('localhost', 11211);
				if( !$memcache_obj )
					throw new Exception( 'Memcache error.' );
				$querylimit = $memcache_obj->get( 'QLimit::' . $_SERVER['REMOTE_ADDR'] );
				if( $querylimit === FALSE || $querylimit < 1000000 )
				{
					if( $action != 'querylearn' && $action != 'queue' ) {
						$memcache_obj->add( 'QLimit::' . $_SERVER['REMOTE_ADDR'], 0, 0, 86400 );
						$memcache_obj->increment( 'QLimit::' . $_SERVER['REMOTE_ADDR'] );
					}
					if( $dtmtb )
						$egtbresult = $memcache_obj->get( 'EGTB_DTM::' . $row );
					else
						$egtbresult = $memcache_obj->get( 'EGTB_DTC::' . $row );
					if( $egtbresult === FALSE && $action != 'queue' ) {
//						$egtblock = new MyRWLock( "EGTBLock" );
//						$egtblock->writelock();
						$egtbresult = ccegtbprobe( $row, $dtmtb );
//						$egtblock->writeunlock();
						if( $egtbresult !== FALSE ) {
							if( $dtmtb )
								$memcache_obj->add( 'EGTB_DTM::' . $row, $egtbresult, 0, 30 );
							else
								$memcache_obj->add( 'EGTB_DTC::' . $row, $egtbresult, 0, 30 );
						}
					}
					if( $egtbresult !== FALSE ) {
						if( !$dtmtb ) {
							$GLOBALS['order'] = array_pop( $egtbresult );
						}
						else {
							$GLOBALS['check'] = array_pop( $egtbresult );
						}
						$GLOBALS['score'] = array_pop( $egtbresult );

						$moves = array_diff_key( $egtbresult, $banmoves );

						if( $GLOBALS['score'] == 0 ) {
							foreach( array_keys( $moves ) as $record ) {
								$moves[$record]['check'] += ccbruleischase( $row, $record );
							}
						}
						if( $dtmtb )
							uasort( $moves , 'dtmcmp' );
						else
							uasort( $moves , 'dtccmp' );

						if( $action == 'queryall' ) {
							if( count( $moves ) > 0 ) {
								$bestmove = reset( $moves );
								$isfirst = true;
								if( $dtmtb ) {
									foreach( array_keys( $moves ) as $record ) {
										if( !$isfirst ) {
											if( $moves[$record]['score'] > 0 ) {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												else
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
											}
											else if( $moves[$record]['score'] == 0 ) {
												if( $bestmove['score'] == 0 ) {
													if( $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
												else
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
											}
											else {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												else if( $bestmove['score'] < 0 )
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												else
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
											}
										}
										else {
											$isfirst = false;
											if( $bestmove['score'] == 0 && $moves[$record]['score'] == 0 )
												echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
											else
												if( $moves[$record]['score'] > 0 )
													echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												else
													echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
										}
									}
								}
								else {
									foreach( array_keys( $moves ) as $record ) {
										if( !$isfirst ) {
											if( $moves[$record]['score'] > 0 ) {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['order'] == $bestmove['order'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												else
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
											}
											else if( $moves[$record]['score'] == 0 ) {
												if( $bestmove['score'] == 0 ) {
													if( $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )

														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:* (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
												else
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
											}
											else {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['order'] == $bestmove['order'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												else if( $bestmove['score'] < 0 )
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												else
													echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
											}
										}
										else {
											$isfirst = false;
											if( $bestmove['score'] == 0 && $moves[$record]['score'] == 0 )
												echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:* (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
											else
												if( $moves[$record]['score'] > 0 )
													echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												else
													echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
										}
									}
								}
							}
							else {
								$allmoves = ccbmovegen( $row );
								if( count( $allmoves ) > 0 )
									echo 'unknown';
								else {
									if( ccbincheck( $row ) )
										echo 'checkmate';
									else
										echo 'stalemate';
								}
							}
						}
						else if( $action == 'query' || $action == 'querybest' || $action == 'querylearn' || $action == 'querysearch' )
						{
							if( count( $moves ) > 0 ) {
								$bestmove = reset( $moves );
								if( $bestmove['score'] != 0 || ( $bestmove['score'] == 0 && count_attacker_pieces( $row ) == 0 ) ) {
									$finals = array();
									$finalcount = 0;
									if( $dtmtb ) {
										foreach( array_keys( $moves ) as $record ) {
											if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )
												$finals[$finalcount++] = $record;
											else
												break;
										}
									}
									else {
										foreach( array_keys( $moves ) as $record ) {
											if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['order'] == $bestmove['order'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] )
												$finals[$finalcount++] = $record;
											else
												break;
										}
									}
									shuffle( $finals );
									echo 'egtb:' . end( $finals );
								}
								else if( $bestmove['score'] == 0 )
								{
									//if( $dtmtb ) {
									//	echo 'move:' . reset( array_keys( $moves ) );
									//}
									//else {
										$isfirst = true;
										foreach( $moves as $key => $entry ) {
											if( $entry['score'] == 0 ) {
												if( !$isfirst ) {
													echo '|search:' . $key;
												}
												else {
													$isfirst = false;
													echo 'search:' . $key;
												}
											}
											else
												break;
										}
									//}
								}
								else
									echo 'nobestmove';
							}
							else
								echo 'nobestmove';
						}
						else if( $action == 'querypv' ) {
							if( count( $moves ) > 0 ) {
								$bestmove = reset( $moves );
								echo 'score:' . $bestmove['score'] . ',depth:' . $bestmove['step'] . ',pv:' . key( $moves );
							}
							else
								echo 'unknown';
						}
						else if( $action == 'queryscore' ) {
							if( count( $moves ) > 0 ) {
								$bestmove = reset( $moves );
								echo 'eval:' . $bestmove['score'];
							}
							else
								echo 'unknown';
						}
					}
					else if( !$endgame || $action == 'queryall' || $action == 'queryscore' || $action == 'querypv' || $action == 'queue' ) {
						if( $action == 'querybest' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
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
										echo 'move:' . end( $finals );
									}
									else {
										echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -50 ) {
										echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										echo 'nobestmove';
									}
								}
							}
							else {
								echo 'nobestmove';
							}
						}
						else if( $action == 'query' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
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
										echo 'move:' . end( $finals );
									}
									else {
										echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -50 ) {
										echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										echo 'nobestmove';
									}
								}
							}
							else {
								echo 'nobestmove';
							}
						}
						else if( $action == 'queryall' ) {
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							list( $statmoves, $variations ) = getMoves( $redis, $row, $banmoves, true, true, $learn, 0 );
							if( count( $statmoves ) > 0 ) {
								uksort( $statmoves, function ( $a, $b ) use ( $statmoves, $variations ) {
									if( $statmoves[$a] != $statmoves[$b] ) {
										return $statmoves[$b] - $statmoves[$a];
									} else {
										return $variations[$a][1] - $variations[$b][1];
									}
								} );
								$maxscore = reset( $statmoves );
								$throttle = getthrottle( $maxscore );
								$isfirst = true;
								foreach( $statmoves as $record => $score ) {
									$winrate = '';
									if( abs( $score ) < 10000 )
										$winrate = ',winrate:' . getWinRate( $score );

									if( !$isfirst && ( $learn || ( $score >= $throttle && $score >= getbestthrottle( $maxscore ) ) ) )
										echo '|';
									if( $score >= $throttle && $score >= getbestthrottle( $maxscore ) ) {
										echo 'move:' . $record . ',score:' . $score . ',rank:2,note:! (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
									}
									else if( $score >= $throttle ) {
										if( $isfirst || $learn ) {
											echo 'move:' . $record . ',score:' . $score . ',rank:1,note:* (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
										}
										else
											unset( $statmoves[$record] );
									}
									else {
										if( $isfirst || $learn ) {
											echo 'move:' . $record . ',score:' . $score . ',rank:0,note:? (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
										}
										else
											unset( $statmoves[$record] );
									}
									$isfirst = false;
								}
								if( $showall || !$learn ) {
									$allmoves = ccbmovegen( $row );
									foreach( $allmoves as $record => $score ) {
										if( !isset( $statmoves[$record] ) ) {
											echo '|move:' . $record . ',score:??,rank:0,note:? (??-??)';
										}
									}
								}
							}
							else {
								$allmoves = ccbmovegen( $row );
								if( count( $allmoves ) > 0 ) {
									if( $showall ) {
										$isfirst = true;
										foreach( $allmoves as $record => $score ) {
											if( !$isfirst )
												echo '|';
											else
												$isfirst = false;
											echo 'move:' . $record . ',score:??,rank:0,note:? (??-??)';
										}
									}
									else
										echo 'unknown';
								}
								else {
									if( ccbincheck( $row ) )
										echo 'checkmate';
									else
										echo 'stalemate';
								}
							}
						}
						else if( $action == 'querylearn' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
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
										echo 'move:' . end( $finals );
									}
									else {
										echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -75 ) {
										echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										echo 'nobestmove';
									}
								}
							}
							else {
								echo 'nobestmove';
							}
						}
						else if( $action == 'querysearch' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0 );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								if( count( $statmoves ) > 1 ) {
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -50 ) {
										$throttle = getthrottle( $maxscore );
										$isfirst = true;
										foreach( $statmoves as $key => $entry ) {
											if( $entry >= $throttle ) {
												if( !$isfirst ) {
													echo '|search:' . $key;
												}
												else {
													$isfirst = false;
													echo 'search:' . $key;
												}
											}
											else
												break;
										}
									}
									else {
										echo 'nobestmove';
									}
								}
								else {
									if( end( $statmoves ) >= -50 ) {
										echo 'move:' . end( array_keys( $statmoves ) );
									}
									else {
										echo 'nobestmove';
									}
								}
							}
							else {
								echo 'nobestmove';
							}
						}
						else if( $action == 'querypv' ) {
							$pv = array();
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							$statmoves = getAnalysisPath( $redis, $row, $banmoves, 0, 400, true, $learn, 0, $pv );
							if( count( $statmoves ) > 0 ) {
								echo 'score:' . $statmoves[$pv[0]] . ',depth:' . count( $pv ) . ',pv:' . implode( '|', $pv );
							}
							else
								echo 'unknown';
						}
						else if( $action == 'queryscore' ) {
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							list( $statmoves, $variations ) = getMoves( $redis, $row, $banmoves, true, true, true, 0 );
							if( count( $statmoves ) > 0 ) {
								arsort( $statmoves );
								$maxscore = reset( $statmoves );
								echo 'eval:' . $maxscore;
							}
							else {
								echo 'unknown';
							}
						}
						else if( $action == 'queue' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('192.168.1.2', 8889, 1.0);
							$statmoves = getMovesWithCheck( $redis, $row, array(), 0, 200, true, true, 0 );
							if( count( $statmoves ) >= 5 ) {
								echo 'ok';
							}
							else if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 && count( ccbmovegen( $row ) ) > 0 ) {
								updateSel( $row, true );
								echo 'ok';
							}
						}
						else if( $action == 'queryengine' ) {
							if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && substr( md5( $_REQUEST['board'] . $_REQUEST['token'] ), 0, 2 ) == '00' ) {
								$movelist = array();
								$isvalid = true;
								if( isset( $_REQUEST['movelist'] ) && !empty( $_REQUEST['movelist'] ) ) {
									$movelist = explode( "|", $_REQUEST['movelist'] );
									$nextfen = $row;
									$movecount = count( $movelist );
									if( $movecount > 0 && $movecount < 2047 ) {
										foreach( $movelist as $entry ) {
											$validmoves = ccbmovegen( $nextfen );
											if( isset( $validmoves[$entry] ) )
												$nextfen = ccbmovemake( $nextfen, $entry );
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
									$memcache_obj->add( 'EngineCount', 0 );
									$engcount = $memcache_obj->increment( 'EngineCount' );
									$result = getEngineMove( $row, $movelist, 5 - $engcount / 2 );
									$memcache_obj->decrement( 'EngineCount' );
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
							else {
								echo 'invalid parameters';
							}
						}
					}
				}
				else {
					//$memcache_obj->delete( 'QLimit::' . $_SERVER['REMOTE_ADDR'] );
					echo 'rate limit exceeded';
				}
			}
		}
		else {
			echo 'invalid board';
		}
		echo "\0";
	}
	else if( $action == 'getqueue' ) {
		if( isset( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) ) {
			if( $readwrite_queue->trywritelock() )
			{
				//$readwrite_queue->writelock();
				$memcache_obj = new Memcache();
				$memcache_obj->pconnect('localhost', 11211);
				if( !$memcache_obj )
					throw new Exception( 'Memcache error.' );
				$activelist = $memcache_obj->get( 'WorkerList' );
				if( $activelist === FALSE ) {
					$activelist = array();
					$memcache_obj->add( 'WorkerList', $activelist, 0, 0 );
				}
				if( !isset( $activelist[$_SERVER['REMOTE_ADDR']] ) ) {
					$activelist[$_SERVER['REMOTE_ADDR']] = 1;
					$memcache_obj->set( 'WorkerList', $activelist, 0, 0 );
				}
				$m = new MongoClient('mongodb://localhost');
				$collection = $m->selectDB('ccdbackqueue')->selectCollection('ackqueuedb');
				$doc = $collection->findAndModify( array( 'ts' => array( '$lt' => new MongoDate( time() - 3600 ) ) ), array( '$set' => array( 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ) ) );
				if( !empty( $doc ) && isset( $doc['data'] ) ) {
					echo $doc['data'];
				}
				else {
					$collection2 = $m->selectDB('ccdbqueue')->selectCollection('queuedb');
					$cursor = $collection2->find()->sort( array( 'p' => -1 ) )->limit(10);
					$docs = array();
					$queueout = '';
					foreach( $cursor as $doc ) {
						$fen = ccbhexfen2fen(bin2hex($doc['_id']->bin));
						if( count_pieces( $fen ) >= 10 && count_attackers( $fen ) >= 4 ) {
							$moves = array();
							foreach( $doc as $key => $item ) {
								if( $key == '_id' )
									continue;
								else if( $key == 'p' )
									continue;
								else if( $key == 'e' )
									continue;
								else if( $memcache_obj->add( 'QueueHistory::' . $fen . $key, 1, 0, 300 ) )
									$moves[] = $key;
							}
							if( count( $moves ) > 0 ) {
								$queueout .= $fen . "\n";
								foreach( $moves as $move )
									$queueout .= $fen . ' moves ' . $move . "\n";
								$thisminute = date('i');
								$memcache_obj->add( 'QueueCount::' . $thisminute, 0, 0, 150 );
								$memcache_obj->increment( 'QueueCount::' . $thisminute );
							}
						}
						$docs[] = $doc['_id'];
					}
					$cursor->reset();
					if( count( $docs ) > 0 ) {
						$collection2->remove( array( '_id' => array( '$in' => $docs ) ) );
					}
					if( strlen($queueout) > 0 ) {
						$collection->update( array( '_id' => new MongoBinData(hex2bin(hash( 'md5', $queueout ))) ), array( 'data' => $queueout, 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ), array( 'upsert' => true ) );
						echo $queueout;
					}
				}
				$readwrite_queue->writeunlock();
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
			$collection = $m->selectDB('ccdbackqueue')->selectCollection('ackqueuedb');
			$collection->remove( array( '_id' => new MongoBinData(hex2bin($_REQUEST['key'])) ) );
			echo 'ok';
		}
		else {
			echo 'tokenerror';
		}
	}
	else if( $action == 'getsel' ) {
		if( isset( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) ) {
			if( $readwrite_sel->trywritelock() )
			{
				//$readwrite_sel->writelock();
				$m = new MongoClient('mongodb://localhost');
				$collection = $m->selectDB('ccdbacksel')->selectCollection('ackseldb');
				$doc = $collection->findAndModify( array( 'ts' => array( '$lt' => new MongoDate( time() - 3600 ) ) ), array( '$set' => array( 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ) ) );
				if( !empty( $doc ) && isset( $doc['data'] ) ) {
					echo $doc['data'];
				}
				else {
					$memcache_obj = new Memcache();
					$memcache_obj->pconnect('localhost', 11211);
					if( !$memcache_obj )
						throw new Exception( 'Memcache error.' );
					$activelist = $memcache_obj->get( 'SelList' );
					if( $activelist === FALSE ) {
							$activelist = array();
							$memcache_obj->add( 'SelList', $activelist, 0, 0 );
					}
					if( !isset( $activelist[$_SERVER['REMOTE_ADDR']] ) ) {
							$activelist[$_SERVER['REMOTE_ADDR']] = 1;
							$memcache_obj->set( 'SelList', $activelist, 0, 0 );
					}

					$collection2 = $m->selectDB('ccdbsel')->selectCollection('seldb');
					$cursor = $collection2->find()->sort( array( 'p' => -1 ) )->limit(10);
					$docs = array();
					$selout = '';
					foreach( $cursor as $doc ) {
						$fen = ccbhexfen2fen(bin2hex($doc['_id']->bin));
						if( count_pieces( $fen ) >= 10 && count_attackers( $fen ) >= 4 && $memcache_obj->add( 'SelHistory::' . $fen, 1, 0, 300 ) )
						{
							if( isset( $doc['p'] ) && $doc['p'] > 0 )
								$selout .= '!' . $fen . "\n";
							else
								$selout .= $fen . "\n";
							$thisminute = date('i');
							$memcache_obj->add( 'SelCount::' . $thisminute, 0, 0, 150 );
							$memcache_obj->increment( 'SelCount::' . $thisminute );
						}
						$docs[] = $doc['_id'];
					}
					$cursor->reset();
					if( count( $docs ) > 0 ) {
						$collection2->remove( array( '_id' => array( '$in' => $docs ) ) );
					}
					if( strlen($selout) > 0 ) {
						$collection->update( array( '_id' => new MongoBinData(hex2bin(hash( 'md5', $selout ))) ), array( 'data' => $selout, 'ip' => $_SERVER['REMOTE_ADDR'], 'ts' => new MongoDate() ), array( 'upsert' => true ) );
						echo $selout;
					}
				}
				$readwrite_sel->writeunlock();
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
			$collection = $m->selectDB('ccdbacksel')->selectCollection('ackseldb');
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
