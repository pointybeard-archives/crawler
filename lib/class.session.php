<?php
	namespace Crawler;

	require_once('class.page.php');
	
	Class Session{
		private $_starttime, $_stoptime;
		private $_id;
		private $_url;
		private $_analysed_urls;
		
		public static $db;
	
		public function __construct($url, \Database $database, $url_base=NULL){
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
			$this->_id = self::$db->insert(array(
				'id' => NULL, 
				'datestamp' => \DateTimeObj::get('YmdHis'), 
				'location' => $this->_url, 
				'time' => NULL, 
				'status' => 'in-progress'
			), 'tbl_crawler_sessions');
		}
	
		private function __closeSession(){
			
			$this->_stoptime = precision_timer();
			
			return self::$db->update(array(
				'time' => number_format($this->_stoptime - $this->_starttime, 4),
				'status' => 'complete'
			), 'tbl_crawler_sessions', "`id` = {$this->_id}");

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

