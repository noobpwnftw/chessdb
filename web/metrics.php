<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header('Content-Type: text/plain; charset=utf-8');

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

function collect_ssdb(string $instance, string $host, int $port): array {
	$redis = new Redis();
	$redis->pconnect($host, $port, 5.0);

	$out = ['keys' => (int)$redis->dbsize(), 'calls' => []];
	$calls = $redis->info('calls');
	if (isset($calls) && is_array($calls)) {
		$out['calls'] = $calls;
	}
	return $out;
}

function collect_queues(string $queue_sock, string $sel_sock): array {
	$fq_queue = new FlexibleQueueDBClient($queue_sock);
	$fq_sel = new FlexibleQueueDBClient($sel_sock);
	return [
		'scoring'           => $fq_queue->count(0, 0, 2),
		'scoring_prio'      => $fq_queue->count(0, 1, 2),
		'scoring_inflight'  => $fq_queue->count(1, 0, 0),
		'sieving'           => $fq_sel->count(0, 0, 2),
		'sieving_prio'      => $fq_sel->count(0, 1, 2),
		'sieving_inflight'  => $fq_sel->count(1, 0, 0),
	];
}

function collect_workers(Memcache $mc, string $list_key, string $worker_prefix): int {
	$lastminute = date('i', time() - 60);
	$nps = 0;
	$activelist = $mc->get($list_key);
	if ($activelist !== FALSE) {
		foreach ($activelist as $key => $value) {
			$npn = $mc->get($worker_prefix . $key . 'NC_' . $lastminute);
			if ($npn !== FALSE) {
				$nps += $npn;
			}
		}
		$nps *= 1000;
		$nps /= 60;
	}
	return (int)$nps;
}


try {
	$instances = [
		'chess'    => collect_ssdb('chess',    'dbserver.internal', 8888),
		'xiangqi'  => collect_ssdb('xiangqi',  'dbserver.internal', 8889),
	];
	echo "# HELP ssdb_keys Total number of keys in the database\n";
	echo "# TYPE ssdb_keys gauge\n";
	foreach ($instances as $instance => $data) {
		echo 'ssdb_keys{instance="'.$instance.'"} '.$data['keys']."\n";
	}

	echo "# HELP ssdb_commands_total Total number of commands processed since server start\n";
	echo "# TYPE ssdb_commands_total counter\n";
	foreach ($instances as $instance => $data) {
		foreach ($data['calls'] as $cmd => $n) {
			echo 'ssdb_commands_total{instance="'.$instance.'",command="'.$cmd.'"} '.(int)$n."\n";
		}
	}

	$memcache_obj = new Memcache();
	if (!$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0))
		throw new Exception('Memcache error.');

	echo "# HELP fqdb_size Number of tasks in the work queue\n";
	echo "# TYPE fqdb_size gauge\n";
	echo "# HELP fqdb_size_priority Number of priority tasks in the work queue\n";
	echo "# TYPE fqdb_size_priority gauge\n";
	echo "# HELP fqdb_size_inflight Number of tasks currently being processed\n";
	echo "# TYPE fqdb_size_inflight gauge\n";
	echo "# HELP chessdb_worker_speed Worker aggregate speed in nodes per second\n";
	echo "# TYPE chessdb_worker_speed gauge\n";
	echo "# HELP chessdb_worker_processed Tasks processed in the last minute\n";
	echo "# TYPE chessdb_worker_processed gauge\n";
	echo "# HELP chessdb_rate_limit Current rate limit value\n";
	echo "# TYPE chessdb_rate_limit gauge\n";
	$lastminute = date('i', time() - 60);
	foreach ([
		'chess'   => ['queue' => '/run/cqueue/cqueue.sock', 'sel' => '/run/csel/csel.sock', 'workers' => 'WorkerList2', 'wp' => 'Worker2::', 'qc' => 'QueueCount2::', 'sc' => 'SelCount2::', 'rl' => 'RateLimit2'],
		'xiangqi' => ['queue' => '/run/ccqueue/ccqueue.sock', 'sel' => '/run/ccsel/ccsel.sock', 'workers' => 'WorkerList', 'wp' => 'Worker::', 'qc' => 'QueueCount::', 'sc' => 'SelCount::', 'rl' => 'RateLimit'],
	] as $instance => $cfg) {
		$q = collect_queues($cfg['queue'], $cfg['sel']);
		foreach (['scoring', 'sieving'] as $type) {
			echo 'fqdb_size{instance="'.$instance.'",type="'.$type.'"} '.$q[$type]."\n";
			echo 'fqdb_size_priority{instance="'.$instance.'",type="'.$type.'"} '.$q[$type.'_prio']."\n";
			echo 'fqdb_size_inflight{instance="'.$instance.'",type="'.$type.'"} '.$q[$type.'_inflight']."\n";
		}
		echo 'chessdb_worker_processed{instance="'.$instance.'",queue="scoring"} '.(int)$memcache_obj->get($cfg['qc'] . $lastminute)."\n";
		echo 'chessdb_worker_processed{instance="'.$instance.'",queue="sieving"} '.(int)$memcache_obj->get($cfg['sc'] . $lastminute)."\n";
		echo 'chessdb_worker_speed{instance="'.$instance.'"} '.collect_workers($memcache_obj, $cfg['workers'], $cfg['wp'])."\n";
		echo 'chessdb_rate_limit{instance="'.$instance.'"} '.(int)$memcache_obj->get($cfg['rl'])."\n";
	}

	if (function_exists('fpm_get_status')) {
		$fpm = fpm_get_status();
		if (isset($fpm['pool'])) {
			echo "# HELP php_fpm_accepted_connections_total Total FastCGI requests accepted by the PHP-FPM pool since start\n";
			echo "# TYPE php_fpm_accepted_connections_total counter\n";
			echo 'php_fpm_accepted_connections_total{pool="'.$fpm['pool'].'"} '.(int)$fpm['accepted-conn']."\n";
			echo "# HELP php_fpm_active_processes Number of currently active processes in the PHP-FPM pool\n";
			echo "# TYPE php_fpm_active_processes gauge\n";
			echo 'php_fpm_active_processes{pool="'.$fpm['pool'].'"} '.(int)$fpm['active-processes']."\n";
		}
	}
}
catch (Exception $e) {
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
