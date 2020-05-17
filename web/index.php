<?php
if( !empty( $_SERVER['QUERY_STRING'] ) ) {
	if( ccbgetfen( urldecode( $_SERVER['QUERY_STRING'] ) ) )
		header( "Location: https://www.chessdb.cn/query/?" . $_SERVER['QUERY_STRING'] );
	else if( cbgetfen( urldecode( $_SERVER['QUERY_STRING'] ) ) )
		header( "Location: https://www.chessdb.cn/queryc/?" . $_SERVER['QUERY_STRING'] );
	else
		header( "Location: https://www.chessdb.cn/query/" );
} else {
	header( "Location: https://www.chessdb.cn/query/" );
}
