<?php
if( !empty( $_SERVER['QUERY_STRING'] ) ) {
	if( ccbgetfen( urldecode( $_SERVER['QUERY_STRING'] ) ) ) {
		header( "Location: https://www.chessdb.cn/query/?" . $_SERVER['QUERY_STRING'] );
		return;
	}
	else {
		list( $row, $frc ) = cbgetfen( urldecode( $_SERVER['QUERY_STRING'] ) );
		if( isset( $row ) && !empty( $row ) ) {
			header( "Location: https://www.chessdb.cn/queryc/?" . $_SERVER['QUERY_STRING'] );
			return;
		}
	}
}
header( "Location: https://www.chessdb.cn/query/" );
