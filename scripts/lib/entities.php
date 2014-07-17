<?php

################
### ENTITIES ###
################

# adjust log_analyze_idp and log_analyze_sp tables to store the custom metadata 
# defined in la.ini
function fixIdPSPTables()
{
	global $LA;

	foreach ($LA['extra_metadata'] as $metadata)
	{
		$key = $metadata['metadata_key'];

		# check type
		if ( strncasecmp($metadata['type'], 'idp', 1) == 0 ) {
			# IdP
			$q = "ALTER TABLE `log_analyze_idp` ADD COLUMN `idp_m_$key` TEXT DEFAULT NULL";
		}
		elseif ( strncasecmp($metadata['type'], 'sp', 1) == 0 ) {
			# SP
			$q = "ALTER TABLE `log_analyze_sp` ADD COLUMN `sp_m_$key` TEXT DEFAULT NULL";
		}
		else
		{
			log2file("Unknown entity type {$metadata['type']} in config for additional metadata");
			continue;
		}

		$result = mysql_query($q,$LA['mysql_link_stats']);
		# duplicate column (error 1060) is actually ok, as it implies the 
		# column already exists
		if (!$result && mysql_errno($LA['mysql_link_stats'])!=1060) {
			catchMysqlError("fixIdPSPTables ($q)", $LA['mysql_link_stats']);
		}
	}
}


function array_equal($a,$b)
{
	return !array_diff_assoc($a,$b) && !array_diff_assoc($b,$a);
}

function getAllEntities() {
    global $LA;

	$entities = array();
	$sp_index = array();
	$idp_index = array();
	
	# order by is crucial for building data structure
	$result = mysql_query("SELECT id,eid,entityid,revisionid,state,type,created FROM ".$LA['table_entities']." ORDER BY eid,revisionid ASC", $LA['mysql_link_sr']);
	
	if ($result) {
		while ($result_row = mysql_fetch_assoc($result)) {
			$eid=0;
			$name="";
			$revision=0;
			$timestamp=NULL;
			$environment="";
			$provider="";

			# reformat
			$conrev_id = $result_row['id'];
			$eid = $result_row['eid'];
			$name = $result_row['entityid'];
			$entityid = $result_row['entityid'];
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
			if (! array_key_exists($eid, $entities)) {
				$entities[$eid] = array();
				$prev_env = "";
				$prev_name = "";
				$prev_extra = "";
			}
				
			# fetch requested metadata fields
			$extra = array();
			foreach ($LA['extra_metadata'] as $metadata)
			{
				$key = $metadata['metadata_key'];

				# check type
				if ( strncasecmp($metadata['type'], $provider, 1) != 0 ) continue;

				# fetch metadata
				$q = "
					SELECT `value` FROM {$LA['table_metadata']}
					WHERE `connectionRevisionId` = {$conrev_id}
					AND `key`='{$key}'
				";
				$result2 = mysql_query($q,$LA['mysql_link_sr']);
				if ($result2 && mysql_num_rows($result2)==1) {
					$result_row = mysql_fetch_array($result2);
					$value = $result_row[0];
					$extra[$key] = $value;
				}
			}

			# Be smart, only consider revision with changes in environment :-)
			if ( $environment != $prev_env || !array_equal($extra,$prev_extra) ) {
				$entities[$eid][$revision] = array();
				$entities[$eid][$revision]['timestamp'] = $timestamp;
				$entities[$eid][$revision]['entityid'] = $entityid;
				$entities[$eid][$revision]['environment'] = $environment;
				$entities[$eid][$revision]['metadata'] = $extra;

				$prev_extra = $extra;
				$prev_env   = $environment;
			}

			# Be fast, build indexes from name to eid, for both sp & idp :-)
			if ($name != $prev_name) {		
				if ( $name != $prev_name ) {
					if ($provider == "S") {
						$sp_index[$name] = $eid;
					}
					else {
						$idp_index[$name] = $eid;
					}
				}
				$prev_name = $name;
			}
			
		}
	}
	else {
		catchMysqlError("getAllEntities", $LA['mysql_link_sr']);
	}

	return array($entities,$sp_index,$idp_index);
}

?>
