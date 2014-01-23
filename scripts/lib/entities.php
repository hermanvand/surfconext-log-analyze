<?php

################
### ENTITIES ###
################

function getAllEntities() {
    global $LA;

	$entities = array();
	$sp_index = array();
	$idp_index = array();
	
	# order by is crucial for building data structure
	$result = mysql_query("SELECT eid,entityid,revisionid,state,type,created FROM ".$LA['table_entities']." ORDER BY eid,revisionid ASC", $LA['mysql_link']);
	
	if ($result) {
		while ($result_row = mysql_fetch_assoc($result)) {
			$id=0;
			$name="";
			$revision=0;
			$timestamp=NULL;
			$environment="";
			$provider="";

			# reformat
			$id = $result_row['eid'];
			$name = $result_row['entityid'];
			$revision = $result_row['revisionid'];

			$dt = new DateTime($result_row['created']);
			$timestamp = $dt->format("Y-m-d H:i:s");

			if ($result_row['state'] == "prodaccepted") {
				$environment = "PA";
			}
			else {
				$environment = "TA";
			}

			if ($result_row['type'] == "saml20-sp") {
				$provider = "S";
			}
			else {
				$provider = "I";
			}

			# first entry
			if (! array_key_exists($id, $entities)) {
				$entities[$id] = array();
				$prev_env = "";
				$prev_name = "";
			}
				
			# Be smart, only consider revision with changes in environment :-)
			if ($environment != $prev_env) {
				$entities[$id][$revision] = array();
				$entities[$id][$revision]['timestamp'] = $timestamp;
				$entities[$id][$revision]['environment'] = $environment;
				$prev_env = $environment;
			}
			
			# Be fast, build indexes from name to eid, for both sp & idp :-)
			if ($name != $prev_name) {		
				if ( $name != $prev_name ) {
					if ($provider == "S") {
						$sp_index[$name] = $id;
					}
					else {
						$idp_index[$name] = $id;
					}
				}
				$prev_name = $name;
			}
			
		}
	}
	else {
		catchMysqlError("getAllEntities", $LA['mysql_link']);
	}

	return array($entities,$sp_index,$idp_index);
}

?>
