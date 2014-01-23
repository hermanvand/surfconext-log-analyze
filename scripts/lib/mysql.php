<?php

# Open database connection
function openMysqlDb($db) {
    global $LA;

	$LA['mysql_link'] = mysql_connect($LA[$db]['mysql_host'], $LA[$db]['mysql_user'], $LA[$db]['mysql_pass']);
	mysql_select_db($LA[$db]['mysql_db'], $LA['mysql_link']);
	mysql_query("SET NAMES 'utf8';", $LA['mysql_link']);
}

function openChildMysqlDb($db) {
    global $LA;

	$mysql_link = mysql_connect($LA[$db]['mysql_host'], $LA[$db]['mysql_user'], $LA[$db]['mysql_pass']);
	mysql_select_db($LA[$db]['mysql_db'], $mysql_link);
	mysql_query("SET NAMES 'utf8';", $mysql_link);
	
	return $mysql_link;
}

# close database
function closeMysqlDb() {
    global $LA;

	mysql_close($LA['mysql_link']);
}

function closeChildMysqlDb($mysql_link) {
	mysql_close($mysql_link);
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
