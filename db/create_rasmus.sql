CREATE VIEW stats AS 
(SELECT log_analyze_day.day_day AS period, log_analyze_provider.provider_sp_id AS sp, log_analyze_provider.provider_idp_id AS idp, log_analyze_stats.stats_users AS users, log_analyze_stats.stats_logins AS logins, log_analyze_day.day_updated AS updated FROM log_analyze_stats, log_analyze_day, log_analyze_provider WHERE log_analyze_stats.stats_day_id = log_analyze_day.day_id AND log_analyze_stats.stats_provider_id = log_analyze_provider.provider_id)
UNION
(SELECT log_analyze_day.day_day AS period, log_analyze_provider.provider_sp_id AS sp, "-" AS idp, log_analyze_stats.stats_users AS users, log_analyze_stats.stats_logins AS logins, log_analyze_day.day_updated AS updated FROM log_analyze_stats, log_analyze_day, log_analyze_provider WHERE log_analyze_stats.stats_day_id = log_analyze_day.day_id AND log_analyze_stats.stats_provider_id = log_analyze_provider.provider_id)
UNION
(SELECT log_analyze_day.day_day AS period, "-" AS sp, log_analyze_provider.provider_idp_id AS idp, log_analyze_stats.stats_users AS users, log_analyze_stats.stats_logins AS logins, log_analyze_day.day_updated AS updated FROM log_analyze_stats, log_analyze_day, log_analyze_provider WHERE log_analyze_stats.stats_day_id = log_analyze_day.day_id AND log_analyze_stats.stats_provider_id = log_analyze_provider.provider_id);

CREATE VIEW entities AS
(SELECT log_analyze_sp.sp_eid AS id, log_analyze_sp.sp_id AS entityid, "sp" AS sporidp, log_analyze_sp.sp_name AS name_da, log_analyze_sp.sp_name AS name_en, 0 As integration_costs, 0 AS integration_costs_wayf, 0 AS number_of_users, null AS updated, "" AS schacHomeOrganization FROM log_analyze_sp)
UNION
(SELECT log_analyze_idp.idp_eid AS id, log_analyze_idp.idp_id AS entityid, "idp" AS sporidp, log_analyze_idp.idp_name AS name_da, log_analyze_idp.idp_name AS name_en, 0 As integration_costs, 0 AS integration_costs_wayf, 0 AS number_of_users, null AS updated, "" AS schacHomeOrganization FROM log_analyze_idp);

/*
CREATE TABLE stats
    (
        period text COLLATE latin1_swedish_ci NOT NULL,
        sp text COLLATE latin1_swedish_ci NOT NULL,
        idp text COLLATE latin1_swedish_ci NOT NULL,
        users INT(10) unsigned NOT NULL,
        logins INT(10) unsigned NOT NULL,
        updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
    ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE entities
    (
        id INT NOT NULL AUTO_INCREMENT,
        entityid text NOT NULL,
        sporidp text NOT NULL,
        name_da text NOT NULL,
        name_en text NOT NULL,
        integration_costs INT(10) unsigned,
        integration_costs_wayf INT(10) unsigned,
        number_of_users INT(10) unsigned,
        updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        schacHomeOrganization text,
        PRIMARY KEY (id),
        CONSTRAINT entityid UNIQUE (entityid(100), sporidp(5))
    )
    ENGINE=MyISAM DEFAULT CHARSET=utf8;
*/
