<?php

declare(strict_types=1);

namespace pointybeard\Symphony\Extensions\Console\Commands\Crawler;

use Extension_Crawler;
use pointybeard\Symphony\Extensions\Console;
use pointybeard\Symphony\Extensions\Crawler;
use pointybeard\Helpers\Cli;
use pointybeard\Helpers\Foundation\BroadcastAndListen;

class Crawl extends Console\AbstractCommand implements Console\Interfaces\AuthenticatedCommandInterface, BroadcastAndListen\Interfaces\AcceptsListenersInterface
{
    use Console\Traits\hasCommandRequiresAuthenticateTrait;
    use BroadcastAndListen\Traits\HasListenerTrait;
    use BroadcastAndListen\Traits\HasBroadcasterTrait;

    public function __construct()
    {
        parent::__construct();
        $this
            ->description('Crawls local or remote website, following links and recording availablity')
            ->version('1.0.0')
            ->example(
                'symphony -t 4141e465 crawler crawl index'.PHP_EOL.
                'symphony -t 4141e465 crawler crawl --max-depth=5 --timeout=10 --base="https://mysite.com" "/articles/10/"'
            )
            ->support("If you believe you have found a bug, please report it using the GitHub issue tracker at https://github.com/pointybeard/crawler/issues, or better yet, fork the library and submit a pull request.\r\n\r\nCopyright 2020 Alannah Kearney. See ".realpath(__DIR__.'/../LICENCE')." for full software licence information.\r\n")
        ;
    }

    public function usage(): string
    {
        return 'Usage: symphony [OPTIONS]... crawler crawl <url>';
    }

    public function init(): void
    {
        parent::init();

        Extension_Crawler::init();

        $this
            ->addInputToCollection(
                Cli\Input\InputTypeFactory::build('Argument')
                    ->name('url')
                    ->flags(Cli\Input\AbstractInputType::FLAG_REQUIRED)
                    ->description('the url (relative or absolute) to begin crawling from. Relative url will assume $root when --base is not supplied.')
            )
            ->addInputToCollection(
                Cli\Input\InputTypeFactory::build('LongOption')
                    ->name('max-depth')
                    ->flags(Cli\Input\AbstractInputType::FLAG_OPTIONAL | Cli\Input\AbstractInputType::FLAG_VALUE_REQUIRED)
                    ->description('maximum page depth the crawler will go when traversing (default is 10)')
                    ->validator(
                        function (Cli\Input\AbstractInputType $input, Cli\Input\AbstractInputHandler $context) {
                            $value = (int) $context->find('max-depth');
                            if ($value <= 0) {
                                throw new \Exception('--max-depth must be a positive integer greater than 0.');
                            }

                            return $value;
                        }
                    )
                    ->default(10)
            )
            ->addInputToCollection(
                Cli\Input\InputTypeFactory::build('LongOption')
                    ->name('timeout')
                    ->flags(Cli\Input\AbstractInputType::FLAG_OPTIONAL | Cli\Input\AbstractInputType::FLAG_VALUE_REQUIRED)
                    ->description('maximum time spent (seconds) on any single request (default is 10 seconds)')
                    ->validator(
                        function (Cli\Input\AbstractInputType $input, Cli\Input\AbstractInputHandler $context) {
                            $value = (int) $context->find('timeout');
                            if ($value <= 0) {
                                throw new \Exception('--timeout must be a positive integer greater than 0.');
                            }

                            return $value;
                        }
                    )
                    ->default(10)
            )
            ->addInputToCollection(
                Cli\Input\InputTypeFactory::build('LongOption')
                    ->name('base')
                    ->flags(Cli\Input\AbstractInputType::FLAG_OPTIONAL | Cli\Input\AbstractInputType::FLAG_VALUE_REQUIRED)
                    ->description('helps crawler to more accurately follow relative links.')
                    ->default(null)
            )
        ;
    }

    public function recieveNotification($type, ...$arguments): void
    {
        // Currently not being used. Placeholder for future use
    }

    public function execute(Cli\Input\Interfaces\InputHandlerInterface $input): bool
    {
        Extension_Crawler::init();

        $maxDepth = $input->find('max-depth');
        $timeout = $input->find('timeout');
        $base = $input->find('base');
        $url = $input->find('url');

        $session = Crawler\Models\Session::open($maxDepth, $timeout, $base);

        // Eventually sessions will support multiple seeds to facilitate better
        // coverage
        $session->seed($url);

        $session->start(
            array_merge(
                (array) $this->listeners(),
                [[$this, 'recieveNotification']]
            )
        );

        return true;
    }
}
