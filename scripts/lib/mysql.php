<?php

# Open database connection
function openMysqlDb($db) {
    global $LA;

	$mysql_link = mysql_connect($LA[$db]['mysql_host'], $LA[$db]['mysql_user'], $LA[$db]['mysql_pass'],true);

	mysql_select_db($LA[$db]['mysql_db'], $mysql_link);
	mysql_query("SET NAMES 'utf8';", $mysql_link);
	mysql_query("SET storage_engine=InnoDB;", $mysql_link);

	#log2file("Opened {$LA[$db]['mysql_user']}@{$LA[$db]['mysql_host']}/{$LA[$db]['mysql_db']}: ".print_r($mysql_link,1));
	
	return $mysql_link;
}

# close database
function closeMysqlDb(&$mysql_link) {
	mysql_close($mysql_link);
	$mysql_link = null;
}

# other mysql functions
function safeInsert($value){
	return mysql_real_escape_string($value);
} 

function safeListInsert($list) {
	foreach ($list as $key => $val) {
		$list[$key] = mysql_real_escape_string($val);
	}
	return $list;
}

function checkDateTime($date) {
    if (date('Y-m-d H:i:s', strtotime($date)) == $date) {
        return true;
    } 
    else {
        return false;
    }
}

function checkDateMine($date) {
    if (date('Y-m-d', strtotime($date)) == $date) {
        return true;
    } 
    else {
        return false;
    }
}

?>
