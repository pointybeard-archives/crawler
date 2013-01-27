<?php
	namespace Crawler;
	
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
				\Crawl::printCLIMessage(" previously analysed. Ignoring.", true, false);
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

			elseif(!preg_match('/^text\/html/i', $this->_curl_info['content_type'])){
				$stop_time = precision_timer();
				\Crawl::printCLIMessage(sprintf(" isn't HTML (%ss) \t\t [√]",
					number_format($stop_time - $start_time, 4)
				), true, false);
			}
			
			// Only look for links on HTML pages
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
			
			$this->_id = Session::$db->insert(array(
				'id' => NULL,
				'session_id' => $this->_session->id,
				'parent_page_id' => ($this->_parent_id == NULL ? 'NULL' : $this->_parent_id),
				'datestamp' => \DateTimeObj::get('YmdHis'),
				'location' => $this->_url,
				'http_code' => $this->_curl_info['http_code'],
				'content_type' => \MySQL::cleanValue($this->_curl_info['content_type']),
				'time' => $time,
				'headers_raw' => \MySQL::cleanValue($this->_response->headers),
			), 'tbl_crawler_pages');
		
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
			
			// If there is no scheme or host, then its a relative link
			if(isset($parsed_dest['path']) && (!isset($parsed_dest['scheme']) && !isset($parsed_dest['host']))){
				return false;
			}
			
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
			$elements = $xpath->evaluate("//*[@src or @href]");
		
			$a = array();
			for ($i = 0; $i < $elements->length; $i++) {
				$item = $elements->item($i);
				$l = $item->getAttribute(
					($item->hasAttribute('src') ? 'src' : 'href')
				);
				
				if($l{0} == '#' || strlen(trim($l)) == 0) continue;
			
				$a[] = $l;
			}
		
			$a = array_unique($a);

			foreach($a as $l){
				$bIsRemote = self::isRemote($this->_url, $l);
				
				if(!$bIsRemote){
					// Check for relative links here
					$parsed = parse_url($l); 
				    if (!array_key_exists('scheme', $parsed) && $l{0} !== '/' && $l{0} !== '.'){
						$p = parse_url($this->_url);
						$l = sprintf("%s://%s%s/%s", $p['scheme'], $p['host'], dirname($p['path']), $l);
				    }
				}
				
				$this->_links->{$bIsRemote ? 'remote' : 'local'}[] = trim($l);
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
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		
			// Headers only, no content
			//curl_setopt($ch, CURLOPT_NOBODY, true);

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


