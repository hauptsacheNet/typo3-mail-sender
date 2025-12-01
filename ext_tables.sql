#
# Table structure for table 'tx_mailsender_address'
#
CREATE TABLE tx_mailsender_address (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

	sender_address varchar(255) DEFAULT '' NOT NULL,
	sender_name varchar(255) DEFAULT '' NOT NULL,
	validation_status varchar(50) DEFAULT 'pending' NOT NULL,
	validation_last_check int(11) unsigned DEFAULT '0' NOT NULL,
	validation_result text,
	eml_file int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY parent (pid),
	KEY sender_address (sender_address),
	KEY validation_status (validation_status)
);
