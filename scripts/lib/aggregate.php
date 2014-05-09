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

?>
