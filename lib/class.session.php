<?php
	namespace Crawler;
	
	Class Session{
		private $_starttime, $_stoptime;
		private $_id;
		private $_url;
		private $_analysed_urls;
		
		public static $db;
	
		public function __construct($url, \MySQL $database, $url_base=NULL){
			self::$db = $database;
			$this->_url = $url;
			$this->_url_base = $url_base;
			$this->_analysed_urls = array();
			
			$this->__processURL();
		}
	
		public function __get($name){
			return $this->{"_$name"};
		}
		
		public function isAnalysed($url){
			return (bool)in_array(strtolower(trim(self::sanatiseURL($url, $this->_url_base))), $this->_analysed_urls);
		}
		
		public function appendAnalysedURL($url){
			$this->_analysed_urls[] = strtolower(trim(self::sanatiseURL($url, $this->_url_base)));
		}
		
		public static function sanatiseURL($url, $url_base){
			
			// Thanks to example code provided by
			// "Isaac Z. Schlueter i" (http://www.php.net/manual/en/function.realpath.php#85388)

			$parsed = parse_url($url); 
		    if (array_key_exists('scheme', $parsed)) { 
		        return $url; 
		    }

		    $parsed_base = parse_url($url_base . " "); 

		    if (!array_key_exists('path', $parsed_base)){ 
		        $parsed_base = parse_url($url_base . "/ "); 
		    }
		
			if ($url{0} === '/') $path = $url; 
			else $path = dirname($parsed_base['path']) . "/{$url}"; 
			
			$path = preg_replace('@/\./@', '/', $path); 
			
			$parts = array(); 
			foreach(explode('/', preg_replace('@/+@', '/', $path)) as $part){
				if ($part === '..') array_pop($parts); 
				elseif($part != '') $parts[] = $part; 
	        }
	
			return sprintf("%s/%s", $url_base, implode("/", $parts));

		
		}
		
		private function __processURL(){
			
			if(!is_null($this->_url_base)) return;
			
			$parsed = parse_url($this->_url);
		
			if(!isset($parsed['scheme'])){
				\Crawl::printCLIMessage("WARNING - URL supplied did not contain any scheme. Assuming 'http'");
				$parsed = parse_url("http://" . $this->_url);
			}
			
			$this->_url_base = sprintf(
				"%s://%s%s",
				$parsed['scheme'], $parsed['host'],
				(isset($parsed['port']) ? ":{$parsed['port']}" : NULL)
			);
			
			\Crawl::printCLIMessage("NOTICE - No base URL specified. Using '{$this->_url_base}'");
			
		}
		
		private function __createSessionRecord(){
			self::$db->query(sprintf(
				"INSERT INTO `tbl_crawler_sessions` VALUES (NULL, NOW(), '%s', NULL, 'in-progress')",
				\MySQL::cleanValue($this->_url)
			));
		
			$this->_id = self::$db->getInsertID();
		}
	
		private function __closeSession(){
			
			$this->_stoptime = precision_timer();
			
			return self::$db->query(sprintf(
				"UPDATE `tbl_crawler_sessions` SET `time` = %s, `status` = '%s' WHERE `id` = %s LIMIT 1",
				number_format($this->_stoptime - $this->_starttime, 4),
				'complete',
				$this->_id
			));
		}
	
		public function start($max_depth=1){
			\Crawl::printCLIMessage("Crawling Started -- '{$this->_url}'");
			$this->_starttime = precision_timer();
			$this->__createSessionRecord();
		
			$page = new Page($this, $this->_url);
			$page->crawl(0, $max_depth);
			
			$this->__closeSession();

			\Crawl::printCLIMessage(sprintf(
				"Crawling completed in '%s' seconds", 
				number_format($this->stoptime - $this->_starttime, 4)
			));
			
			
		}
	
	
	}
	
	Class Page{
		
		private $_url;
		private $_session;
		private $_response;
		private $_curl_info;
		private $_links;
		private $_id, $_parent_id;
		
		public function __construct(Session &$session, $url, $parent_id=NULL){
			$this->_url = $url;
			$this->_session = $session;
			$this->_links = new \stdClass;
			$this->_response = new \stdClass;
			$this->_curl_info = new \stdClass;
			$this->_parent_id = $parent_id;
		}
		
		public function crawl($current_depth, $max_depth){
			if($current_depth > $max_depth){
				\Crawl::printCLIMessage("Maximum depth reached. My feet are sore, I shall go no further.");
				return;
			}
			
			$start_time = precision_timer();
			\Crawl::printCLIMessage(
				($current_depth > 0 ? str_pad('', $current_depth, '-') . ' ' : NULL)
				. $this->_url . " ... ", false
			);
			
			// Important. This ensures we don't get in an endless loop.
			if($this->_session->isAnalysed($this->_url)){
				\Crawl::printCLIMessage(" previous analysed. Ignoring.", true, false);
				return;
			}
			
			$this->_session->appendAnalysedURL($this->_url);
			
			$this->__load();

			// Deal with status codes other than 200 OK
			if($this->_curl_info['http_code'] != 200){

				$stop_time = precision_timer();
				\Crawl::printCLIMessage(sprintf("resulted in %s (%ss) \t\t [×]",
					$this->_curl_info['http_code'] ,
					number_format($stop_time - $start_time, 4)
				), true, false);
			}
			
			else{
				$this->__findLinks();
			
				$stop_time = precision_timer();
				\Crawl::printCLIMessage(sprintf("found %d link/s (%d remote) in %ss \t\t [√]",
					count($this->_links->local) + count($this->_links->remote), 
					count($this->_links->remote), 
					number_format($stop_time - $start_time, 4)
				), true, false);
			}
			
			$this->__save(number_format($stop_time - $start_time, 4));
			
			if(is_array($this->_links->local) && count($this->_links->local) > 0 && $current_depth + 1 <= $max_depth){
				foreach($this->_links->local as $l){
					$page = new Page($this->_session, $l, $this->_id);
					$page->crawl($current_depth + 1, $max_depth);
				}
			}

		}
		
		private function __save($time){
			
			Session::$db->query(sprintf(
				"INSERT INTO `tbl_crawler_pages` 
				(`id`, `session_id`, `parent_page_id`, `datestamp`, `location`, `http_code`, `content_type`, `time`, `headers_raw`)
					VALUES 
				(NULL, %d, %s, NOW(), '%s', %d, '%s', %s, '%s')",
				$this->_session->id,
				($this->_parent_id == NULL ? 'NULL' : $this->_parent_id),
				\MySQL::cleanValue($this->_url),
				$this->_curl_info['http_code'],
				\MySQL::cleanValue($this->_curl_info['content_type']),
				$time,
				\MySQL::cleanValue($this->_response->headers)
			));
			
			$this->_id = Session::$db->getInsertID();
			
			/*
			15:09:45 > - http://symphony.local/2.x/banana/ ... array(26) {
			
			  ["url"]=>string(33) "http://symphony.local/2.x/banana/"
			  ["content_type"]=>string(24) "text/html; charset=UTF-8"
			  ["http_code"]=>int(404)
			  ["primary_ip"]=>string(9) "127.0.0.1"
			  ["primary_port"]=>int(80)

			  ["size_download"]=>float(593)
			  ["speed_download"]=>float(57786)
			
			  ["total_time"]=>float(0.010262)
			  ["namelookup_time"]=>float(2.9E-5)
			  ["connect_time"]=>float(0.000173)
			  ["pretransfer_time"]=>float(0.000227)

			*/
		}
		
		public static function isRemote($root, $dest){
			
			if($dest{0} == "/") return false;
			
			$parsed_root = parse_url($root);
			$parsed_dest = parse_url($dest);
			
			return strcasecmp($parsed_root['host'], $parsed_dest['host']) != 0;
		}
		
		private function __findLinks(){

			$this->_links = (object)array(
				'remote' => array(),
				'local' => array()
			);

			// DOMDocument is more efficent
			//preg_match_all('/<a\s+href=["\']([^"\']+)["\']/i', $data[0], $links, PREG_PATTERN_ORDER);
			
			$dom = new \DOMDocument();
			@$dom->loadHTML($this->_response->data);

			// grab all the on the page
			$xpath = new \DOMXPath($dom);
			$elements = $xpath->evaluate("/html/body//a");
			
			$a = array();
			for ($i = 0; $i < $elements->length; $i++) {
				$l = $elements->item($i)->getAttribute('href');
				if($l{0} == '#' || strlen(trim($l)) == 0) continue;
				
				$a[] = $l;
			}
			
			$a = array_unique($a);
			
			foreach($a as $l){
				$this->_links->{(self::isRemote($this->_url, $l) ? 'remote' : 'local')}[] = trim($l);
			}
			
		}
		
		private function __load(){

			$ch = curl_init();

			$url = Session::sanatiseURL($this->_url, $this->_session->url_base);

			$url_parsed = parse_url($url);

			// Allow basic HTTP authentiction
			if(isset($url_parsed['user']) && isset($url_parsed['pass'])){
				curl_setopt($ch, CURLOPT_USERPWD, sprintf('%s:%s', $url_parsed['user'], $url_parsed['pass']));
				curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
			}

			// Better support for HTTPS requests
			if($url_parsed['scheme'] == 'https'){
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			}

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, "Symphony-CMS/Crawler");
			curl_setopt($ch, CURLOPT_PORT, (isset($url_parsed['port']) ? $url_parsed['port'] : NULL));
			//@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);

			//curl_setopt($ch, CURLOPT_HTTPHEADER, $this->_headers);

			// Grab the result
			$raw = curl_exec($ch);

			// Split out the headers
			$response = preg_split('/\r\n\r\n/', $raw, 2);

			// Split up the headers
		//	$response[0] = preg_split('/\r?\n/', $response[0]);

			$info = curl_getinfo($ch);

			// Close the connection
			curl_close ($ch);
			
			$this->_response = (object)array(
				'url' => $url,
				'headers' => $response[0],
				'data' => $response[1]
			);
			
			$this->_curl_info = $info;

			return;

		}
	}
	


