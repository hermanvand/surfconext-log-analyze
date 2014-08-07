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


// compare two metadata arrays (assuming the keys are identical!)
// return false if the arrays are the same
// return true if array2 is different from array 1
// except if a new key was introduced, then return true
// 
// the idea here is that if a previously non-existing field was added, that's 
// most probably not a significant change.  However, if the contents of the 
// field changed, that would be considered major
function _laMetadataChanged($array1,$array2)
{
	foreach ($array1 as $k=>$v)
	{
		if ( !isset($array2[$k]) ) return true;
		if ( $array2[$k]!==$v    ) return true;
	}
	return false;
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
			if ($revision==0) $prev_rev=null;

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
				$prev_provider = "";
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
			if ( $provider != $prev_provider or $environment!=$prev_env 
				or _laMetadataChanged($prev_extra,$extra) ) 
			{
				$entities[$eid][$revision] = array();
				$entities[$eid][$revision]['timestamp'] = $timestamp;
				$entities[$eid][$revision]['entityid'] = $entityid;
				$entities[$eid][$revision]['environment'] = $environment;
				$entities[$eid][$revision]['metadata'] = $extra;
				$entities[$eid][$revision]['date_from'] = $timestamp;
				$entities[$eid][$revision]['date_to'  ] = null;

				# keep track of chain of revisions
				if ($revision>0) {
					$entities[$eid][$prev_rev]['date_to'] = $timestamp;
				}

				$prev_extra    = $extra;
				$prev_env      = $environment;
				$prev_rev      = $revision;
			}

			# Be fast, build indexes from name to eid, for both sp & idp :-)
			if ( $name != $prev_name || $provider != $prev_provider ) {
				if ($provider == "S") {
					$sp_index[$name] = $eid;
				}
				else {
					$idp_index[$name] = $eid;
				}
				$prev_name     = $name;
				$prev_provider = $provider;
			}
		}
	}
	else {
		catchMysqlError("getAllEntities", $LA['mysql_link_sr']);
	}

	return array($entities,$sp_index,$idp_index);
}

?>
