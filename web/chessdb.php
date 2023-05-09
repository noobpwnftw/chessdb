<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");

$MASTER_PASSWORD = '123456';

final class FlexibleQueueDBClient
{
	private $sock;
	private $socketPath;
	private $timeout; // connect timeout (seconds)

	/**
	 * @param string $socketPath Path to Unix socket (default "/tmp/fqdb.sock")
	 * @param float  $timeout    Connect timeout in seconds (default 5.0).
	 */
	public function __construct($socketPath = "/tmp/fqdb.sock", $timeout = 5.0)
	{
		$this->socketPath = $socketPath;
		$this->timeout = $timeout;
		$this->connectPersistent();
	}

	/** PUSH: returns array('exists'=>bool, 'updated'=>bool) */
	public function push($type, $key, $value, $priority, $expiry, $upsert, $mutate)
	{
		$payload  = $this->u32(strlen($key));
		$payload .= $this->u32(strlen($value));
		$payload .= $this->u16($priority);
		$payload .= $this->u64($expiry);
		$payload .= chr($upsert ? 1 : 0);
		$payload .= chr($mutate ? 1 : 0);
		$payload .= $key . $value;

		$resp = $this->rpc($type, 1, $payload);
		return array('exists'=>ord($resp[0])!==0, 'updated'=>ord($resp[1])!==0);
	}

	/** POP: returns array of items */
	public function pop($type, $n, $direction)
	{
		$payload = $this->u32($n) . chr($direction);
		$resp = $this->rpc($type, 2, $payload);

		$off=0; $count=$this->ru32($resp,$off); $items=array();
		for($i=0;$i<$count;$i++){
			$klen=$this->ru32($resp,$off);
			$vlen=$this->ru32($resp,$off);
			$pri =$this->ru16($resp,$off);
			$exp =$this->ru64($resp,$off);
			$key =substr($resp,$off,$klen); $off+=$klen;
			$val =substr($resp,$off,$vlen); $off+=$vlen;
			$items[]=array('key'=>$key,'value'=>$val,'priority'=>$pri,'expiry'=>$exp);
		}
		return $items;
	}

	/** REFRESH: returns array of items */
	public function refresh($type, $threshold, $expiry, $n)
	{
		$payload  = $this->u64($threshold);
		$payload .= $this->u64($expiry);
		$payload .= $this->u32($n);
		$resp = $this->rpc($type, 3, $payload);

		$off=0; $count=$this->ru32($resp,$off); $items=array();
		for($i=0;$i<$count;$i++){
			$klen=$this->ru32($resp,$off);
			$vlen=$this->ru32($resp,$off);
			$pri =$this->ru16($resp,$off);
			$exp =$this->ru64($resp,$off);
			$key =substr($resp,$off,$klen); $off+=$klen;
			$val =substr($resp,$off,$vlen); $off+=$vlen;
			$items[]=array('key'=>$key,'value'=>$val,'priority'=>$pri,'expiry'=>$exp);
		}
		return $items;
	}

	/** REMOVE: returns bool */
	public function remove($type, $key)
	{
		$payload  = $this->u32(strlen($key));
		$payload .= $key;

		$resp = $this->rpc($type, 4, $payload);
		return ord($resp[0])!==0;
	}

	/** COUNT: returns u64 (as int on 64-bit PHP) */
	public function count($type, $min, $max)
	{
		$payload = $this->u32($min & 0xFFFFFFFF).$this->u32($max & 0xFFFFFFFF);
		$resp = $this->rpc($type, 5, $payload);
		$off=0; return $this->ru64($resp,$off);
	}

	/** Optional explicit close */
	public function close()
	{
		if (is_resource($this->sock)) {
			@fclose($this->sock);
		}
		$this->sock = null;
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	private function connectPersistent()
	{
		$errno = 0; $errstr = '';
		$this->sock = @pfsockopen("unix://".$this->socketPath, -1, $errno, $errstr, $this->timeout);
		if (!$this->sock) {
			throw new RuntimeException("connect($this->socketPath): [$errno] $errstr");
		}
		stream_set_blocking($this->sock, true);
	}

	private function ensureConnected()
	{
		if (!is_resource($this->sock) || feof($this->sock)) {
			$this->close();
			$this->connectPersistent();
		}
	}

	/** RPC with close on failure. */
	private function rpc($type, $op, $payload)
	{
		$this->ensureConnected();

		$hdr = $this->u16($type).$this->u16($op).$this->u32(strlen($payload));
		$buf = $hdr.$payload;

		try {
			$this->writeAll($buf);
			$h=$this->readN(4); $off=0; $len=$this->ru32($h,$off);
			if($len==0){
				throw new RuntimeException("server error");
			}
			return $this->readN($len);
		} catch (Exception $e) {
			$this->close();
			throw $e;
		}
	}

	private function writeAll($buf)
	{
		$n=strlen($buf); $o=0;
		while($o<$n){
			$w=@fwrite($this->sock, substr($buf,$o));
			if($w===false || $w===0){
				throw new RuntimeException("socket write failed");
			}
			$o+=$w;
		}
	}

	private function readN($n)
	{
		$out='';
		while(strlen($out)<$n){
			$chunk=@fread($this->sock, $n - strlen($out));
			if($chunk===false || $chunk===''){
				$meta = @stream_get_meta_data($this->sock);
				if (!empty($meta['timed_out'])) {
					throw new RuntimeException("socket read timeout");
				}
				throw new RuntimeException("socket closed");
			}
			$out.=$chunk;
		}
		return $out;
	}

	// Little-endian pack/unpack
	private function u16($v){return pack('v',$v & 0xFFFF);}
	private function u32($v){return pack('V',$v & 0xFFFFFFFF);}
	private function u64($v){return pack('P',$v);}
	private function ru16($b,&$o){$v=unpack('v',substr($b,$o,2));$o+=2;return $v[1];}
	private function ru32($b,&$o){$v=unpack('V',substr($b,$o,4));$o+=4;return $v[1];}
	private function ru64($b,&$o){$v=unpack('P',substr($b,$o,8));$o+=8;return $v[1];}
}

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
function getlearnthrottle( $maxscore ) {
	if( $maxscore >= 50 ) {
		$throttle = $maxscore;
	}
	else if( $maxscore >= -30 ) {
		$throttle = $maxscore - 40 / ( 1 + exp( -abs( $maxscore ) / 10 ) );
	}
	else {
		$throttle = -75;
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
function countAllScores( $redis, $row ) {
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		return $redis->hLen( $minbinfen );
	}
	else {
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		return $redis->hLen( $minbinfen );
	}
}
function scoreExists( $redis, $minbinfen, $minindex, $hasLRmirror, $move ) {
	if( $hasLRmirror ) {
		if( $minindex == 0 ) {
			return $redis->hExists( $minbinfen, $move );
		}
		else if( $minindex == 1 ) {
			return $redis->hExists( $minbinfen, ccbgetBWmove( $move ) );
		}
		else if( $minindex == 2 ) {
			return $redis->hExists( $minbinfen, ccbgetLRmove( $move ) );
		}
		else {
			return $redis->hExists( $minbinfen, ccbgetLRBWmove( $move ) );
		}
	}
	else {
		if( $minindex == 0 ) {
			if( $move != ccbgetLRmove( $move ) )
			{
				return ( $redis->hExists( $minbinfen, $move ) || $redis->hExists( $minbinfen, ccbgetLRmove( $move ) ) );
			}
			else {
				return $redis->hExists( $minbinfen, $move );
			}
		}
		else {
			if( $move != ccbgetLRmove( $move ) )
			{
				return ( $redis->hExists( $minbinfen, ccbgetBWmove( $move ) ) || $redis->hExists( $minbinfen, ccbgetLRBWmove( $move ) ) );
			}
			else {
				return $redis->hExists( $minbinfen, ccbgetBWmove( $move ) );
			}
		}
	}
}
function updateScore( $redis, $minbinfen, $minindex, $hasLRmirror, $updatemoves ) {
	if( $hasLRmirror ) {
		if( $minindex == 0 ) {
			if( $redis->hMSet( $minbinfen, $updatemoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetBWmove( $key )] = $newscore;
			}
			if( $redis->hMSet( $minbinfen, $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
		else if( $minindex == 2 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRmove( $key )] = $newscore;
			}
			if( $redis->hMSet( $minbinfen, $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
		else {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				$newmoves[ccbgetLRBWmove( $key )] = $newscore;
			}
			if( $redis->hMSet( $minbinfen, $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
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
			if( $redis->hMSet( $minbinfen, $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
		else if( $minindex == 1 ) {
			$newmoves = array();
			foreach( $updatemoves as $key => $newscore ) {
				if( ccbgetBWmove( $key ) <= ccbgetLRBWmove( $key ) )
					$newmoves[ccbgetBWmove( $key )] = $newscore;
				else
					$newmoves[ccbgetLRBWmove( $key )] = $newscore;
			}
			if( $redis->hMSet( $minbinfen, $newmoves ) === FALSE )
				throw new RedisException( 'Server operation error.' );
		}
	}
}
function updateQueue( $row, $key, $priority ) {
	$fq = new FlexibleQueueDBClient( '/run/ccqueue/ccqueue.sock' );
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
function updateSel( $row, $priority ) {
	$fq = new FlexibleQueueDBClient( '/run/ccsel/ccsel.sock' );
	$LRfen = ccbgetLRfen( $row );
	$BWfen = ccbgetBWfen( $row );
	$hasLRmirror = ( $row == $LRfen ? false : true );
	if( $hasLRmirror ) {
		$LRBWfen = ccbgetLRfen( $BWfen );
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen), ccbfen2hexfen($LRfen), ccbfen2hexfen($LRBWfen) ) );
		$fq->push( 0, $minbinfen, '', $priority, time() + 7200, true, false );
	}
	else {
		list( $minbinfen, $minindex ) = getBinFenStorage( array( ccbfen2hexfen($row), ccbfen2hexfen($BWfen) ) );
		$fq->push( 0, $minbinfen, '', $priority, time() + 7200, true, false );
	}
}
function updatePly( $redis, $minbinfen, $ply ) {
	if( $redis->hSet( $minbinfen, 'a0a0', $ply ) === FALSE )
		throw new RedisException( 'Server operation error.' );
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
	$moves2 = array();

	if( isset($moves1['ply']) )
	{
		if( $depth > 0 && ( $moves1['ply'] < 0 || $moves1['ply'] > $depth ) )
		{
			updatePly( $redis, $minbinfen, $depth );
			$depth++;
		}
		else
			$depth = $moves1['ply'] + 1;
	}
	else if( count( $moves1 ) > 0 && $depth > 0 )
	{
		updatePly( $redis, $minbinfen, $depth );
		$depth++;
	}
	unset( $moves1['ply'] );

	if( $depth > 0 )
		$moves2['ply'] = $depth - 1;

	if( $update )
	{
		$knownmoves = array();
		$updatemoves = array();
		foreach( $moves1 as $key => $item ) {
			$knownmoves[$key] = 0;
			if( !$hasLRmirror && $key != ccbgetLRmove( $key ) ) {
				$knownmoves[ccbgetLRmove( $key )] = 0;
			}
			$moves2[ $key ][0] = 0;
			$moves2[ $key ][1] = 0;
			if( isset( $finals[ $key ] ) )
				continue;
			$nextfen = ccbmovemake( $row, $key );
			list( $nextmoves, $variations ) = getMoves( $redis, $nextfen, array(), false, false, false, $depth );
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
			else if( count( ccbmovegen( $nextfen ) ) == 0 )
			{
				$moves1[ $key ] = 30000;
				$updatemoves[ $key ] = -30000;
			}
			else if( count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) >= 4 )
			{
				updateQueue( $row, $key, 2 );
			}
		}
		if( count( $updatemoves ) > 0 )
			updateScore( $redis, $minbinfen, $minindex, $hasLRmirror, $updatemoves );
		$memcache_obj = new Memcache();
		if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
			throw new Exception( 'Memcache error.' );
		if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
			$allmoves = ccbmovegen( $row );
			if( count( $allmoves ) > count( $knownmoves ) ) {
				if( count( $knownmoves ) > 0 && count( $knownmoves ) < 5 ) {
					updateSel( $row, 1 );
				}
				$allmoves = array_diff_key( $allmoves, $knownmoves );
				$findmoves = array();
				foreach( $allmoves as $key => $item ) {
					if( !$hasLRmirror && $key != ccbgetLRmove( $key ) && isset( $findmoves[ccbgetLRmove( $key )] ) )
						continue;
					$findmoves[$key] = $item;
				}
				$learnArr = array();
				foreach( $findmoves as $key => $item ) {
					$nextfen = ccbmovemake( $row, $key );
					list( $nextmoves, $variations ) = getMoves( $redis, $nextfen, array(), false, false, false, $depth );
					if( count( $nextmoves ) > 0 ) {
						updateQueue( $row, $key, 2 );
					}
					else if( $learn ) {
						$learnArr['Learn::' . $nextfen] = array( $row, $key );
					}
				}
				if( count( $learnArr ) > 0 )
					$memcache_obj->set( $learnArr, NULL, 0, 300 );
			}
		}
		$autolearn = $memcache_obj->get( 'Learn::' . $row );
		if( $autolearn !== FALSE ) {
			$memcache_obj->delete( 'Learn::' . $row );
			updateQueue( $autolearn[0], $autolearn[1], $learn ? 2 : 0 );
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
function getMovesWithCheck( $redis, $row, $banmoves, $ply, $enumlimit, $resetlimit, $learn, $depth, $sieve ) {
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

	if( isset($moves1['ply']) )
	{
		if( $depth > 0 && ( $moves1['ply'] < 0 || $moves1['ply'] > $depth ) )
		{
			updatePly( $redis, $minbinfen, $depth );
			$depth++;
		}
		else
			$depth = $moves1['ply'] + 1;
	}
	else if( count( $moves1 ) > 0 && $depth > 0 )
	{
		updatePly( $redis, $minbinfen, $depth );
		$depth++;
	}
	unset( $moves1['ply'] );

	if( $GLOBALS['counter'] < $enumlimit )
	{
		$recurse = false;
		if( !isset( $GLOBALS['boardtt'][$row] ) )
		{
			if( !isset( $GLOBALS['boardtt'][$BWfen] ) )
			{
				if( $hasLRmirror )
				{
					if( !isset( $GLOBALS['boardtt'][$LRfen] ) )
					{
						if( !isset( $GLOBALS['boardtt'][$LRBWfen] ) )
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
				$updatemoves = array();
				asort( $moves1 );
				$throttle = getthrottle( end( $moves1 ) );
				$moves2 = array();
				$knownmoves = array();
				foreach( $moves1 as $key => $item ) {
					$knownmoves[$key] = 0;
					if( !$hasLRmirror && $key != ccbgetLRmove( $key ) ) {
						$knownmoves[ccbgetLRmove( $key )] = 0;
					}
					if( ( $ply == 0 && $resetlimit && $item > -10000 ) || ( $item >= $throttle || $item == end( $moves1 ) ) ) {
						$moves2[ $key ] = $item;
					}
				}
				shuffle_assoc( $moves2 );
				arsort( $moves2 );

				if( $ply == 0 ) {
					$GLOBALS['movecnt'] = array();
					$isfirst = true;
				}

				foreach( $moves2 as $key => $item ) {
					if( $resetlimit )
						$GLOBALS['counter'] = 0;
					else
						$GLOBALS['counter']++;

					if( $ply == 0 )
						$GLOBALS['counter1'] = 1;
					else
						$GLOBALS['counter1']++;
					if( isset( $finals[ $key ] ) ) {
						if( $ply == 0 )
							$GLOBALS['movecnt'][$key] = $GLOBALS['counter1'];
						continue;
					}
					$nextfen = ccbmovemake( $row, $key );
					$GLOBALS['historytt'][$row]['fen'] = $nextfen;
					$GLOBALS['historytt'][$row]['move'] = $key;
					$nextmoves = getMovesWithCheck( $redis, $nextfen, array(), $ply + 1, $enumlimit, false, false, $depth, $sieve );
					unset( $GLOBALS['historytt'][$row] );
					if( isset( $GLOBALS['loopcheck'] ) ) {
						$GLOBALS['looptt'][$row][$key] = $GLOBALS['loopcheck'];
						unset( $GLOBALS['loopcheck'] );
					}
					if( $ply == 0 ) {
						$GLOBALS['movecnt'][$key] = $GLOBALS['counter1'];
						if( $resetlimit && $isfirst ) {
							$enumlimit = ( int )( $enumlimit / count( $moves2 ) ) + 10;
							$isfirst = false;
						}
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
					else if( count( ccbmovegen( $nextfen ) ) == 0 )
					{
						$moves1[ $key ] = 30000;
						$updatemoves[ $key ] = -30000;
					}
					else if( $ply == 0 )
						updateQueue( $row, $key, 2 );
					else if( count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) >= 4 )
						updateQueue( $row, $key, $sieve ? 1 : 0 );
				}
				if( count( $updatemoves ) > 0 )
					updateScore( $redis, $minbinfen, $minindex, $hasLRmirror, $updatemoves );

				if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
					$allmoves = ccbmovegen( $row );
					if( count( $allmoves ) > count( $knownmoves ) ) {
						if( $ply == 0 ) {
							$memcache_obj = new Memcache();
							if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
								throw new Exception( 'Memcache error.' );
							$allmoves = array_diff_key( $allmoves, $knownmoves );
							$findmoves = array();
							foreach( $allmoves as $key => $item ) {
								if( !$hasLRmirror && $key != ccbgetLRmove( $key ) && isset( $findmoves[ccbgetLRmove( $key )] ) )
									continue;
								$findmoves[$key] = $item;
							}
							$learnArr = array();
							foreach( $findmoves as $key => $item ) {
								$nextfen = ccbmovemake( $row, $key );
								if( $learn ) {
									$learnArr['Learn::' . $nextfen] = array( $row, $key );
								}
							}
							if( count( $learnArr ) > 0 )
								$memcache_obj->set( $learnArr, NULL, 0, 300 );
							$autolearn = $memcache_obj->get( 'Learn::' . $row );
							if( $autolearn !== FALSE ) {
								$memcache_obj->delete( 'Learn::' . $row );
								updateQueue( $autolearn[0], $autolearn[1], $learn ? 2 : 0 );
							}
						}
						if( $sieve && $ply < ( $enumlimit / 2 + 10 ) && count( $knownmoves ) > 0 && count( $knownmoves ) < 5 )
							updateSel( $row, $ply == 0 ? 1 : 0 );
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
			}
			else if( !$isloop )
				$GLOBALS['boardtt'][$row] = 1;
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
function getAnalysisPath( $redis, $row, $banmoves, $ply, $enumlimit, $isbest, $learn, $depth, &$pv, $stable, $sieve ) {
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

	if( isset($moves1['ply']) )
	{
		if( $depth > 0 && ( $moves1['ply'] < 0 || $moves1['ply'] > $depth ) )
		{
			updatePly( $redis, $minbinfen, $depth );
			$depth++;
		}
		else
			$depth = $moves1['ply'] + 1;
	}
	else if( count( $moves1 ) > 0 && $depth > 0 )
	{
		updatePly( $redis, $minbinfen, $depth );
		$depth++;
	}
	unset( $moves1['ply'] );

	if( $GLOBALS['counter'] < $enumlimit )
	{
		$recurse = false;
		if( !isset( $GLOBALS['boardtt'][$row] ) )
		{
			if( !isset( $GLOBALS['boardtt'][$BWfen] ) )
			{
				if( $hasLRmirror )
				{
					if( !isset( $GLOBALS['boardtt'][$LRfen] ) )
					{
						if( !isset( $GLOBALS['boardtt'][$LRBWfen] ) )
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
				$updatemoves = array();
				asort( $moves1 );
				$throttle = getbestthrottle( end( $moves1 ) );
				$moves2 = array();
				$knownmoves = array();
				foreach( $moves1 as $key => $item ) {
					$knownmoves[$key] = 0;
					if( !$hasLRmirror && $key != ccbgetLRmove( $key ) ) {
						$knownmoves[ccbgetLRmove( $key )] = 0;
					}
					if( $item >= $throttle ) {
						$moves2[ $key ] = $item;
					}
				}
				if( !$stable )
					shuffle_assoc( $moves2 );
				else
				{
					$moves3 = array();
					foreach( $moves2 as $key => $item ) {
						$moves3[ $key ][0] = 0;
						$moves3[ $key ][1] = 0;
						if( isset( $finals[ $key ] ) )
							continue;
						$nextfen = ccbmovemake( $row, $key );
						list( $nextmoves, $variations ) = getMoves( $redis, $nextfen, array(), false, false, false, $depth );
						if( count( $nextmoves ) > 0 ) {
							arsort( $nextmoves );
							$nextscore = reset( $nextmoves );
							$throttle = getthrottle( $nextscore );
							$nextcount = 0;
							foreach( $nextmoves as $record => $score ) {
								if( $score >= $throttle )
									$nextcount++;
								else
									break;
							}
							$moves3[ $key ][0] = count( $nextmoves );
							$moves3[ $key ][1] = $nextcount;
						}
					}
					uksort( $moves2, function ( $a, $b ) use ( $moves2, $moves3 ) {
						return ( $moves2[$b] <=> $moves2[$a] )
							?: ( $moves3[$a][1] <=> $moves3[$b][1] )
							?: ( $moves3[$b][0] <=> $moves3[$a][0] )
							?: ( $a <=> $b );
					});

				}
				foreach( $moves2 as $key => $item ) {
					$GLOBALS['counter']++;
					if( $isbest ) {
						array_push( $pv, $key );
					}
					if( isset( $finals[ $key ] ) ) {
						$isbest = false;
						continue;
					}
					$nextfen = ccbmovemake( $row, $key );
					$GLOBALS['historytt'][$row]['fen'] = $nextfen;
					$GLOBALS['historytt'][$row]['move'] = $key;
					$nextmoves = getAnalysisPath( $redis, $nextfen, array(), $ply + 1, $enumlimit, $isbest, false, $depth, $pv, $stable, $sieve );
					$isbest = false;
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
					else if( count( ccbmovegen( $nextfen ) ) == 0 )
					{
						$moves1[ $key ] = 30000;
						$updatemoves[ $key ] = -30000;
					}
					else if( $ply == 0 )
						updateQueue( $row, $key, 2 );
					else if( count_pieces( $nextfen ) >= 10 && count_attackers( $nextfen ) >= 4 )
						updateQueue( $row, $key, $sieve ? 1 : 0 );
				}
				if( count( $updatemoves ) > 0 )
					updateScore( $redis, $minbinfen, $minindex, $hasLRmirror, $updatemoves );

				if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 ) {
					$allmoves = ccbmovegen( $row );
					if( count( $allmoves ) > count( $knownmoves ) ) {
						if( $ply == 0 ) {
							$memcache_obj = new Memcache();
							if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
								throw new Exception( 'Memcache error.' );
							$allmoves = array_diff_key( $allmoves, $knownmoves );
							$findmoves = array();
							foreach( $allmoves as $key => $item ) {
								if( !$hasLRmirror && $key != ccbgetLRmove( $key ) && isset( $findmoves[ccbgetLRmove( $key )] ) )
									continue;
								$findmoves[$key] = $item;
							}
							$learnArr = array();
							foreach( $findmoves as $key => $item ) {
								$nextfen = ccbmovemake( $row, $key );
								if( $learn ) {
									$learnArr['Learn::' . $nextfen] = array( $row, $key );
								}
							}
							if( count( $learnArr ) > 0 )
								$memcache_obj->set( $learnArr, NULL, 0, 300 );
							$autolearn = $memcache_obj->get( 'Learn::' . $row );
							if( $autolearn !== FALSE ) {
								$memcache_obj->delete( 'Learn::' . $row );
								updateQueue( $autolearn[0], $autolearn[1], $learn ? 2 : 0 );
							}
						}
						else if( $sieve && $ply < ( $enumlimit / 2 + 10 ) && count( $knownmoves ) > 0 && count( $knownmoves ) < 5 )
							updateSel( $row, $ply == 0 ? 1 : 0 );
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
			}
			else if( !$isloop )
				$GLOBALS['boardtt'][$row] = 1;
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
	$sock = fsockopen('unix:///run/cceval/cceval.sock');
	if( $sock ) {
		stream_set_blocking( $sock, FALSE );
		fwrite( $sock, 'fen ' . $row);
		if( count( $movelist ) > 0 ) {
			fwrite( $sock, ' moves ' . implode(' ', $movelist ) );
		}
		fwrite( $sock, PHP_EOL . 'go' . PHP_EOL );
		$startTime = time();
		$readfd = array( $sock );
		$writefd = NULL;
		$errfd = NULL;
		while( true ) {
			$res = @stream_select( $readfd, $writefd, $errfd, 1 );
			if( $res > 0 && ( $out = fgets( $sock ) ) ) {
				if( $move = strstr( $out, 'bestmove' ) ) {
					$result = substr( $move, 9, 4 );
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

					$moves = ccbmovegen( $row );
					$move = $_REQUEST['move'];
					if( isset( $moves[$move] ) && isset( $_REQUEST['score'] ) ) {
						if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) . $row . $_REQUEST['move'] . $_REQUEST['score'] ) ) {
							if( isset( $_REQUEST['nodes'] ) ) {
								$nodes = intval($_REQUEST['nodes']);
								$memcache_obj = new Memcache();
								if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
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
							$redis = new Redis();
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							if( !scoreExists( $redis, $minbinfen, $minindex, $hasLRmirror, $move ) || countAllScores( $redis, ccbmovemake( $row, $move ) ) == 0 ) {
								updateScore( $redis, $minbinfen, $minindex, $hasLRmirror, array( $move => $score ) );
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
							$priority = 1;
							if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) . $move ) ) {
								$priority = 2;
							}
							$redis = new Redis();
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							if( !scoreExists( $redis, $minbinfen, $minindex, $hasLRmirror, $move ) || countAllScores( $redis, ccbmovemake( $row, $move ) ) == 0 ) {
								updateQueue( $row, $move, $priority );
								if( $isJson )
									echo '"status":"ok"';
								else
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
							if( $isJson )
								echo '"status":"ok","moves":[{';
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
								if( !$isfirst ) {
									if( $isJson )
										echo '},{"uci":"' . $key . '","rule":"' . $rulestr . '"';
									else
										echo '|move:' . $key . ',rule:' . $rulestr;
								}
								else {
									if( $isJson )
										echo '"uci":"' . $key . '","rule":"' . $rulestr . '"';
									else
										echo 'move:' . $key . ',rule:' . $rulestr;
									$isfirst = false;
								}
							}
							if( $isJson )
								echo '}]';
						}
						else {
							if( ccbincheck( $nextfen ) ) {
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
					else {
						if( $isJson )
							echo '"status":"invalid movelist"';
						else
							echo 'invalid movelist';
					}
				}
			}
			else
			{
				$memcache_obj = new Memcache();
				if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
					throw new Exception( 'Memcache error.' );
				$ratelimit = $memcache_obj->get( 'RateLimit' );
				if( $ratelimit === FALSE )
					$ratelimit = 5;
				$ratekey = 'QLimit::' . $_SERVER['REMOTE_ADDR'];
				$memcache_obj->add( $ratekey, 0, 0, 1 );
				$querylimit = $memcache_obj->increment( $ratekey );
				if( $querylimit === FALSE || $querylimit <= $ratelimit )
				{
					$egtbresult = NULL;
					if( count_pieces( $row ) <= 13 && count_attackers( $row ) <= 5 && ( $action == 'queryall' || $action == 'query' || $action == 'querybest' || $action == 'querylearn' || $action == 'querysearch' || $action == 'queryscore' || $action == 'querypv' ) ) {
						if( $dtmtb )
							$egtbresult = $memcache_obj->get( 'EGTB_DTM::' . $row );
						else
							$egtbresult = $memcache_obj->get( 'EGTB_DTC::' . $row );
						if( $egtbresult === FALSE ) {
							$egtbresult = file_get_contents( 'http://tbserver.internal/tbproxy.php?action=ccegtbprobe&board=' . urlencode( $row ) . '&egtbmetric=' . ( $dtmtb ? 'dtm' : 'dtc' ) );
							if( $egtbresult !== FALSE ) {
								$egtbresult = unserialize( $egtbresult );
								if( !empty( $egtbresult ) ) {
									if( $dtmtb )
										$memcache_obj->add( 'EGTB_DTM::' . $row, $egtbresult, 0, 30 );
									else
										$memcache_obj->add( 'EGTB_DTC::' . $row, $egtbresult, 0, 30 );
								}
							}
						}
					}
					if( !empty( $egtbresult ) ) {
						if( !$dtmtb ) {
							$GLOBALS['order'] = array_pop( $egtbresult );
						}
						$GLOBALS['score'] = array_pop( $egtbresult );

						$moves = array_diff_key( $egtbresult, $banmoves );

						if( $dtmtb )
							uasort( $moves , 'dtmcmp' );
						else
							uasort( $moves , 'dtccmp' );

						if( $action == 'queryall' ) {
							if( count( $moves ) > 0 ) {
								if( $isJson )
									echo '"status":"ok","moves":[{';
								$bestmove = reset( $moves );
								$isfirst = true;
								if( $dtmtb ) {
									foreach( array_keys( $moves ) as $record ) {
										if( !$isfirst ) {
											if( $moves[$record]['score'] > 0 ) {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] ) {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
												else {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":1,"note":"* (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
											}
											else if( $moves[$record]['score'] == 0 ) {
												if( $bestmove['score'] == 0 ) {
													if( $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] ) {
														if( $isJson )
															echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
														else
															echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
													}
													else {
														if( $isJson )
															echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":1,"note":"* (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
														else
															echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
													}
												}
												else {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":0,"note":"? (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
											}
											else {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] ) {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
												else if( $bestmove['score'] < 0 ) {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":1,"note":"* (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
												else {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":0,"note":"? (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
											}
										}
										else {
											$isfirst = false;
											if( $bestmove['score'] == 0 && $moves[$record]['score'] == 0 ) {
												if( $isJson )
													echo '"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
												else
													echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (D-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
											}
											else {
												if( $moves[$record]['score'] > 0 ) {
													if( $isJson )
														echo '"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
												else {
													if( $isJson )
														echo '"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')"';
													else
														echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-M-' . str_pad( $moves[$record]['step'], 4, '0', STR_PAD_LEFT ) . ')';
												}
											}
										}
									}
								}
								else {
									foreach( array_keys( $moves ) as $record ) {
										if( !$isfirst ) {
											if( $moves[$record]['score'] > 0 ) {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['order'] == $bestmove['order'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] ) {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
												else {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":1,"note":"* (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
											}
											else if( $moves[$record]['score'] == 0 ) {
												if( $bestmove['score'] == 0 ) {
													if( $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] ) {
														if( $isJson )
															echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
														else
															echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
													}
													else {
														if( $isJson )
															echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":1,"note":"* (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
														else
															echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
													}
												}
												else {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":0,"note":"? (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
											}
											else {
												if( $moves[$record]['score'] == $bestmove['score'] && $moves[$record]['order'] == $bestmove['order'] && $moves[$record]['cap'] == $bestmove['cap'] && $moves[$record]['check'] == $bestmove['check'] ) {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
												else if( $bestmove['score'] < 0 ) {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":1,"note":"* (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:1,note:* (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
												else {
													if( $isJson )
														echo '},{"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":0,"note":"? (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo '|move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:0,note:? (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
											}
										}
										else {
											$isfirst = false;
											if( $bestmove['score'] == 0 && $moves[$record]['score'] == 0 ) {
												if( $isJson )
													echo '"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
												else
													echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (D-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
											}
											else {
												if( $moves[$record]['score'] > 0 ) {
													if( $isJson )
														echo '"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (W-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
												else {
													if( $isJson )
														echo '"uci":"' . $record . '","score":' . $moves[$record]['score'] . ',"rank":2,"note":"! (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')"';
													else
														echo 'move:' . $record . ',score:' . $moves[$record]['score'] . ',rank:2,note:! (L-' . str_pad( $moves[$record]['order'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $moves[$record]['step'], 3, '0', STR_PAD_LEFT ) . ')';
												}
											}
										}
									}
								}
								if( $isJson )
									echo '}]';
							}
							else {
								$allmoves = ccbmovegen( $row );
								if( count( $allmoves ) > 0 ) {
									if( $isJson )
										echo '"status":"unknown"';
									else
										echo 'unknown';
								}
								else {
									if( ccbincheck( $row ) ) {
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
									if( $isJson )
										echo '"status":"ok","egtb":"' . end( $finals ) . '"';
									else
										echo 'egtb:' . end( $finals );
								}
								else if( $bestmove['score'] == 0 )
								{
									//if( $dtmtb ) {
									//	echo 'move:' . reset( array_keys( $moves ) );
									//}
									//else {
										if( $isJson )
											echo '"status":"ok","search_moves":[{';
										$isfirst = true;
										foreach( $moves as $key => $entry ) {
											if( $entry['score'] == 0 ) {
												if( !$isfirst ) {
													if( $isJson )
														echo '},{"uci":"' . $key . '"';
													else
														echo '|search:' . $key;
												}
												else {
													$isfirst = false;
													if( $isJson )
														echo '"uci":"' . $key . '"';
													else
														echo 'search:' . $key;
												}
											}
											else
												break;
										}
										if( $isJson )
											echo '}]';
									//}
								}
								else {
									if( $isJson )
										echo '"status":"nobestmove"';
									else
										echo 'nobestmove';
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
							if( count( $moves ) > 0 ) {
								$bestmove = reset( $moves );
								if( $isJson )
									echo '"status":"ok","score":' . $bestmove['score'] . ',"depth":' . $bestmove['step'] . ',"pv":["' . key( $moves ) . '"]';
								else
									echo 'score:' . $bestmove['score'] . ',depth:' . $bestmove['step'] . ',pv:' . key( $moves );
							}
							else {
								$allmoves = ccbmovegen( $row );
								if( count( $allmoves ) > 0 ) {
									if( $isJson )
										echo '"status":"unknown"';
									else
										echo 'unknown';
								}
								else {
									if( ccbincheck( $row ) ) {
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
						else if( $action == 'queryscore' ) {
							if( count( $moves ) > 0 ) {
								$bestmove = reset( $moves );
								if( $isJson )
									echo '"status":"ok","eval":' . $bestmove['score'];
								else
									echo 'eval:' . $bestmove['score'];
							}
							else {
								$allmoves = ccbmovegen( $row );
								if( count( $allmoves ) > 0 ) {
									if( $isJson )
										echo '"status":"unknown"';
									else
										echo 'unknown';
								}
								else {
									if( ccbincheck( $row ) ) {
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
					}
					else if( !$endgame || $action == 'queryall' || $action == 'queryscore' || $action == 'querypv' || $action == 'queue' ) {
						if( $action == 'querybest' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$redis = new Redis();
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0, true );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								if( count( $statmoves ) > 1 ) {
									$finals = array();
									$finalcount = 0;
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -50 ) {
										foreach( $statmoves as $key => $entry ) {
											if( $entry >= getbestthrottle( $maxscore ) )
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
									if( end( $statmoves ) >= -50 ) {
										if( $isJson )
											echo '"status":"ok","move":"' . array_key_last( $statmoves ) . '"';
										else
											echo 'move:' . array_key_last( $statmoves );
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
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0, true );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								if( count( $statmoves ) > 1 ) {
									$finals = array();
									$finalcount = 0;
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -50 ) {
										$throttle = getthrottle( $maxscore );
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
									if( end( $statmoves ) >= -50 ) {
										if( $isJson )
											echo '"status":"ok","move":"' . array_key_last( $statmoves ) . '"';
										else
											echo 'move:' . array_key_last( $statmoves );
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
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							list( $statmoves, $variations ) = getMoves( $redis, $row, $banmoves, true, true, $learn, 0 );
							if( count( $statmoves ) > 0 ) {
								if( $isJson )
									echo '"status":"ok","moves":[{';

								uksort( $statmoves, function ( $a, $b ) use ( $statmoves, $variations ) {
									return ( $statmoves[$b] <=> $statmoves[$a] )
										?: ( $variations[$a][1] <=> $variations[$b][1] )
										?: ( $variations[$b][0] <=> $variations[$a][0] )
										?: ( $a <=> $b );
								});
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
										if( $isJson )
											echo '"uci":"' . $record . '","score":' . $score . ',"rank":2,"note":"! (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')"' . $winrate;
										else
											echo 'move:' . $record . ',score:' . $score . ',rank:2,note:! (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
									}
									else if( $score >= $throttle ) {
										if( $isfirst || $learn ) {
											if( $isJson )
												echo '"uci":"' . $record . '","score":' . $score . ',"rank":1,"note":"* (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')"' . $winrate;
											else
												echo 'move:' . $record . ',score:' . $score . ',rank:1,note:* (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')' . $winrate;
										}
										else
											unset( $statmoves[$record] );
									}
									else {
										if( $isfirst || $learn ) {
											if( $isJson )
												echo '"uci":"' . $record . '","score":' . $score . ',"rank":0,"note":"? (' . str_pad( $variations[$record][0], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $variations[$record][1], 2, '0', STR_PAD_LEFT ) . ')"' . $winrate;
											else
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
											if( $isJson )
												echo '},{"uci":"' . $record . '","score":"??","rank":0,"note":"? (??-??)"';
											else
												echo '|move:' . $record . ',score:??,rank:0,note:? (??-??)';
										}
									}
								}
								if( $isJson ) {
									echo '}]';
									if( isset( $variations['ply'] ) ) {
										$ply = $variations['ply'];
										$side = substr( $row, strpos( $row, ' ' ) + 1, 1 );
										if( $side == 'w' ) {
											if( $ply & 1 )
												$ply++;
										}
										else {
											if( ( $ply & 1 ) == 0 )
												$ply++;
										}
										echo ',"ply":' . $ply;
									}
								}
							}
							else {
								$allmoves = ccbmovegen( $row );
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
												echo '"uci":"' . $record . '","score":"??","rank":0,"note":"? (??-??)"';
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
									if( ccbincheck( $row ) ) {
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
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0, true );
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
											echo '"status":"ok","move":"' . array_key_last( $statmoves ) . '"';
										else
											echo 'move:' . array_key_last( $statmoves );
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
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							$statmoves = getMovesWithCheck( $redis, $row, $banmoves, 0, 20, false, $learn, 0, true );
							if( count( $statmoves ) > 0 && $GLOBALS['counter'] >= 10 && $GLOBALS['counter2'] >= 4 ) {
								if( count( $statmoves ) > 1 ) {
									arsort( $statmoves );
									$maxscore = reset( $statmoves );
									if( $maxscore >= -50 ) {
										if( $isJson )
											echo '"status":"ok","search_moves":[{';
										$throttle = getthrottle( $maxscore );
										$isfirst = true;
										foreach( $statmoves as $key => $entry ) {
											if( $entry >= $throttle ) {
												if( !$isfirst ) {
													if( $isJson )
														echo '},{"uci":"' . $key . '"';
													else
														echo '|search:' . $key;
												}
												else {
													$isfirst = false;
													if( $isJson )
														echo '"uci":"' . $key . '"';
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
											echo '"status":"ok","move":"' . array_key_last( $statmoves ) . '"';
										else
											echo 'move:' . array_key_last( $statmoves );
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
							if( isset( $_REQUEST['stable'] ) ) {
								$stable = is_true( $_REQUEST['stable'] );
							}
							else {
								$stable = false;
							}
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$sieve = false;
							if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && substr( hash( 'md5', $_REQUEST['board'] . $_REQUEST['token'] ), 0, 2 ) == '00' )
								$sieve = true;
							$redis = new Redis();
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							$statmoves = getAnalysisPath( $redis, $row, $banmoves, 0, 200, true, $learn, 0, $pv, $stable, $sieve );
							if( count( $statmoves ) > 0 ) {
								if( $isJson )
									echo '"status":"ok","score":' . $statmoves[$pv[0]] . ',"depth":' . count( $pv ) . ',"pv":["' . implode( '","', $pv ) . '"]';
								else
									echo 'score:' . $statmoves[$pv[0]] . ',depth:' . count( $pv ) . ',pv:' . implode( '|', $pv );
							}
							else {
								$allmoves = ccbmovegen( $row );
								if( count( $allmoves ) > 0 ) {
									if( $isJson )
										echo '"status":"unknown"';
									else
										echo 'unknown';
								}
								else {
									if( ccbincheck( $row ) ) {
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
						else if( $action == 'queryscore' ) {
							$redis = new Redis();
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							list( $statmoves, $variations ) = getMoves( $redis, $row, $banmoves, true, true, true, 0 );
							if( count( $statmoves ) > 0 ) {
								arsort( $statmoves );
								$maxscore = reset( $statmoves );
								if( $isJson ) {
									if( isset( $variations['ply'] ) ) {
										$ply = $variations['ply'];
										$side = substr( $row, strpos( $row, ' ' ) + 1, 1 );
										if( $side == 'w' ) {
											if( $ply & 1 )
												$ply++;
										}
										else {
											if( ( $ply & 1 ) == 0 )
												$ply++;
										}
										echo '"status":"ok","eval":' . $maxscore . ',"ply":' . $ply;
									} else {
										echo '"status":"ok","eval":' . $maxscore;
									}
								}
								else
									echo 'eval:' . $maxscore;
							}
							else {
								$allmoves = ccbmovegen( $row );
								if( count( $allmoves ) > 0 ) {
									if( $isJson )
										echo '"status":"unknown"';
									else
										echo 'unknown';
								}
								else {
									if( ccbincheck( $row ) ) {
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
						else if( $action == 'queue' ) {
							$GLOBALS['counter'] = 0;
							$GLOBALS['boardtt'] = new Judy( Judy::STRING_TO_INT );
							$sieve = false;
							if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && substr( hash( 'md5', $_REQUEST['board'] . $_REQUEST['token'] ), 0, 2 ) == '00' )
								$sieve = true;
							$redis = new Redis();
							$redis->pconnect('dbserver.internal', 8889, 5.0);
							$statmoves = getMovesWithCheck( $redis, $row, array(), 0, 200, true, true, 0, $sieve );
							if( count( $statmoves ) >= 5 ) {
								if( $isJson )
									echo '"status":"ok"';
								else
									echo 'ok';
							}
							else if( count_pieces( $row ) >= 10 && count_attackers( $row ) >= 4 && count( ccbmovegen( $row ) ) > 0 ) {
								updateSel( $row, $sieve ? 2 : 0 );
								if( $isJson )
									echo '"status":"ok"';
								else
									echo 'ok';
							}
						}
						else if( $action == 'queryengine' ) {
							if( isset( $_REQUEST['token'] ) && !empty( $_REQUEST['token'] ) && isset( $_REQUEST['movelist'] ) && substr( hash( 'md5', $_REQUEST['board'] . $_REQUEST['movelist'] . $_REQUEST['token'] ), 0, 2 ) == '00' ) {
								$movelist = array();
								$isvalid = true;
								$lastfen = $row;
								if( !empty( $_REQUEST['movelist'] ) ) {
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
											$lastfen = $nextfen;
										}
									}
									else
										$isvalid = false;
								}
								if( $isvalid ) {
									$cachekey = 'Engine::' . $lastfen . hash( 'md5', $row . implode( $movelist ) );
									$result = $memcache_obj->get( $cachekey );
									if( $result === FALSE ) {
										$memcache_obj->add( 'EngineCount', 0 );
										$engcount = $memcache_obj->increment( 'EngineCount' );
										$result = getEngineMove( $row, $movelist, 5 - min( 4, $engcount / 2 ) );
										$memcache_obj->decrement( 'EngineCount' );
										$memcache_obj->add( $cachekey, $result, 0, 30 );
									}
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
			if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
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
			$fq = new FlexibleQueueDBClient( '/run/ccqueue/ccqueue.sock' );
			$docs = $fq->refresh( 1, time() - 3600, time(), 1 );
			if( count( $docs ) > 0 && isset( $docs[0]['key'] ) ) {
				echo $docs[0]['value'];
			}
			else
			{
				$docs = $fq->pop( 0, 10, 0 );
				$queueout = '';
				$thisminute = date('i');
				foreach( $docs as $doc ) {
					$fen = ccbhexfen2fen( bin2hex( $doc['key'] ) );
					if( count_pieces( $fen ) >= 10 && count_attackers( $fen ) >= 4 ) {
						$moves = array();
						$values = explode( ",", $doc['value'] );
						foreach( $values as $key ) {
							if( $memcache_obj->add( 'QueueHistory::' . $fen . $key, 1, 0, 300 ) )
								$moves[] = $key;
						}
						if( count( $moves ) > 0 ) {
							$queueout .= $fen . "\n";
							foreach( $moves as $move )
								$queueout .= $fen . ' moves ' . $move . "\n";
							$memcache_obj->add( 'QueueCount::' . $thisminute, 0, 0, 150 );
							$memcache_obj->increment( 'QueueCount::' . $thisminute );
						}
					}
				}
				if( strlen( $queueout ) > 0 ) {
					$fq->push( 1, hex2bin( hash( 'md5', $queueout ) ), $queueout, 0, time(), true, false);
					echo $queueout;
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
			$fq = new FlexibleQueueDBClient( '/run/ccqueue/ccqueue.sock' );
			$fq->remove( 1, hex2bin( $_REQUEST['key'] ) );
			echo 'ok';
		}
		else {
			echo 'tokenerror';
		}
	}
	else if( $action == 'getsel' ) {
		if( isset( $_REQUEST['token'] ) && $_REQUEST['token'] == hash( 'md5', 'ChessDB' . $_SERVER['REMOTE_ADDR'] . $MASTER_PASSWORD ) ) {
			$memcache_obj = new Memcache();
			if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
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
			$fq = new FlexibleQueueDBClient( '/run/ccsel/ccsel.sock' );
			$docs = $fq->refresh( 1, time() - 3600, time(), 1 );
			if( count( $docs ) > 0 && isset( $docs[0]['key'] ) ) {
				echo $docs[0]['value'];
			}
			else
			{
				$docs = $fq->pop( 0, 10, 1 );
				$selout = '';
				$thisminute = date('i');
				foreach( $docs as $doc ) {
					$fen = ccbhexfen2fen( bin2hex( $doc['key'] ) );
					if( count_pieces( $fen ) >= 10 && count_attackers( $fen ) >= 4 )
					{
						if( $doc['priority'] > 1 )
						{
							if( $memcache_obj->add( 'SelHistory::!' . $fen, 1, 0, 300 ) )
							{
								$memcache_obj->add( 'SelHistory::' . $fen, 1, 0, 300 );
								$selout .= '!' . $fen . "\n";
							}
						}
						else {
							if( $memcache_obj->add( 'SelHistory::' . $fen, 1, 0, 300 ) )
								$selout .= $fen . "\n";
						}
						$memcache_obj->add( 'SelCount::' . $thisminute, 0, 0, 150 );
						$memcache_obj->increment( 'SelCount::' . $thisminute );
					}
				}
				if( strlen( $selout ) > 0 ) {
					$fq->push( 1, hex2bin( hash( 'md5', $selout ) ), $selout, 0, time(), true, false);
					echo $selout;
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
			$fq = new FlexibleQueueDBClient( '/run/ccsel/ccsel.sock' );
			$fq->remove( 1, hex2bin( $_REQUEST['key'] ) );
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
catch (Exception $e) {
	error_log( get_class($e) . ': ' . $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
