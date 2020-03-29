<?php

declare(strict_types=1);

if (!file_exists(__DIR__.'/vendor/autoload.php')) {
    throw new Exception(sprintf(
        'Could not find composer autoload file %s. Did you run `composer update` in %s?',
        __DIR__.'/vendor/autoload.php',
        __DIR__
    ));
}

require_once __DIR__.'/vendor/autoload.php';

use pointybeard\Symphony\SectionBuilder;
use pointybeard\Symphony\Extensions\Crawler;
use pointybeard\Helpers\Functions\Json;
use pointybeard\Symphony\Extended;

// Check if the class already exists before declaring it again.
if (!class_exists('\\Extension_Crawler')) {
    class Extension_Crawler extends Extended\AbstractExtension
    {
        public function install()
        {
            parent::install();

            $this->createSections();
            $this->generateHTTPStatusCodeEntries();

            return true;
        }

        public function enable()
        {
            return $this->install();
        }

        private function generateHTTPStatusCodeEntries(): void
        {
            try {
                $codes = Json\json_decode_file(__DIR__.'/src/Install/httpcodes.json');
            } catch (\JsonException $ex) {
                throw new \Exception("Unable to install extension. 'src/Install/httpcodes.json' could not be loaded. Returned: ".$ex->getMessage());
            }

            foreach ($codes->groups as $group) {
                foreach ($group->codes as $c) {
                    $code = Crawler\Models\StatusCode::loadFromCode($c->code);

                    if (!($code instanceof Crawler\Models\StatusCode)) {
                        $code = new Crawler\Models\StatusCode();
                    }

                    $code
                        ->code($c->code)
                        ->description($c->description)
                        ->group($group->name)
                        ->save()
                    ;
                }
            }
        }

        private function createSections(): void
        {
            $statusCodesSection = SectionBuilder\Models\Section::loadFromHandle(
                'crawler-status-codes'
            );
            if (!($statusCodesSection instanceof SectionBuilder\Models\Section)) {
                SectionBuilder\Import::fromJsonFile(
                    __DIR__.'/src/Install/section-status-codes.json',
                    SectionBuilder\Import::FLAG_SKIP_ORDERING
                );
            }

            $sessionsSection = SectionBuilder\Models\Section::loadFromHandle(
                'crawler-sessions'
            );
            if (!($sessionsSection instanceof SectionBuilder\Models\Section)) {
                SectionBuilder\Import::fromJsonFile(
                    __DIR__.'/src/Install/section-sessions.json',
                    SectionBuilder\Import::FLAG_SKIP_ORDERING
                );
            }

            $resourcesSection = SectionBuilder\Models\Section::loadFromHandle(
                'crawler-resources'
            );
            if (!($resourcesSection instanceof SectionBuilder\Models\Section)) {
                SectionBuilder\Import::fromJsonFile(
                    __DIR__.'/src/Install/section-resources.json',
                    SectionBuilder\Import::FLAG_SKIP_ORDERING
                );
            }

            return;
        }
    }
}
