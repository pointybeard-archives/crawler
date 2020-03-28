<?php

declare(strict_types=1);

namespace pointybeard\Symphony\Extensions\Crawler\Models;

use pointybeard\Symphony\Extensions\Console\Commands\Console\Symphony;
use pointybeard\Symphony\Classmapper;
use pointybeard\Symphony\Extensions\Crawler;
use SebastianBergmann\Timer\Timer;
use pointybeard\Helpers\Foundation\BroadcastAndListen;
use pointybeard\Helpers\Exceptions\ReadableTrace;
use pointybeard\Helpers\Cli\Message\Message;
use pointybeard\Helpers\Cli\Colour\Colour;

final class Session extends Classmapper\AbstractModel implements Classmapper\Interfaces\FilterableModelInterface, Classmapper\Interfaces\SortableModelInterface, BroadcastAndListen\Interfaces\AcceptsListenersInterface
{
    use Classmapper\Traits\HasModelTrait;
    use Classmapper\Traits\HasFilterableModelTrait;
    use Classmapper\Traits\HasSortableModelTrait;
    use Crawler\Traits\HasUuidTrait;
    use BroadcastAndListen\Traits\HasListenerTrait;
    use BroadcastAndListen\Traits\HasBroadcasterTrait;

    public const STATUS_COMPLETE = 'Complete';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_ABORTED = 'Aborted';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_UNKNOWN = 'Unknown';
    public const STATUS_PENDING = 'Pending';

    public function getSectionHandle(): string
    {
        return 'crawler-sessions';
    }

    protected static function getCustomFieldMapping(): array
    {
        return [
            'date-created-at' => [
                'flags' => self::FLAG_SORTBY | self::FLAG_SORTASC | self::FLAG_REQUIRED,
                'classMemberName' => 'dateCreatedAt',
            ],
            'date-started-at' => [
                'flags' => self::FLAG_NULL,
                'classMemberName' => 'dateStartedAt',
            ],
            'date-completed-at' => [
                'flags' => self::FLAG_NULL,
                'classMemberName' => 'dateCompletedAt',
            ],
            'seed' => [
                'flags' => self::FLAG_STR | self::FLAG_NULL,
            ],
            'time' => [
                'flags' => self::FLAG_FLOAT | self::FLAG_NULL,
            ],
            'status' => [
                'flags' => self::FLAG_STR | self::FLAG_REQUIRED,
            ],
            'max-depth' => [
                'flags' => self::FLAG_INT | self::FLAG_NULL,
            ],
            'timeout' => [
                'flags' => self::FLAG_INT | self::FLAG_NULL,
            ],
            'base' => [
                'flags' => self::FLAG_STR | self::FLAG_NULL,
            ],
        ];
    }

    public static function fetchByStatus($status): \Iterator
    {
        return self::fetch(
            Classmapper\FilterFactory::build('Basic', 'status', $status)
        );
    }

    public static function open(int $maxDepth = 10, int $timeout = 10, ?string $base = null): self
    {
        return (new self())
            ->dateCreatedAt('now')
            ->timeout($timeout)
            ->base($base)
            ->maxDepth($maxDepth)
            ->status(self::STATUS_PENDING)
            ->save()
        ;
    }

    private $resourceQueue = [];
    private $resourcesCrawled = [];

    public function queueResourceForCrawling(string $resource, ?int $parentResourceId = null, bool $checkForDuplicateInQueue = true, bool $ignoreAlreadyCrawled = true): void
    {
        $resource = self::resolveUrl($resource, $this->base());

        if(true == $checkForDuplicateInQueue) {
            foreach($this->resourceQueue as $index => $r) {
                if($r->location() == $resource) {
                    // $this->broadcast(
                    //     Symphony::BROADCAST_MESSAGE,
                    //     E_NOTICE,
                    //     (new Message)
                    //         ->message("{$resource} - already already in queue")
                    //         ->foreground(Colour::FG_RED)
                    // );
                    return;
                }
            }
        }

        if(true == $ignoreAlreadyCrawled && in_array($resource, $this->resourcesCrawled)) {
            // $this->broadcast(
            //     Symphony::BROADCAST_MESSAGE,
            //     E_NOTICE,
            //     (new Message)
            //         ->message("{$resource} - already been crawled")
            //         ->foreground(Colour::FG_RED)
            // );
            return;
        }

        $this->resourceQueue[] = (new Resource)
            ->location($resource)
            ->sessionId($this->id)
            ->parentResourceId($parentResourceId)
        ;
    }

    public function getNextResourceInQueue(): Resource
    {
        return array_shift($this->resourceQueue);
    }

    public function start(?array $listeners = null): void
    {
        try {
            $this
                ->dateStartedAt('now')
                ->status(self::STATUS_IN_PROGRESS)
                ->save()
            ;

            if (null !== $listeners) {
                foreach ($listeners as $callback) {
                    if (!is_callable($callback)) {
                        throw new ReadableTrace\ReadableTraceException('Listener provided is not a valid callback');
                    }
                    $this->addListener($callback);
                }
            }

            Timer::start();

            // Create a resource and add it to the queue
            $this->queueResourceForCrawling($this->seed());

            $this->broadcast(
                Symphony::BROADCAST_MESSAGE,
                E_NOTICE,
                (new Message)
                    ->message("Session has started - {$url}")
                    ->foreground(Colour::FG_RED)
            );

            // Start the process
            while(count($this->resourceQueue) > 0) {
                $this
                    ->getNextResourceInQueue()
                    ->crawl($this, $listeners)
                ;
                // $this->broadcast(
                //     Symphony::BROADCAST_MESSAGE,
                //     E_WARNING,
                //     (new Message)
                //         ->message("Queue Size - {".count($this->resourceQueue)."}")
                //         ->foreground(Colour::FG_RED)
                // );
            }

            $this->status(self::STATUS_COMPLETE);

        } catch (\Exception $ex) {
            var_dump($ex);
            $this->status(self::STATUS_FAILED);
        }

        $this
            ->time(Timer::stop())
            ->dateCompletedAt('now')
            ->save()
        ;
    }

    protected static function resolveUrl(string $url, string $base = null): string
    {
        if (null !== parse_url($url, \PHP_URL_SCHEME)) {
            return $url;
        }

        $parsed = parse_url($base.' ');

        if (false == array_key_exists('path', $parsed)) {
            $parsed = parse_url($base.'/ ');
        }

        if ('/' === $url[0]) {
            $path = $url;
        } else {
            $path = dirname($parsed['path'])."/{$url}";
        }

        $path = preg_replace('@/\./@', '/', $path);

        $parts = [];
        foreach (explode('/', preg_replace('@/+@', '/', $path)) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('' != $part) {
                $parts[] = $part;
            }
        }

        return sprintf('%s/%s', $base, implode('/', $parts));
    }
}

    // namespace Crawler;
    //
    // require_once('class.page.php');
    //
    // Class Session{
    // 	private $_starttime, $_stoptime;
    // 	private $_id;
    // 	private $_url;
    // 	private $_analysed_urls;
    //
    // 	public static $db;
    //
    // 	public function __construct($url, \Database $database, $url_base=NULL){
    // 		self::$db = $database;
    // 		$this->_url = $url;
    // 		$this->_url_base = $url_base;
    // 		$this->_analysed_urls = array();
    //
    // 		$this->__processURL();
    // 	}
    //
    // 	public function __get($name){
    // 		return $this->{"_$name"};
    // 	}
    //
    // 	public function isAnalysed($url){
    // 		return (bool)in_array(strtolower(trim(self::sanatiseURL($url, $this->_url_base))), $this->_analysed_urls);
    // 	}
    //
    // 	public function appendAnalysedURL($url){
    // 		$this->_analysed_urls[] = strtolower(trim(self::sanatiseURL($url, $this->_url_base)));
    // 	}
    //
    // 	public static function sanatiseURL($url, $url_base){
    //
    // 		// Thanks to example code provided by
    // 		// "Isaac Z. Schlueter i" (http://www.php.net/manual/en/function.realpath.php#85388)
    //
    // 		$parsed = parse_url($url);
    // 	    if (array_key_exists('scheme', $parsed)) {
    // 	        return $url;
    // 	    }
    //
    // 	    $parsed_base = parse_url($url_base . " ");
    //
    // 	    if (!array_key_exists('path', $parsed_base)){
    // 	        $parsed_base = parse_url($url_base . "/ ");
    // 	    }
    //
    // 		if ($url{0} === '/') $path = $url;
    // 		else $path = dirname($parsed_base['path']) . "/{$url}";
    //
    // 		$path = preg_replace('@/\./@', '/', $path);
    //
    // 		$parts = array();
    // 		foreach(explode('/', preg_replace('@/+@', '/', $path)) as $part){
    // 			if ($part === '..') array_pop($parts);
    // 			elseif($part != '') $parts[] = $part;
    //         }
    //
    // 		return sprintf("%s/%s", $url_base, implode("/", $parts));
    //
    //
    // 	}
    //
    // 	private function __processURL(){
    //
    // 		if(!is_null($this->_url_base)) return;
    //
    // 		$parsed = parse_url($this->_url);
    //
    // 		if(!isset($parsed['scheme'])){
    // 			\Crawl::printCLIMessage("WARNING - URL supplied did not contain any scheme. Assuming 'http'");
    // 			$parsed = parse_url("http://" . $this->_url);
    // 		}
    //
    // 		$this->_url_base = sprintf(
    // 			"%s://%s%s",
    // 			$parsed['scheme'], $parsed['host'],
    // 			(isset($parsed['port']) ? ":{$parsed['port']}" : NULL)
    // 		);
    //
    // 		\Crawl::printCLIMessage("NOTICE - No base URL specified. Using '{$this->_url_base}'");
    //
    // 	}
    //
    // 	private function __createSessionRecord(){
    // 		$this->_id = self::$db->insert(array(
    // 			'id' => NULL,
    // 			'datestamp' => \DateTimeObj::get('YmdHis'),
    // 			'location' => $this->_url,
    // 			'time' => NULL,
    // 			'status' => 'in-progress'
    // 		), 'tbl_crawler_sessions');
    // 	}
    //
    // 	private function __closeSession(){
    //
    // 		$this->_stoptime = precision_timer();
    //
    // 		return self::$db->update(array(
    // 			'time' => number_format($this->_stoptime - $this->_starttime, 4),
    // 			'status' => 'complete'
    // 		), 'tbl_crawler_sessions', "`id` = {$this->_id}");
    //
    // 	}
    //
    // 	public function start($max_depth=1){
    // 		\Crawl::printCLIMessage("Crawling Started -- '{$this->_url}'");
    // 		$this->_starttime = precision_timer();
    // 		$this->__createSessionRecord();
    //
    // 		$page = new Page($this, $this->_url);
    // 		$page->crawl(0, $max_depth);
    //
    // 		$this->__closeSession();
    //
    // 		\Crawl::printCLIMessage(sprintf(
    // 			"Crawling completed in '%s' seconds",
    // 			number_format($this->stoptime - $this->_starttime, 4)
    // 		));
    //
    //
    // 	}
    //
    //
    // }
    //
