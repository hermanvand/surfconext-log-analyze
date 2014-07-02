SET storage_engine=InnoDB;

# CHUNK

CREATE TABLE log_analyze_chunk (
  chunk_id INT NOT NULL AUTO_INCREMENT,
  chunk_from DATETIME DEFAULT NULL,
  chunk_to DATETIME DEFAULT NULL,
  chunk_status VARCHAR(128) NOT NULL DEFAULT 'new',
  chunk_created DATETIME DEFAULT NULL,
  chunk_updated DATETIME DEFAULT NULL,
  chunk_in INT DEFAULT NULL,
  chunk_out INT DEFAULT NULL,
  PRIMARY KEY (chunk_id),
  INDEX from_index (chunk_from),
  INDEX to_index (chunk_to),
  INDEX status_index (chunk_status)
);

# STATS

CREATE TABLE log_analyze_day (
	day_id INT NOT NULL AUTO_INCREMENT,
	day_day DATE DEFAULT NULL,
	day_environment VARCHAR(8) DEFAULT NULL,
	day_logins INT DEFAULT NULL,
	day_created DATETIME DEFAULT NULL,
	day_updated DATETIME DEFAULT NULL,
	PRIMARY KEY (day_id),
	INDEX day_index (day_day)
);

CREATE TABLE log_analyze_sp (
	sp_id INT NOT NULL AUTO_INCREMENT,
	sp_name VARCHAR(4096) DEFAULT NULL,
	sp_eid INT DEFAULT NULL,
	sp_revision INT DEFAULT NULL,
	PRIMARY KEY (sp_id),
	INDEX entity_index (sp_eid,sp_revision)
);

CREATE TABLE log_analyze_idp (
	idp_id INT NOT NULL AUTO_INCREMENT,
	idp_name VARCHAR(4096) DEFAULT NULL,
	idp_eid INT NOT NULL,
	idp_revision INT NOT NULL,
	PRIMARY KEY (idp_id),
	INDEX entity_index (idp_eid,idp_revision)
);

CREATE TABLE log_analyze_provider (
	provider_id INT NOT NULL AUTO_INCREMENT,
	provider_sp_id INT NOT NULL,
	provider_idp_id INT NOT NULL,
	PRIMARY KEY (provider_id),
	FOREIGN KEY (provider_sp_id) REFERENCES log_analyze_sp (sp_id) ON DELETE CASCADE,
	FOREIGN KEY (provider_idp_id) REFERENCES log_analyze_idp (idp_id) ON DELETE CASCADE
);

/* 
* do not use an auto_increment id on the stats and users table
* use a clustered index for better performance
*/
CREATE TABLE log_analyze_stats (
	stats_day_id INT NOT NULL,
	stats_provider_id INT NOT NULL,
	stats_logins INT DEFAULT NULL,
	stats_users INT DEFAULT NULL,
	PRIMARY KEY (stats_day_id,stats_provider_id),
	FOREIGN KEY (stats_day_id) REFERENCES log_analyze_day (day_id) ON DELETE CASCADE,
	FOREIGN KEY (stats_provider_id) REFERENCES log_analyze_provider (provider_id) ON DELETE CASCADE
);

CREATE TABLE log_analyze_semaphore (
	semaphore_id INT NOT NULL,
	semaphore_name VARCHAR(128) NOT NULL,
	semaphore_value INT NOT NULL,
	PRIMARY KEY (semaphore_id)
);

INSERT INTO log_analyze_semaphore VALUES(1,"provider",1);
INSERT INTO log_analyze_semaphore VALUES(2,"unknownSP",1);
INSERT INTO log_analyze_semaphore VALUES(3,"unknownIDP",1);
INSERT INTO log_analyze_semaphore VALUES(4,"user",1);
INSERT INTO log_analyze_semaphore VALUES(5,"day",1);

/* aggregation tables */
CREATE TABLE `log_analyze_period` (
	`period_id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
	`period_type`        char(1) NOT NULL,
	`period_period`      int(2) unsigned NOT NULL,
	`period_year`        int(4) unsigned NOT NULL,
	`period_environment` char(2) NOT NULL,
	`period_from`        timestamp NULL,
	`period_to`          timestamp NULL,
	PRIMARY KEY (`period_id`),
	UNIQUE KEY (`period_period`,`period_year`,`period_environment`,`period_type`),
	KEY (`period_period`,`period_year`),
	KEY (`period_type`),
	KEY (`period_environment`)
);

/* creating stored procedure to get unique user count over multiple days */

DELIMITER //
CREATE PROCEDURE getUniqueUserCount (IN fromDay DATE, IN toDay DATE, IN environment VARCHAR(8))
    BEGIN
		SET group_concat_max_len = 1024 * 1024 * 10;
		SET @a = (select group_concat('select * from log_analyze_days__' , day_id SEPARATOR ' UNION ') from log_analyze_day where (day_day BETWEEN fromDay AND toDay) AND (day_environment = environment) );
		SET @x := CONCAT('select count(distinct(user_name)) as user_count from ( ', @a, ' ) e');
		Prepare stmt FROM @x;
		Execute stmt;
		DEALLOCATE PREPARE stmt;
    END //
DELIMITER ;
