<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header('Content-Type: text/plain; charset=utf-8');

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
	if (function_exists('fpm_get_status')) {
		$fpm = fpm_get_status();
		if (isset($fpm['accepted-conn'])) {
			echo "# HELP php_fpm_accepted_connections_total Total FastCGI requests accepted by the PHP-FPM pool since start\n";
			echo "# TYPE php_fpm_accepted_connections_total counter\n";
			echo 'php_fpm_accepted_connections_total{pool="'.$fpm['pool'].'"} '.(int)$fpm['accepted-conn']."\n";
		}
	}
}
catch (Exception $e) {
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
