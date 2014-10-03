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

// Return start and end datetime of given period
function agPeriodInfo($period_type,$period,$period_year)
{
	global $LA;

	# initialize begin time
	$pbegin = new DateTime();
	$pbegin->setTimeZone(new DateTimeZone($LA['timezone']));
	$pbegin->setTime(0,0,0);

	// fill begin and end time
	switch ($period_type)
	{
		case 'w':
			$pbegin->setISODate($period_year,$period,1); // start on monday
			$pend = clone $pbegin;
			$pend->add(new DateInterval('P1W'));
			break;
		case 'm':
			$pbegin->setDate($period_year,$period,1);
			$pend = clone $pbegin;
			$pend->add(new DateInterval('P1M'));
			break;
		case 'q':
			$pbegin->setDate($period_year,3*$period-2,1);
			$pend = clone $pbegin;
			$pend->add(new DateInterval('P3M'));
			break;
		case 'y':
			$pbegin->setDate($period_year,1,1);
			$pend = clone $pbegin;
			$pend->add(new DateInterval('P1Y'));
			break;
		case 'a':
			$pbegin->setDate($period_year,9,1);
			$pend = clone $pbegin;
			$pend->add(new DateInterval('P1Y'));
			break;
	}
	$pend->sub(new DateInterval('PT1S'));

	return array($pbegin,$pend);
}

# handle the actual aggregation for a given day and period
function agHandlePeriod($day_id,$env,$period_type,$period,$period_year)
{
	global $LA;
	$con = $LA['mysql_link_stats'];

	#print "  - $period_type: $period_year-$period...";
	print $period_type;

	mysql_query("START TRANSACTION", $con);

	list($period_start,$period_end) = agPeriodInfo($period_type,$period,$period_year);
	$pbegin = $period_start->format('Y-m-d H:i:s');
	$pend   = $period_end  ->format('Y-m-d H:i:s');

	# create a new entry for the period in log_analyze_period
	# note teh trick to make insert_id meaningful even if the row was only 
	# updated (see http://dev.mysql.com/doc/refman/5.0/en/insert-on-duplicate.html )
	$q = "
		INSERT INTO `log_analyze_period` 
		(`period_type`, `period_period`, `period_year`, `period_environment`, `period_from`, `period_to`) 
		VALUES ('{$period_type}', {$period}, {$period_year}, '${env}', '{$pbegin}', '{$pend}')
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
			`period_id`   int(10) unsigned NOT NULL,
			`provider_id` int(10) NOT NULL,
			`name`        char(40) NOT NULL,
			UNIQUE KEY (`provider_id`,`name`),
			FOREIGN KEY (period_id)   REFERENCES log_analyze_period   (period_id),
			FOREIGN KEY (provider_id) REFERENCES log_analyze_provider (provider_id)
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
		(`period_id`,`provider_id`,`name`)
		SELECT $period_id,`user_provider_id`,`user_name` 
			FROM `log_analyze_days__{$day_id}`
	";
	$result = mysql_query($q,$con);
	if (!$result) {
		catchMysqlError("agHandlePeriod 3", $con);
		return;
	}

	mysql_query("COMMIT", $con);

	return $period_id;
}

function agPeriodTotals($periods)
{
	global $LA;

	$periods = array_unique( array_values($periods) );
	sort($periods);

	print "Calculating totals\n";

	foreach ($periods as $period)
	{
		mysql_query("START TRANSACTION", $LA['mysql_link_stats']);

		print "period: $period ";

		# fetch days belonging to the currect period
		$q = "
			SELECT d.day_id,d.day_logins
			FROM log_analyze_period as p
			INNER JOIN log_analyze_day as d	
				ON  d.day_environment=p.period_environment
				AND d.day_day BETWEEN p.period_from AND p.period_to 
			WHERE p.period_id=$period
			ORDER BY day_day;
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result || mysql_num_rows($result)==0) {
			catchMysqlError("agPeriodTotals: no days found for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		$selects = array();
		$tot_logins = 0;
		while ($row = mysql_fetch_assoc($result)) 
		{
			$selects[] = "SELECT DISTINCT user_name FROM log_analyze_days__{$row['day_id']}";
			$tot_logins += $row['day_logins'];
		}
		$q = "SELECT COUNT(DISTINCT user_name) FROM ( " . implode(' UNION ', $selects) . ") foo";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: query failed: {$q}", $LA['mysql_link_stats']);
			continue;
		}
		$row = mysql_fetch_array($result);
		$tot_users = $row[0];

		print " --> logins $tot_logins, users $tot_users\n";

		$q = "
			UPDATE log_analyze_period
			SET period_logins={$tot_logins}, period_users={$tot_users}
			WHERE period_id = {$period}
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while updating period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		# save users per (period,idp,sp), by counting users in log_analyze_periods__%
		$q = "
			INSERT INTO log_analyze_periodstats
				(periodstats_period_id, periodstats_idp_id,periodstats_sp_id,periodstats_users)
				SELECT s.period_id, p.provider_idp_id, p.provider_sp_id, @cnt := count(s.name) AS cnt
					FROM log_analyze_periods__{$period} AS s
					LEFT JOIN log_analyze_provider AS p on s.provider_id=p.provider_id
				GROUP BY p.provider_id
			ON DUPLICATE KEY UPDATE periodstats_users = @cnt;
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while updating periodstats for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}
		
		# save logins per (idp,sp), from log_analyze_stats
		$q = "
			INSERT INTO log_analyze_periodstats
				(periodstats_period_id, periodstats_idp_id, periodstats_sp_id, periodstats_logins)
				SELECT p.period_id, prov.provider_idp_id, prov.provider_sp_id, @cnt := SUM(s.stats_logins) AS cnt
					FROM log_analyze_stats as s
					LEFT JOIN log_analyze_provider AS prov
						ON s.stats_provider_id = prov.provider_id
					LEFT JOIN log_analyze_day AS d
						ON s.stats_day_id = d.day_id
					LEFT JOIN log_analyze_period AS p
						ON d.day_day BETWEEN p.period_from AND p.period_to
						AND d.day_environment = p.period_environment
				WHERE p.period_id={$period}
				GROUP BY prov.provider_id
			ON DUPLICATE KEY UPDATE periodstats_logins = @cnt;
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while updating periodstats for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		# save totals per idp and per sp
		# manually delete the rows we're going to insert below
		# as MySQL doesn't support partial indices
		$q = "
			DELETE FROM log_analyze_periodstats
				WHERE periodstats_period_id={$period}
				AND (periodstats_sp_id IS NULL OR periodstats_idp_id IS NULL);
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while calculating periodstats for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		# total logins per period per sp
		# fetched by summing over all idps for this (period,sp) in log_analyze_periodstats
		$q = "
			INSERT INTO log_analyze_periodstats
				  (periodstats_period_id, periodstats_idp_id, periodstats_sp_id, periodstats_logins)
			SELECT periodstats_period_id, NULL,               periodstats_sp_id, SUM(periodstats_logins)
				FROM log_analyze_periodstats
				WHERE periodstats_period_id={$period} AND periodstats_sp_id IS NOT NULL
				GROUP BY periodstats_sp_id;
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while calculating periodstats for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		# total logins per period per idp
		# fetched by summing over all sps for this (period,idp) in log_analyze_periodstats
		$q = "
			INSERT INTO log_analyze_periodstats
				  (periodstats_period_id, periodstats_idp_id, periodstats_sp_id, periodstats_logins)
			SELECT periodstats_period_id, periodstats_idp_id, NULL,              SUM(periodstats_logins)
				FROM log_analyze_periodstats
				WHERE periodstats_period_id={$period} AND periodstats_idp_id IS NOT NULL
				GROUP BY periodstats_idp_id;
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while calculating periodstats for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		# total users per period per sp
		# fetched by counting unqiue users in log_analyze_periods__% for a fixed (period,sp)
		$q = "
			UPDATE log_analyze_periodstats as ps
			SET ps.periodstats_users=(
				SELECT COUNT(DISTINCT s.name) AS cnt
					FROM log_analyze_periods__{$period} AS s
					LEFT JOIN log_analyze_provider AS p ON s.provider_id=p.provider_id
				WHERE p.provider_sp_id=ps.periodstats_sp_id)
			WHERE ps.periodstats_period_id={$period} AND ps.periodstats_idp_id IS NULL;
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while calculating periodstats for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		# total users per period per idp
		# fetched by counting unqiue users in log_analyze_periods__% for a fixed (period,idp)
		$q = "
			UPDATE log_analyze_periodstats as ps
			SET ps.periodstats_users=(
				SELECT COUNT(DISTINCT s.name) AS cnt
					FROM log_analyze_periods__{$period} AS s
					LEFT JOIN log_analyze_provider AS p ON s.provider_id=p.provider_id
				WHERE p.provider_idp_id=ps.periodstats_idp_id)
			WHERE ps.periodstats_period_id={$period} AND ps.periodstats_sp_id IS NULL;
		";
		$result = mysql_query($q,$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agPeriodTotals: error while calculating periodstats for period {$period}, query was: $q", $LA['mysql_link_stats']);
			continue;
		}

		mysql_query("COMMIT", $LA['mysql_link_stats']);
	}
}

# update the number of unique users for this day
function agCalcDayTotals($day_id)
{
	global $LA;

	$q = "
		update log_analyze_day
		set day_users = (
			select count(distinct user_name) from log_analyze_days__{$day_id}
		)
		where day_id={$day_id};
	";
	$result = mysql_query($q,$LA['mysql_link_stats']);
	if (!$result) {
		catchMysqlError("agCalcDayTotals($day_di)", $LA['mysql_link_stats']);
		return;
	}
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
		# day is included too
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


	# array to keep track of which periods have changed 
	$periods = array();
	# iterate over days, and add each day's users to the period's users
	foreach ($process_dates as $d)
	{
		#look up id and environment
		$result = mysql_query("SELECT day_id,day_environment FROM log_analyze_day WHERE day_day='{$d}' ORDER BY day_day,day_environment",$LA['mysql_link_stats']);
		if (!$result) {
			catchMysqlError("agAggregate: day {$d} not found", $LA['mysql_link_stats']);
			return;
		}

		print "Aggregating $d: ";

		# and do the actual aggregation for each date/environment
		$date = agParseDate($d);
		while ($row = mysql_fetch_assoc($result)) {
			$day_id = $row['day_id'];
			$env    = $row['day_environment'];

			print "[$env]:";

			agCalcDayTotals($day_id);
			$periods[] = agHandlePeriod($day_id,$env,'w',$date['w'],$date['wy']);
			$periods[] = agHandlePeriod($day_id,$env,'m',$date['m'],$date['y']);
			$periods[] = agHandlePeriod($day_id,$env,'q',$date['q'],$date['y']);
			$periods[] = agHandlePeriod($day_id,$env,'y',$date['y'],$date['y']);
			$periods[] = agHandlePeriod($day_id,$env,'a',$date['a'],$date['ay']);

			print " ";
		}
		print "\n";
	}

	# update the totals of all periods we've touched
	agPeriodTotals($periods);

	return count($process_dates);

}

# todo: not used any more
# calculate relationships (child/parent) between entity (SP/IdP) revisions
function agEntityRelations()
{
	global $LA;
	$con = $LA['mysql_link_stats'];

	mysql_query("START TRANSACTION", $con);

	foreach (array('sp','idp') as $t)
	{
		# first reset everything
		$result = mysql_query("update log_analyze_{$t} set {$t}_child_id=null, {$t}_parent_id=null;",$con);
		if (!$result) {
			catchMysqlError("agEntityRelation reset", $con);
			return;
		}

		# fetch all entities
		$result = mysql_query("select * from log_analyze_{$t} where {$t}_eid>0 order by {$t}_eid,{$t}_revision;",$con);
		if (!$result) {
			catchMysqlError("agEntityRelation", $con);
			return;
		}

		$prev_id  = -1;
		$prev_eid = -1;
		$prev_rev = -1;
		while ($row = mysql_fetch_assoc($result))
		{
			$id  = $row["{$t}_id"];
			$eid = $row["{$t}_eid"];
			$rev = $row["{$t}_revision"];

			# don't update on the first revision of an entity (has no parent, and
			# the child is set on the next iteration anyway)
			if ($eid==$prev_eid)
			{
				$r1 = mysql_query("
					UPDATE log_analyze_{$t} SET {$t}_child_id =$id WHERE {$t}_id=$prev_id
				",$con);
				$r2 = mysql_query("
					UPDATE log_analyze_{$t} SET {$t}_parent_id=$prev_id WHERE {$t}_id=$id
				",$con);
				if (!$r1 || !$r2)
				{
					catchMysqlError("agEntityRelation (update)", $con);
					return;
				}
			}
			$prev_id  = $id;
			$prev_eid = $eid;
		}

	}
	mysql_query("COMMIT", $con);
}


?>
