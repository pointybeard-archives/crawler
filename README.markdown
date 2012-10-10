# Symphony Page Crawler #

Spiders a local or remote Symphony CMS powered website profiling pages and looking for problems.

- Version: 0.01
- Date: 10th August 2012
- Requirements: Symphony 2.3.1 or later, Advanced Symphony Database Connector (ASDC)
- Author: Alistair Kearney, hi@alistairkearney.com
- GitHub Repository: <http://github.com/pointybeard/crawler>


## Installation

Information about [installing and updating extensions](http://symphony-cms.com/learn/tasks/view/install-an-extension/) can be found in the [Symphony documentation](http://symphony-cms.com/learn/).

Requires the Advanced Symphony Database Connector(asdc) extension is installed. See 'http://github.com/pointybeard/asdc/'

Use the following SQL to install. Remember to replace `tbl_` with your database prefix.

	CREATE TABLE IF NOT EXISTS `tbl_crawler_pages` (
	  `id` int(14) unsigned NOT NULL AUTO_INCREMENT,
	  `session_id` int(14) unsigned NOT NULL,
	  `parent_page_id` int(14) DEFAULT NULL,
	  `datestamp` datetime NOT NULL,
	  `location` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
	  `http_code` int(4) NOT NULL,
	  `content_type` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
	  `time` float NOT NULL,
	  `headers_raw` text COLLATE utf8_unicode_ci NOT NULL,
	  PRIMARY KEY (`id`),
	  KEY `session_id` (`session_id`),
	  KEY `parent_page_id` (`parent_page_id`)
	);

	CREATE TABLE IF NOT EXISTS `tbl_crawler_sessions` (
	  `id` int(14) unsigned NOT NULL AUTO_INCREMENT,
	  `datestamp` datetime NOT NULL,
	  `location` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
	  `time` double DEFAULT NULL,
	  `status` enum('complete','in-progress','aborted','failed','unknown') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'unknown',
	  PRIMARY KEY (`id`),
	  KEY `location` (`location`)
	);

## Usage

### Basics

TO DO