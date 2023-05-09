<?php
ignore_user_abort(true);
header("Cache-Control: no-cache");
header("Pragma: no-cache");

try{
	if( !isset( $_REQUEST['action'] ) || $_SERVER['REMOTE_ADDR'] != '127.0.0.1' ) {
		exit(0);
	}
	$action = $_REQUEST['action'];
	if( isset( $_REQUEST['board'] ) && !empty( $_REQUEST['board'] ) ) {
		if( $action == 'ccegtbprobe' ) {
			$row = ccbgetfen( $_REQUEST['board'] );
			if( isset( $row ) && !empty( $row ) ) {
				if( isset( $_REQUEST['egtbmetric'] ) ) {
					$dtmtb = strcasecmp( $_REQUEST['egtbmetric'], 'dtc' ) == 0 ? false : true;
				}
				else {
					$dtmtb = true;
				}
				echo serialize( ccegtbprobe( $row, $dtmtb ) );
			}
		}
		else if( $action == 'cegtbprobe' ) {
			list( $row, $frc ) = cbgetfen( $_REQUEST['board'] );
			if( isset( $row ) && !empty( $row ) && $frc == 0 ) {
				echo file_get_contents( 'http://localhost:9000/standard?fen=' . urlencode( $row ) );
			}
		}
	}
	else if( $action == 'ccegtbstats' ) {
		$egtb_count_dtc = 0;
		$egtb_size_dtc = 0;
		$egtb_count_dtm = 0;
		$egtb_size_dtm = 0;
		$egtb_dirs_dtc = array( '/data/EGTB_DTC/' );
		foreach( $egtb_dirs_dtc as $dir ) {
			$dir_iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach( $dir_iter as $file ) {
				$egtb_count_dtc++;
				$egtb_size_dtc += $file->getSize();
			}
		}

		$egtb_dirs_dtm = array( '/data/EGTB_DTM/' );
		foreach( $egtb_dirs_dtm as $dir ) {
			$dir_iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach( $dir_iter as $file ) {
				$egtb_count_dtm++;
				$egtb_size_dtm += $file->getSize();
			}
		}
		echo serialize( array( $egtb_count_dtc, $egtb_size_dtc, $egtb_count_dtm, $egtb_size_dtm ) );
	}
	else if( $action == 'cegtbstats' ) {
		$egtb_count_wdl = 0;
		$egtb_size_wdl = 0;
		$egtb_count_dtz = 0;
		$egtb_size_dtz = 0;
		$egtb_dirs = array( '/data/syzygy/3-6men/', '/data/syzygy/7men/4v3_pawnful', '/data/syzygy/7men/4v3_pawnless', '/data/syzygy/7men/5v2_pawnful', '/data/syzygy/7men/5v2_pawnless', '/data/syzygy/7men/6v1_pawnful', '/data/syzygy/7men/6v1_pawnless' );
		foreach( $egtb_dirs as $dir ) {
			$dir_iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach( $dir_iter as $filename => $file ) {
				if( strpos($filename, '.rtbw') !== FALSE )
				{
					$egtb_count_wdl++;
					$egtb_size_wdl += $file->getSize();
				}
				else if( strpos($filename, '.rtbz') !== FALSE )
				{
					$egtb_count_dtz++;
					$egtb_size_dtz += $file->getSize();
				}
			}
		}
		echo serialize( array( $egtb_count_wdl, $egtb_size_wdl, $egtb_count_dtz, $egtb_size_dtz ) );
	}
}
catch (Exception $e) {
	error_log( $e->getMessage(), 0 );
	http_response_code(503);
	echo 'Error: ' . $e->getMessage();
}
