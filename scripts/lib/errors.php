<?php

#########
# ERRORS
#########

# echo three types of errors in the logfile
# - program errors
# - program exceptions
# - mysql errors

# Turn error & exception handling on
set_error_handler('catchError');
set_exception_handler('catchException');

# catch errors
function catchError($errno, $errstr, $errfile, $errline) {

    if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return;
    }

    echoProgramError($errfile, $errline, $errno, $errstr);

    /* Don't execute PHP internal error handler */
    return true;
}

# TODO: what about exceptions?
function catchException($exception) {
	echoProgramException($exception->getFile(), $exception->getLine(), $exception->getCode(), $exception->getMessage());
}

# my mysql error catching
function catchMysqlError($function, $mysql_link) {
	echoMysqlError($function, mysql_errno($mysql_link), mysql_error($mysql_link));
}

#########
# LOGGING
#########

function openLogFile($dir) {
    global $LA;

    $LA['log_handler'] = fopen($dir.$LA['log_file'], 'a');
}

function closeLogFile() {
    global $LA;

    fclose($LA['log_handler']);
}

function log2file($message) {
    global $LA;

    $out = strftime("%A %d-%b-%y %T %Z", time()).": ".$message."\n";
    fwrite($LA['log_handler'], $out, strlen($out));

}

# error logging
function echoProgramError($file, $line, $errorNr, $errorTxt) {
	log2file( "[".$file.":".$line."] Program Error: ".$errorNr.", ".$errorTxt."\n" );
}

function echoProgramException($file, $line, $errorNr, $errorTxt) {
	log2file( "[".$file.":".$line."] Program Exception: ".$errorNr.", ".$errorTxt."\n" );
}

# mysql logging
function echoMysqlError($function, $errorNr, $errorTxt) {
	log2file( "[".$function."] Mysql Error: ".$errorNr.", ".$errorTxt."\n" );
}

?>
