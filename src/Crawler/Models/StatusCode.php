<?php

declare(strict_types=1);

namespace pointybeard\Symphony\Extensions\Crawler\Models;

use pointybeard\Symphony\Classmapper;

final class StatusCode extends Classmapper\AbstractModel implements Classmapper\Interfaces\FilterableModelInterface, Classmapper\Interfaces\SortableModelInterface
{
    use Classmapper\Traits\HasModelTrait;
    use Classmapper\Traits\HasFilterableModelTrait;
    use Classmapper\Traits\HasSortableModelTrait;

    public function getSectionHandle(): string
    {
        return 'crawler-status-codes';
    }

    protected static function getCustomFieldMapping(): array
    {
        return [
            'name' => [
                'flags' => self::FLAG_STR | self::FLAG_NULL,
            ],

            'description' => [
                'flags' => self::FLAG_STR | self::FLAG_REQUIRED,
            ],

            'code' => [
                'flags' => self::FLAG_INT | self::FLAG_SORTBY | self::FLAG_SORTASC | self::FLAG_REQUIRED,
            ],

            'group' => [
                'flags' => self::FLAG_STR | self::FLAG_REQUIRED,
            ],
        ];
    }

    public function save(?int $flags = self::FLAG_ON_SAVE_VALIDATE, string $sectionHandle = null): self
    {
        $isNew = null == $this->id;

        $result = parent::save($flags, $sectionHandle);

        $section = \SectionManager::fetch(\SectionManager::fetchIDFromHandle($this->getSectionHandle()));
        $entry = \EntryManager::fetch($result->id);

        // Trigger the post save and post edit delegates. This will mean the
        // reflection field "compile" method is called to generate the contents
        // of the 'name' field.
        \Symphony::ExtensionManager()->notifyMembers(
            true == $isNew ? 'EntryPostCreate' : 'EntryPostEdit',
            true == $isNew ? '/publish/new/' : '/publish/edit/',
            [
                'section' => $section,
                'entry' => $entry[0],
                'fields' => $this->getData(),
            ]
        );

        return $result;
    }

    public static function loadFromCode(int $code): ?self
    {
        $result = (new self())
            ->appendFilter(Classmapper\FilterFactory::build('Basic', 'code', $code))
            ->filter()
            ->current()
        ;

        // current() returns true/false but we want to be returning NULL so we
        // cannot use the return value directly.
        return $result instanceof self ? $result : null;
    }
}
