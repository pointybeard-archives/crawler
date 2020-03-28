<?php

declare(strict_types=1);

namespace pointybeard\Symphony\Extensions\Crawler\Models;

use pointybeard\Symphony\Classmapper;
use pointybeard\Symphony\Extensions\Crawler;
use pointybeard\Helpers\Foundation\BroadcastAndListen;
use pointybeard\Helpers\Exceptions\ReadableTrace;
use pointybeard\Helpers\Cli\Message\Message;
use pointybeard\Helpers\Cli\Colour\Colour;
use pointybeard\Symphony\Extensions\Console\Commands\Console\Symphony;

final class Resource extends Classmapper\AbstractModel implements Classmapper\Interfaces\FilterableModelInterface, Classmapper\Interfaces\SortableModelInterface, BroadcastAndListen\Interfaces\AcceptsListenersInterface
{
    use Classmapper\Traits\HasModelTrait;
    use Classmapper\Traits\HasFilterableModelTrait;
    use Classmapper\Traits\HasSortableModelTrait;
    use Crawler\Traits\HasUuidTrait;
    use BroadcastAndListen\Traits\HasListenerTrait;
    use BroadcastAndListen\Traits\HasBroadcasterTrait;

    public function getSectionHandle(): string
    {
        return 'crawler-resources';
    }

    protected static function getCustomFieldMapping(): array
    {
        return [
            'date-crawled-at' => [
                'flags' => self::FLAG_SORTBY | self::FLAG_SORTASC | self::FLAG_REQUIRED,
                'classMemberName' => 'dateCrawledAt',
            ],
            'location' => [
                'flags' => self::FLAG_STR | self::FLAG_REQUIRED,
            ],
            'content-type' => [
                'flags' => self::FLAG_STR | self::FLAG_REQUIRED,
                'classMemberName' => 'contentType',
            ],
            'request' => [
                'flags' => self::FLAG_STR | self::FLAG_REQUIRED,
            ],
            'response-headers' => [
                'flags' => self::FLAG_STR | self::FLAG_REQUIRED,
                'classMemberName' => 'responseHeaders',
            ],
            'time' => [
                'flags' => self::FLAG_FLOAT | self::FLAG_NULL,
            ],
            'status-code' => [
                'flags' => self::FLAG_INT | self::FLAG_REQUIRED,
                'classMemberName' => 'statusCodeId',
                'databaseFieldName' => 'relation_id',
            ],
            'session' => [
                'flags' => self::FLAG_INT | self::FLAG_REQUIRED,
                'classMemberName' => 'sessionId',
                'databaseFieldName' => 'relation_id',
            ],
            'parent-resource' => [
                'flags' => self::FLAG_INT | self::FLAG_NULL,
                'classMemberName' => 'parentResourceId',
                'databaseFieldName' => 'relation_id',
            ],
        ];
    }

    public function crawl(Session &$session, ?array $listeners = null): self
    {
        if (null !== $listeners) {
            foreach ($listeners as $callback) {
                if (!is_callable($callback)) {
                    throw new ReadableTrace\ReadableTraceException('Listener provided is not a valid callback');
                }
                $this->addListener($callback);
            }
        }

        $this->broadcast(
            Symphony::BROADCAST_MESSAGE,
            E_NOTICE,
            (new Message())
                ->message("Started crawling resource - {$this->location()}")
                ->foreground(Colour::FG_YELLOW)
        );

        $this->dateCrawledAt('now');

        // Pull page contents
        $contents = $this->fetchLinkContents($this->location());

        $this
            ->contentType($contents->curlInfo->content_type)
            ->request(json_encode($contents->url, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->responseHeaders(json_encode($contents->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->statusCodeId(StatusCode::loadFromCode($contents->curlInfo->http_code)->id)
            ->time($contents->curlInfo->total_time)
            ->save()
        ;

        // Find resources in the page contents
        $resources = $this->findResourcesInPageContents($contents->data);

        foreach ($resources->local as $r) {
            $session->queueResourceForCrawling($r, $this->id);
        }

        return $this;
    }

    private function fetchLinkContents(string $url): \stdClass
    {
        $ch = curl_init();

        $parsed = (object) parse_url($url);

        // Allow basic HTTP authentiction
        if (isset($parsed->user) && isset($parsed->pass)) {
            curl_setopt($ch, CURLOPT_USERPWD, sprintf('%s:%s', $parsed->user, $parsed->pass));
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        }

        // Better support for HTTPS requests
        if ('https' == $parsed->scheme) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SymphonyCMS/Crawler-1.0.0');
        curl_setopt($ch, CURLOPT_PORT, (isset($parsed->port) ? $parsed->port : null));
        //@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Headers only, no content
        //curl_setopt($ch, CURLOPT_NOBODY, true);
        //
        //curl_setopt($ch, CURLOPT_HTTPHEADER, $this->_headers);

        // Grab the result
        $raw = curl_exec($ch);

        // Split out the headers
        $response = preg_split('@\r\n\r\n@', $raw, 2);

        $info = curl_getinfo($ch);

        // Close the connection
        curl_close($ch);

        return (object) [
            'url' => $url,
            'headers' => $response[0],
            'data' => $response[1],
            'curlInfo' => (object) $info,
        ];
    }

    private function findResourcesInPageContents(string $contents): ?\stdClass
    {
        $resources = (object) [
            'remote' => [],
            'local' => [],
        ];

        $dom = new \DOMDocument();
        @$dom->loadHTML($contents);

        // grab all the on the page
        $xpath = new \DOMXPath($dom);
        $elements = $xpath->evaluate('//*[@src or @href]');

        $a = [];
        for ($ii = 0; $ii < $elements->length; ++$ii) {
            $item = $elements->item($ii);
            $resource = $item->getAttribute(
                (
                    $item->hasAttribute('src')
                        ? 'src'
                        : 'href'
                )
            );

            if ('#' == $resource[0] || 0 == strlen(trim($resource))) {
                continue;
            }

            $a[] = $resource;
        }

        $a = array_unique($a);

        foreach ($a as $resource) {
            $resources->{self::isResourceRemote($resource) ? 'remote' : 'local'}[] = trim($resource);
        }

        return $resources;
    }

    private function isResourceRemote(string $resource): bool
    {
        if ('/' == $resource[0]) {
            return false;
        }

        $parsedRoot = parse_url($this->location());
        $parsedResource = parse_url($resource);

        // If there is no scheme or host, then its a relative link
        if (false == isset($parsedResource['scheme']) || false == isset($parsedResource['host'])) {
            return false;
        }

        return 0 != strcasecmp($parsedRoot['host'], $parsedResource['host']);
    }
}
