<?php

	require_once(TOOLKIT . '/class.datasource.php');

	Class datasourcecrawler_sessions extends Datasource{

		public $dsParamROOTELEMENT = 'crawler-sessions';

		public function __construct(array $env = null, $process_params=true){
			parent::__construct($env, $process_params);
		}

		public function about(){
			return array(
				'name' => 'Site Crawler Sessions',
				'version' => '1.0',
				'release-date' => '2012-09-11',
				'author' => array(
					'name' => 'Alistair Kearney',
					'website' => 'http://alistairkearney.com/',
					'email' => 'hi@alistairkearney.com'
				)
			);
		}

		public function grab(array &$param_pool=NULL){
			$result = new XMLElement($this->dsParamROOTELEMENT);

			$sessions = Symphony::Database()->fetch(sprintf(
				"SELECT * FROM `tbl_crawler_sessions`", 
				(is_numeric($this->_env['url']['session-id']) && $this->_env['url']['session-id'] != NULL 
					? sprintf('WHERE `id` = %d LIMIT 1', (int)$this->_env['url']['session-id']) 
					: NULL)
			));
			
			if(!is_array($sessions) || count($sessions) == 0) $this->emptyXMLSet();
			
			foreach($sessions as $s){
				$xSession = new XMLElement('sessions', NULL, array(
					'id' => $s['id'],
					'time' => $s['time'],
					'status' => $s['status'] 
				));
				$xSession->appendChild(General::createXMLDateObject($s['datestamp']));
				$xSession->appendChild(new XMLElement('location', $s['location']));
				/*
				
				array(5) {
				  ["id"]=>
				  string(1) "1"
				  ["datestamp"]=>
				  string(19) "2012-09-11 17:15:43"
				  ["location"]=>
				  string(26) "http://symphony.local/2.x/"
				  ["time"]=>
				  string(6) "5.0359"
				  ["status"]=>
				  string(8) "complete"
				}
				*/
				
				$pages = Symphony::Database()->fetch(sprintf(
					"SELECT * FROM `tbl_crawler_pages` WHERE `session_id` = %d ORDER BY `datestamp` ASC", $s['id']
				));
				
				foreach($pages as $p){
					$xPage = new XMLElement('page', NULL, array(
						'id' => $p['id'],
						'parent-page-id' => $p['page_page_id'],
						'time' => $p['time'],
						'http-code' => $p['http_code']
					));
					$xPage->appendChild(General::createXMLDateObject($p['datestamp']));
					$xPage->appendChild(new XMLElement('location', $p['location']));
					$xPage->appendChild(new XMLElement('http-code', $p['http_code'], array('http-code' => $p['http_code'])));
					$xPage->appendChild(new XMLElement('content-type', $p['content_type']));
					$xPage->appendChild(new XMLElement('headers-raw', $p['headers_raw']));
					$xSession->appendChild($xPage);
				}
				
				$result->appendChild($xSession);
			}

			return $result;
		}
	}