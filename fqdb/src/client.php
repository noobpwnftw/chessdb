<?php
/**
 * FlexibleQueueDBClient
 *
 * Wire (all little-endian):
 *   Request:  u16 type, u16 opcode, u32 payload_len, payload
 *   Response: u32 payload_len, payload
 *
 * Opcodes: 1=PUSH, 2=POP, 3=REFRESH, 4=REMOVE, 5=COUNT
 */
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
