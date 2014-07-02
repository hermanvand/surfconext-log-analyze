<?php

# all aggregration stuff is in here
# i.e.: after all daily data is processed, we still need to aggregate the data 
# by week, month, quarter, and year


# save metadata about processed chunks to a file for later processing
# need to work with a tempfile, because the chunk metadata is only available 
# inside the child processes (see analyze.php)
# (yay, poor man's IPC!)
function agSaveChunkInfo($file,$chunk)
{
	# open file and lock
	$fh = fopen($file,'a');
	if (!$fh) {
		log2file("Couldn't open chunk info file `$file' for writing");
		return;
	}
	$result = flock($fh,LOCK_EX);
	if (!$result) {
		log2file("Couldn't get lock on file `$file'");
		return;
	}

	# write serialize representation of chunk
	fwrite($fh,serialize($chunk));
	fwrite($fh,"\n");

	# release lock, flush and close
	fflush($fh);
	flock($fh,LOCK_UN);
	fclose($fh);
}


# read chunks from file
# each line has a serialized chunk 
function agReadChunkInfo($file)
{
	$fh = fopen($file,'r');
	if (!$fh) {
		log2file("Couldn't open chunk info file `$file' for reading");
		return;
	}

	$chunks = array();
	while ($line = fgets($fh)) {
		# read serialized object, and convert dates
		$chunk = unserialize($line);
		$chunk['from'] = new DateTime($chunk['from']);
		$chunk['to'  ] = new DateTime($chunk['to'  ]);
		$chunks[] = $chunk;
	}
	fclose($fh);

	return $chunks;
}

function agParseDate($string)
{
	$date = new DateTime($string);
	$w  = $date->format('W')+0; # week
	$wy = $date->format('o')+0; # week-based year
	$m  = $date->format('n')+0; # month
	$my = $date->format('Y')+0; # calender year
	$q  = intval(($m-1)/3)+1;   # quarter
	$ay = ( $m>=9 ? $my : $my-1 ); # start year of academic year (starts on sep 1st)
	$a  = ($ay%100)*100 + ($ay%100)+1; # name of academic year (1314 etc)
	return array  (
		'w'  => $w,
		'wy' => $wy,
		'm'  => $m,
		'q'  => $q,
		'y'  => $my,
		'ay' => $ay,
		'a'  => $a
	);
}

# handle the actual aggregation for a given day and period
function agHandlePeriod($day_id,$env,$period_type,$period,$period_year)
{
	global $LA;
	$con = $LA['mysql_link_stats'];

	#print "  - $period_type: $period_year-$period...";
	print $period_type;

	mysql_query("START TRANSACTION", $con);

	# create a new entry for the period in log_analyze_period
	# note teh trick to make insert_id meaningful even if the row was only 
	# updated (see http://dev.mysql.com/doc/refman/5.0/en/insert-on-duplicate.html )
	$q = "
		INSERT INTO `log_analyze_period` 
		(`period_type`, `period_period`, `period_year`, `period_environment`) 
		VALUES ('{$period_type}', {$period}, {$period_year}, '${env}')
		ON DUPLICATE KEY UPDATE period_id=LAST_INSERT_ID(period_id)
	";
	$result = mysql_query($q,$con);
	if (!$result) {
		catchMysqlError("agHandlePeriod 1", $con);
		return;
	}
	$period_id = mysql_insert_id($con);

	# create the table log_analyze_period__NNNN to contain all unique users 
	# for this period
	$q = "
		CREATE TABLE IF NOT EXISTS `log_analyze_periods__{$period_id}` (
			`provider_id` int(10) unsigned NOT NULL,
			`name` char(40) NOT NULL,
			UNIQUE KEY (`provider_id`,`name`)
		);
	";
	$result = mysql_query($q,$con);
	if (!$result) {
		catchMysqlError("agHandlePeriod 2", $con);
		return;
	}

	# insert all unique users into the new table
	$q = "
		INSERT IGNORE INTO `log_analyze_periods__{$period_id}` 
		(`provider_id`,`name`)
		SELECT `user_provider_id`,`user_name` 
			FROM `log_analyze_days__{$day_id}`
	";
	$result = mysql_query($q,$con);
	if (!$result) {
		catchMysqlError("agHandlePeriod 3", $con);
		return;
	}

	mysql_query("COMMIT", $con);

	print "\n";
}


function agAggregate($file)
{
	global $LA;

	$chunks = agReadChunkInfo($file);
	if (!$chunks) return;

	# build an array of dates to process by iterating over all chunks
	$process_dates = array();
	foreach ($chunks as $chunk) {
		$d1 = $chunk['from'];
		$d2 = $chunk['to'];
		assert($d1<$d2);

		# we're only interested in the date, not the time.  Make sure the last 
		# day is oncluded too
		$d1->setTime(0,0,0);
		$d2->setTime(0,0,1);

		# add all found dates to the $process_dates array.
		$range = new DatePeriod($d1,new DateInterval('P1D'),$d2);
		foreach($range as $date) {
			$d = $date->format('Y-m-d');
			$process_dates[$d] = 1;
		}
	}

	# clean up and sort
	$process_dates = array_keys($process_dates);
	sort($process_dates, SORT_STRING);


	foreach ($process_dates as $d)
	{
		#look up id and environment
		$result = mysql_query("SELECT day_id,day_environment FROM log_analyze_day WHERE day_day='{$d}'",$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agAggregate: day {$date} not found", $LA['mysql_link_stats']);
			return;
		}

		# and do the actual aggregation for each date/environment
		$date = agParseDate($d);
		while ($row = mysql_fetch_assoc($result)) {
			$day_id = $row['day_id'];
			$env    = $row['day_environment'];

			print "Aggregating $d ($env): ";

			agHandlePeriod($day_id,$env,'w',$date['w'] ,$date['wy']);
			agHandlePeriod($day_id,$env,'m',$date['m'] ,$date['y']);
			agHandlePeriod($day_id,$env,'q',$date['q'] ,$date['y']);
			agHandlePeriod($day_id,$env,'y',$date['y'] ,$date['y']);
			agHandlePeriod($day_id,$env,'a',$date['ay'],$date['y']);

			print "\n";
		}
	}

	return count($process_dates);

}



?>
