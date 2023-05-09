<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");
header('X-Accel-Buffering: no');
header('Content-Type: text/event-stream; charset=utf-8');

try{
	if( !isset( $_REQUEST['action'] ) || !isset( $_SERVER['HTTP_REFERER'] ) || empty( $_SERVER['HTTP_REFERER'] ) ) {
		echo "data: [DONE]\n\n";
		exit(0);
	}
	$action = $_REQUEST['action'];

	$lang = 0;
	if( isset( $_REQUEST['lang'] ) ) {
		$lang = intval($_REQUEST['lang']);
	}
	$langCode = ($lang === 0) ? 'ZH' : 'EN';

	$memcache_obj = new Memcache();
	if( !$memcache_obj->pconnect('unix:///run/memcached/memcached.sock', 0) )
		throw new Exception( 'Memcache error.' );
	$ratekey = 'QLimit2::' . $_SERVER['REMOTE_ADDR'];
	$memcache_obj->add( $ratekey, 0, 0, 1 );
	$querylimit = $memcache_obj->increment( $ratekey );
	if( $querylimit === FALSE || $querylimit <= 2 )
	{
		$memcache_obj->delete( 'QLimit::' . $_SERVER['SERVER_ADDR'] );
		if( isset( $_REQUEST['board'] ) && !empty( $_REQUEST['board'] ) ) {
			if( $action == 'ccllm' ) {
				$row = ccbgetfen( $_REQUEST['board'] );
				if( isset( $row ) && !empty( $row ) ) {
					if( isset( $_REQUEST['egtbmetric'] ) ) {
						$dtmtb = strcasecmp( $_REQUEST['egtbmetric'], 'dtc' ) == 0 ? false : true;
					}
					else {
						$dtmtb = true;
					}
					$game = 'XIANGQI';
					$moves_json = file_get_contents( 'http://www.chessdb.cn/chessdb.php?action=queryall&json=1&board=' . urlencode( $row ) . '&egtbmetric=' . ( $dtmtb ? 'dtm' : 'dtc' ) );
				}
			}
			else if( $action == 'cllm' ) {
				list( $row, $frc ) = cbgetfen( $_REQUEST['board'] );
				if( isset( $row ) && !empty( $row ) ) {
					$game = 'CHESS';
					$moves_json = file_get_contents( 'http://www.chessdb.cn/cdb.php?action=queryall&json=1&board=' . urlencode( $row ) );
				}
			}
			if( isset( $game ) && isset( $moves_json ) ) {
				$parsed = json_decode( $moves_json, true );
				if( isset( $parsed['status'] ) && $parsed['status'] == 'ok' ) {
					$top = $parsed['moves'][0];
					$score = intval( $top['score'] );

					$cached = $memcache_obj->get( 'LLMCache::' . $game . $langCode . $row . $top['uci'] . $score );
					if( $cached !== FALSE ) {
						if( is_array( $cached ) ) {
							foreach ($cached as $c) {
								echo "data: " . $c . "\n\n";
								@ob_flush(); @flush();
								usleep(20 * 1000);
							}
						}
						else
							echo "data: " . $cached . "\n\n";
					}
					else {
						$fen_fragments = explode( ' ', $row );
						$stm = $fen_fragments[1];
						if( $game === 'CHESS' ) $sideToMoveName = ( $stm === 'w' ) ? 'White' : 'Black';
						else $sideToMoveName = ($stm === 'w') ? 'Red' : 'Black';

						$moves_eval = array();
						foreach( $parsed['moves'] as $move ) {
							if( count( $moves_eval ) > 4 )
								break;
							if( $game === 'CHESS' )
								$moves_eval[] = array( 'san' => $move['san'], 'score' => $move['score'] );
							else
								$moves_eval[] = array( 'uci' => $move['uci'], 'score' => $move['score'] );
						}
						$moves_eval_json = json_encode($moves_eval, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

						$abs_score = abs( $score );
						if( $abs_score < 50 ) $tier = 'roughly equal';
						else if( $abs_score < 150 ) $tier = ( $score >= 0 ? 'slight edge for ' . $sideToMoveName : 'slight edge against ' . $sideToMoveName );
						else $tier = ( $score >= 0 ? 'clear edge for ' . $sideToMoveName  : 'clear edge against ' . $sideToMoveName );

						$decisive = null;
						$note = $top['note'];
						if( strpos( $note, 'W' ) !== false ) $decisive = 'tablebase win for ' . $sideToMoveName;
						else if( strpos( $note, 'L' ) !== false ) $decisive = 'tablebase loss for ' . $sideToMoveName;
						else if( strpos( $note, 'D' ) !== false ) $decisive = 'tablebase draw';
						else if( $abs_score >= 10000 ) {
							$decisive = ( $score > 0 ) ? 'forced mate for ' . $sideToMoveName : 'forced mate against ' . $sideToMoveName;
						}
						$system = <<<EOT
ROLE

You are a professional commentator for CHESS and XIANGQI positions. You provide one concise paragraph of commentary from the SIDE_TO_MOVE perspective.

INPUTS

LANG: ZH = Simplified Chinese or EN = English.
GAME: CHESS or XIANGQI.
FEN: string in the game's standard format.
SIDE_TO_MOVE: "White" or "Black" (Chess), "Red" or "Black" (Xiangqi).
MOVES_EVAL: evaluation of top moves, ordered by descending preference.
TIER_LABEL: text label describing evaluation strength (e.g., "roughly equal", "clear edge").
DECISIVE: optional override string (e.g., "tablebase win", "forced mate").

XIANGQI ONLY (IGNORE FOR CHESS)

- Xiangqi FEN letter to piece convention: k = general, r = rook, n = knight, c = cannon, a = advisor, b = elephant, p = pawn.
- No castling, pawn double-push, en passant, or promotions exist in xiangqi.
- Forbidden: any description or implication of piece movement, strategy suggestions, or next-step actions.
- Must not confuse with chess-specific tactics (e.g., center control, open files).

COMMENTARY RULES

- Always describe the position from the SIDE_TO_MOVE perspective.
- If DECISIVE is present, state it plainly once; it overrides TIER_LABEL.
- Otherwise, use TIER_LABEL to describe the balance of the game.
- Scores in MOVES_EVAL are centipawn evaluations (scale: 100 = one pawn's worth) oriented in the SIDE_TO_MOVE perspective.
- Base reasoning only on general features implied by the evaluation (e.g., initiative, pressure, stability).
- Must not invent tactics, openings, or deep variations not directly implied by the evaluation.
- Must not mention FEN, UCI move notations or scores.
- Keep terminology strictly within the rules of the declared GAME.

OUTPUT FORMAT

- One short paragraph.
- Neutral, coaching tone.
- No board coordinates or piece locations.
- Written in LANG including the translated SIDE_TO_MOVE for the corresponding GAME.
- No lists, markdowns, quotation marks or code fences.
EOT;

						$user = <<<EOT
LANG: {$langCode}
GAME: {$game}
FEN: {$row}
SIDE_TO_MOVE: {$sideToMoveName}
MOVES_EVAL: {$moves_eval_json}
TIER_LABEL: {$tier}
DECISIVE: {$decisive}
EOT;

						$payload = [
							"model" => "openai/gpt-oss-120b",
							"messages" => [
								["role" => "system", "content" => $system],
								["role" => "user",   "content" => $user],
							],
							"max_tokens"  => 2048,
							"temperature" => 0.6,
							"stream" => true
						];

						$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
						$buffer = '';
						$inThink = false;
						$final = array();
						$ch = curl_init( "http://localhost:8000/v1/chat/completions" );
						curl_setopt_array($ch, [
							CURLOPT_POST => true,
							CURLOPT_HTTPHEADER => ["Content-Type: application/json", 'Accept: text/event-stream'],
							CURLOPT_POSTFIELDS => $json,
							CURLOPT_RETURNTRANSFER => false,
							CURLOPT_TIMEOUT => 30,
							CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$inThink, &$final) {
								$buffer .= $chunk;
								while (($newlinePos = strpos($buffer, "\n")) !== false) {
									$line = substr($buffer, 0, $newlinePos);
									$buffer = substr($buffer, $newlinePos + 1);

									$line = rtrim($line, "\r");

									if (stripos($line, 'data:') === 0) {
										$stream_payload = trim(substr($line, 5));
										if ($stream_payload === '[DONE]') {
											echo "data: [DONE]\n\n";
											@ob_flush(); @flush();
											continue;
										}
										$stream_json = json_decode($stream_payload, true);
										if (!empty($stream_json['choices'][0]['delta']['content'])) {
											$piece = $stream_json['choices'][0]['delta']['content'];
											$output = '';
											while ($piece !== '') {
												if (!$inThink) {
													$pos = stripos($piece, '<think>');
													if ($pos === false) {
														$output .= $piece;
														$piece = '';
													} else {
														$output .= substr($piece, 0, $pos);
														$piece = substr($piece, $pos + 7);
														$inThink = true;
													}
												} else {
													$pos = stripos($piece, '</think>');
													if ($pos === false) {
														$piece = '';
													} else {
														$piece = substr($piece, $pos + 8);
														$inThink = false;
													}
												}
											}
											if ($output !== '') {
												$output = str_replace( array( "\r", "\n" ), '', $output );
												$final[] = $output;
												echo "data: {$output}\n\n";
												@ob_flush(); @flush();
											}
										}
									}
								}
								return strlen($chunk);
							}
						]);

						curl_exec($ch);
						if ($err = curl_error($ch)) {
							curl_close($ch);
							throw new RuntimeException("cURL error: " . $err);
						}
						$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
						curl_close($ch);
						if ($status != 200) {
							throw new RuntimeException("cURL status: " . $status);
						}
						if( count( $final ) ) {
							$memcache_obj->set( 'LLMCache::' . $game . $langCode . $row . $top['uci'] . $score, $final, 0, 3600 );
						}
					}
				}
			}
		}
		echo "data: [DONE]\n\n";
	} else {
		echo "retry: 1000\n\n";
	}
}
catch (Exception $e) {
	error_log( get_class($e) . ': ' . $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
