#!/usr/bin/php
<?php

############
### INIT ###
############

$total_time_start = microtime(true);

# define roots
# - script is in /bin, so one up for the real root
$user_root = getcwd();
$script_root = dirname(realpath(__FILE__));
$script_root .= "/..";

# read config & libs
require $script_root."/etc/config.php";
require $script_root."/lib/libs.php";
global $LA;

# open log
openLogFile($script_root);

# keep track of child processes
$currentProcesses = array();

# lookup array for entity information
# - fetch entities later when database is open
$entities = array();
$entities_sp_index = array();
$entities_idp_index = array();

###############
### SIGNALS ###
###############

# handle child signals
# - figure out which process ended
# - remove it from the currentProcesses list
declare(ticks = 1);

function sig_handler ($signo) {
	global $currentProcesses;
	switch ($signo) {
		case SIGCHLD:
			while ( ($pid = pcntl_wait ( $signo, WNOHANG )) > 0 ) {
				$signal = pcntl_wexitstatus ( $signo );
				if ($signal != 0) {
					echo "WARNING: ".$pid." exited with status ".$signal."!\n";
				}
				if ( isset($currentProcesses[$pid]) ) {
					echo "finished: ".$pid."\n";
					unset($currentProcesses[$pid]);
				}
				else {
					echo "WARNING: ".$pid." was not registered!\n";
				}

			}
			break;
	}
}

pcntl_signal(SIGCHLD, "sig_handler");

############
### MAIN ###
############

# get number of chunks from DB
openMysqlDb("DB");
$numberOfChunks = LaChunkNewCount();
list($entities,$entities_sp_index,$entities_idp_index) = getAllEntities();
closeMysqlDb();

# check for a max
if ($numberOfChunks > $LA['max_allowed_process_chunk'] && $LA['max_allowed_process_chunk'] != 0) {
	$numberOfChunks = $LA['max_allowed_process_chunk'];
}

# save for report
$total_numberOfChunks = $numberOfChunks;

log2file("Start processing ".$total_numberOfChunks." chunks with ".$LA['max_processes']." parallel processes.");

# loop while there are still chunks left
while ($numberOfChunks > 0) {
	$numberOfChunks--;

	# fork away
	$pid = pcntl_fork();
	if ($pid == -1) {
		# problem launching a new process
		echo "ERROR: could not launch a new process! Getting out of here...\n";
		break;
	}
	elseif ($pid) {
		# parent
		# - only register the new process and return to the main loop
		echo "started: ".$pid."\n";
		$currentProcesses[$pid] = 1;
	}
	else {
		# child

		##################
		### CHILD init ###
		##################

		$time_start = microtime(true);
		$child_link = openChildMysqlDb("DB");
		$chunk_logins = 0;

		##################
		### CHILD main ###
		##################
		
		$chunk = LaChunkNewGet($child_link);
		if (isset($chunk['id'])) {
			echo "processing chunk: ".$chunk['id']."\n";
			list($entries,$users) = getEntriesFromLogins($chunk['from'],$chunk['to'],$child_link);

			# entry fields to process
			# - $entries[$entry]['time']
			# - $entries[$entry]['sp']
			# - $entries[$entry]['idp']
			# - $entries[$entry]['sp_name']
			# - $entries[$entry]['idp_name']
			# - $entries[$entry]['sp_eid']
			# - $entries[$entry]['idp_eid']
			# - $entries[$entry]['sp_revision']
			# - $entries[$entry]['idp_revision']
			# - $entries[$entry]['sp_environment']
			# - $entries[$entry]['idp_environment']
			# - $entries[$entry]['sp_metadata']
			# - $entries[$entry]['idp_metadata']
			# - $entries[$entry]['count']
			foreach ($entries as $key => $entry) {

				# ignore entires form blacklisted entityids
				if ( in_array($entry['sp'], $LA['entity_blacklist']) ||
				     in_array($entry['idp'],$LA['entity_blacklist']) ) continue; 

				# first, check the day 
				# - note: two steps to make locking easier and faster
				$day_status = LaAnalyzeDayInsert($entry['time'],$entry['sp_environment'],$child_link);
				$day_id = LaAnalyzeDayUpdate($entry['time'],$entry['sp_environment'],$entry['count'],$child_link);
				
				# second, check the SP and IDP
				$provider_id = LaAnalyzeProviderUpdate($entry,$child_link);
				
				# third, update the main table with login count
				$stats_status = LaAnalyzeStatsUpdate($day_id,$provider_id,$entry['count'],$child_link);
			
				# fourth, update users table & update main table with new user count
				# - note, this might be the performance killer...
				if (! $LA['disable_user_count']) {
					$chunk_users = LaAnalyzeUserUpdate($day_id,$provider_id,$users[$key],$child_link);
					if ($chunk_users > 0) {
						$stats_status = LaAnalyzeStatsUpdateUser($day_id,$provider_id,$chunk_users,$child_link);
					}
				}
				
				$chunk_logins = $chunk_logins + $entry['count'];
			}

			# chunk done
			$done_status = LaChunkProcessUpdate($chunk['id'], $chunk_logins, $child_link);
			
		}
		else {
			echo "WARNING: no chunk processed\n";
		}
		
		###################
		### CHILD close ###
		###################
		
		closeChildMysqlDb($child_link);
		$time_end = microtime(true);

		# keep track of child times
		$time_play = $time_end - $time_start;
		if ($time_play > $LA['max_allowed_process_time']) {
			log2file("WARNING: process time exceeded: ".$time_play);
		}

		# clean exit of child
		sleep(1);
		exit(0);
	}

	# run maximum number of processes
	while (count($currentProcesses) >= $LA['max_processes']) {
		echo ".";
		sleep(1);
	}

}

# wait for all processes to finish
while(count($currentProcesses)){
	echo ".";
	sleep(1);
}

#############
### CLOSE ###
#############

$total_time_end = microtime(true);

# keep track of times
$total_time_play = $total_time_end - $total_time_start;
log2file("End processing ".$total_numberOfChunks." chunks in ".$total_time_play." seconds.");

# close log
closeLogFile();

?>
