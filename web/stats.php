<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header("Access-Control-Allow-Origin: *");
header('Content-Type: text/html; charset=utf-8');

final class FlexibleQueueDBClient
{
    private $sock;
    private $socketPath;
    private $timeout; // connect timeout (seconds)

    /**
     * @param string $socketPath Path to Unix socket (default "/tmp/fqdb.sock")
     * @param float  $timeout    Connect timeout in seconds (default 1.0).
     */
    public function __construct($socketPath = "/tmp/fqdb.sock", $timeout = 1.0)
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

function sizeFilter( $bytes )
{
	$label = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
	for( $i = 0; $bytes >= 1024 && $i < ( count( $label ) -1 ); $bytes /= 1024, $i++ );
	return( number_format( $bytes, 2, '.', '' ) . " " . $label[$i] );
}
function secondsToTime($seconds_time)
{
	$days = floor($seconds_time / 86400);
	if( $days > 99 )
		return 'INF';
	$hours = floor(($seconds_time - $days * 86400) / 3600);
	$minutes = floor(($seconds_time - ($days * 86400) - ($hours * 3600)) / 60);
	$seconds = floor($seconds_time - ($days * 86400) - ($hours * 3600) - ($minutes * 60));
	return str_pad( $days, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $hours, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $minutes, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $seconds, 2, '0', STR_PAD_LEFT );
}

try{
	$lang = 0;
	if( isset( $_REQUEST['lang'] ) ) {
		$lang = intval($_REQUEST['lang']);
	}
	if( isset( $_REQUEST['json'] ) ) {
		$isJson = is_true( $_REQUEST['json'] );
	}
	else {
		$isJson = false;
	}
	$redis = new Redis();
	$redis->pconnect('dbserver.internal', 8889, 1.0);
	$pos_count = $redis->dbsize();

	$fq_queue = new FlexibleQueueDBClient( '/run/ccqueue/ccqueue.sock' );
	$queue_count = $fq_queue->count(0, 0, 2);
	$queue_count_prio = $fq_queue->count(0, 1, 2);

	$fq_sel = new FlexibleQueueDBClient( '/run/ccsel/ccsel.sock' );
	$sel_count = $fq_sel->count(0, 0, 2);
	$sel_count_prio = $fq_sel->count(0, 1, 2);

	$egtb_count_dtc = 0;
	$egtb_size_dtc = 0;
	$egtb_count_dtm = 0;
	$egtb_size_dtm = 0;
	$memcache_obj = new Memcache();
	if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
		throw new Exception( 'Memcache error.' );
	$egtbstats = $memcache_obj->get( 'EGTBStats' );
	if( $egtbstats !== FALSE ) {
		$egtb_count_dtc = $egtbstats[0];
		$egtb_size_dtc = $egtbstats[1];
		$egtb_count_dtm = $egtbstats[2];
		$egtb_size_dtm = $egtbstats[3];
	} else {
		$egtbstats = file_get_contents( 'http://tbserver.internal/tbproxy.php?action=ccegtbstats' );
		if( $egtbstats !== FALSE ) {
			list( $egtb_count_dtc, $egtb_size_dtc, $egtb_count_dtm, $egtb_size_dtm ) = unserialize( $egtbstats );
			$memcache_obj->set( 'EGTBStats', array( $egtb_count_dtc, $egtb_size_dtc, $egtb_count_dtm, $egtb_size_dtm ), 0, 86400 );
		}
	}
	$nps = 0;
	$est = 0;
	$lastminute = date('i', time() - 60);
	$activelist = $memcache_obj->get('WorkerList');
	$queue = $memcache_obj->get('QueueCount::' . $lastminute);
	$sel = $memcache_obj->get('SelCount::' . $lastminute);
	if($activelist !== FALSE) {
		foreach($activelist as $key => $value) {
			$npn = $memcache_obj->get('Worker::' . $key . 'NC_' . $lastminute);
			if( $npn !== FALSE ) {
				$nps += $npn;
			}
		}
		$nps /= 60 * 1000 * 1000;
		$est = max( ( $queue_count + $sel_count ) / ( $queue + 1 ), $sel_count / ( $sel + 1 ) );
	}
	$memcache_obj->set('RateLimit', (int)max( 5, 1000 * pow( 1 + ( $queue_count_prio + $sel_count_prio * 5 ) / ( 10000 / ( 1000 / 5 - 1 ) ), -1 ) ) );
	if( $isJson ) {
		header('Content-type: application/json');
		echo '{"status":"ok","positions":' . $pos_count . ',"queue":{"scoring":' . $queue_count . ',"sieving":' . $sel_count . '},"worker":{"backlog":' . (int)($est * 60) . ',"speed":' . (int)($nps * 1000) . '},"egtb":{"count":{"dtc":' . $egtb_count_dtc . ',"dtm":' . $egtb_count_dtm . '},"size":{"dtc":' . $egtb_count_dtc . ',"dtm":' . $egtb_size_dtm . '}}}';
	} else {
		echo '<table class="stats">';
		if($lang == 0) {
			echo '<tr><td>局面数量（近似）：</td><td style="text-align: right;">' . number_format( $pos_count ) . '</td></tr>';
			echo '<tr><td>学习队列（评估 / 筛选）：</td><td style="text-align: right;">' . number_format( $queue_count ) . ' / ' . number_format( $sel_count ) . '</td></tr>';
			echo '<tr><td>后台计算（剩时 / 速度）：</td><td style="text-align: right;">' . secondsToTime( $est * 60 ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
			echo '<tr><td>残局库数量（ DTC / DTM ）：</td><td style="text-align: right;">' . number_format( $egtb_count_dtc ) . ' / ' . number_format( $egtb_count_dtm ) . '</td></tr>';
			echo '<tr><td>残局库体积（ DTC / DTM ）：</td><td style="text-align: right;">' . sizeFilter( $egtb_size_dtc ) . ' / ' . sizeFilter( $egtb_size_dtm ) . '</td></tr>';
		} else {
			echo '<tr><td>Position Count ( Approx. ) :</td><td style="text-align: right;">' . number_format( $pos_count ) . '</td></tr>';
			echo '<tr><td>Queue ( Scoring / Sieving ) :</td><td style="text-align: right;">' . number_format( $queue_count ) . ' / ' . number_format( $sel_count ) . '</td></tr>';
			echo '<tr><td>Worker ( Backlog / Speed ) :</td><td style="text-align: right;">' . secondsToTime( $est * 60 ) . ' @ ' . number_format( $nps, 3, '.', '' ) . ' GNPS</td></tr>';
			echo '<tr><td>EGTB Count ( DTC / DTM ) :</td><td style="text-align: right;">' . number_format( $egtb_count_dtc ) . ' / ' . number_format( $egtb_count_dtm ) . '</td></tr>';
			echo '<tr><td>EGTB File Size ( DTC / DTM ) :</td><td style="text-align: right;">' . sizeFilter( $egtb_size_dtc ) . ' / ' . sizeFilter( $egtb_size_dtm ) . '</td></tr>';
		}
		echo '</table>';
	}
}
catch (Exception $e) {
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}

