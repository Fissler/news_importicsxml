<?php
/*
 * Copyright (c) 2008-2020 dotSource GmbH.
 * All rights reserved.
 * http://www.dotsource.de
 *
 * Contributors:
 * Sven Lamprecht - initial contents
 */

namespace GeorgRinger\NewsImporticsxml\Mapper;

/**
 * This file is part of the "news_importicsxml" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use DOMDocument;
use DOMElement;
use Exception;
use GeorgRinger\NewsImporticsxml\Domain\Model\TtContent;
use GeorgRinger\NewsImporticsxml\Domain\Model\Dto\TaskConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;

/**
 * Class CsvMapper
 *
 * @package GeorgRinger\NewsImporticsxml\Mapper
 */
class CsvMapper extends AbstractMapper implements MapperInterface
{
    const IMAGE_ORIENTATION_BELOW_CENTER = 8;
    const TITLE_LANGUAGE_COPY_MARKER = '..[0]';

    /**
     * @var \GeorgRinger\News\Domain\Repository\NewsRepository
     * @inject
     */
    protected $newsRepository;

    /**
     * @var \GeorgRinger\NewsImporticsxml\Domain\Repository\TtContentRepository
     * @inject
     */
    protected $ttContentRepository;

    /**
     * @var \GeorgRinger\News\Domain\Repository\CategoryRepository
     * @inject
     */
    protected $categoryRepository;

    /**
     * @var \GeorgRinger\News\Domain\Repository\TagRepository
     * @inject
     */
    protected $tagRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager
     * @inject
     */
    protected $persistenceManager;

    /**
     * @var \GeorgRinger\NewsImporticsxml\Service\ImageImportService
     * @inject
     */
    protected $imageImportService;

    /**
     * @var \TYPO3\CMS\Core\DataHandling\DataHandler
     * @inject
     */
    protected $dataHandler;

    /** @var int */
    protected $targetLanguage;

    /** @var int */
    protected $userId;

    /**
     * @param TaskConfiguration $configuration
     * @return array
     * @throws Exception
     */
    public function map(TaskConfiguration $configuration): array
    {
        $data                 = [];
        $this->userId         = $GLOBALS['BE_USER']->user['uid'] ?? 1;
        $this->targetLanguage = $configuration->getLang();

        //Open file
        if (false === ($importFile = fopen(PATH_site . $configuration->getPath(), 'rb'))) {
            throw new RuntimeException("Can't open import file for reading.");
        }

        $items = $this->readCsvFile($importFile);
        $items = $this->fieldConverter($items);
        $this->enableTagSearching();

        foreach ($items as $item) {
            if ($this->checkImportedIdAndSource($item)) {
                continue;
            }
            $content         = $this->cleanup($item['content'] ?? '');
            $contentElements = $this->parseHtmlToContentElements($content);
            $singleItem      = [
                'hidden'           => (($item['status'] ?? 'false') !== 'publish'),
                'import_source'    => $this->getImportSource(),
                'import_id'        => md5(($item['author'] ?? '') . '_' . ($item['id'] ?? $item['link'] ?? uniqid())),
                'crdate'           => $GLOBALS['EXEC_TIME'],
                'cruser_id'        => $this->userId,
                'type'             => 0,
                'pid'              => $configuration->getPid(),
                'title'            => $item['title'] ?? 'no Title(' . uniqid() . ')',
                'path_segment'     => pathinfo($item['link'] ?? '', PATHINFO_BASENAME),
                'bodytext'         => '', //$content,
                'content_elements' => $this->createTextPicContentElements($contentElements, $configuration),
                'teaser'           => $this->findTeaser($contentElements),
                'author'           => '',
                'media'            => $this->getRemoteFile($this->findFirstImage($contentElements)),
                'datetime'         => strtotime($item['post_date'] ?? $item['publishdate'] ?? ''),
                'categories'       => $this->getGroupingElements(
                    $item['categories'] ?? '',
                    'category',
                    $configuration->getCatPid()
                ),
                'tags'             => $this->getGroupingElements($item['tags'] ?? '', 'tag', $configuration->getCatPid()),
                '_dynamicData'     => [
                    'reference'         => $item,
                    'news_importicsxml' => [
                        'importDate' => date('d.m.Y h:i:s', $GLOBALS['EXEC_TIME']),
                        'feed'       => $configuration->getPath(),
                        'url'        => $item['link'] ?? '',
                        'guid'       => $item['id'] ?? '',
                        'author'     => $item['author'] ?? '',
                    ],
                ],
            ];
            if ($configuration->isPersistAsExternalUrl()) {
                $singleItem['type']        = 2;
                $singleItem['externalurl'] = $item['link'] ?? '';
            }
            $l10nItem = [];
            if ($this->targetLanguage) {
                $singleItem['title']            .= self::TITLE_LANGUAGE_COPY_MARKER;
                $l10nItem                       = $singleItem;
                $singleItem['content_elements'] = '';
                $singleItem['media']            = '';
            }

            $data[] = $singleItem;
            if ($this->targetLanguage) {
                $l10nItem['title'] = str_replace(self::TITLE_LANGUAGE_COPY_MARKER, '', $l10nItem['title']);
                foreach ((GeneralUtility::trimExplode(',', $l10nItem['content_elements'] ?? [])) as $uid) {
                    /** @var TtContent $originElement */
                    $originElement = $this->ttContentRepository->findByUid($uid);
                    $originElement->setSysLanguageUid($this->targetLanguage);
                    $originElement->setLanguageUid($this->targetLanguage);
                    $this->ttContentRepository->update($originElement);
                }
                $l10nItem['sys_language_uid'] = $this->targetLanguage;
                $l10nItem['l10n_parent']      = $singleItem['import_id'];
                $l10nItem['import_id']        = $singleItem['import_id'] . '_' . $this->targetLanguage;
                $data[]                       = $l10nItem;
            }
        }

        return $data;
    }

    /**
     * @param array $imageParams
     * @return array
     */
    protected function getRemoteFile(array $imageParams): array
    {
        $media    = [];
        $fileInfo = pathinfo($imageParams['src']);
        if (!empty($imageParams['src'])) {
            //ToDo make path configurable
            GeneralUtility::mkdir(PATH_site . 'uploads/tx_newsimporticsxml/');
            $file = 'uploads/tx_newsimporticsxml/' . $fileInfo['basename'];
            if (is_file(PATH_site . $file)) {
                $status = true;
            } else {
                $content = GeneralUtility::getUrl($imageParams['src']);
                $status  = GeneralUtility::writeFile(PATH_site . $file, $content);
            }

            if ($status) {
                $media[] = [
                    'image'         => $file,
                    'showinpreview' => true,
                    'alt'           => $imageParams['alt'] ?? '',
                    'title'         => $imageParams['title'] ?? '',
                    'link'          => $imageParams['link'] ?? '',
                ];
            }
        }
        return $media;
    }

    /**
     * @param string $elements
     * @param string $repository
     * @param int    $categoryPid
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function getGroupingElements(string $elements, string $repository, $categoryPid = 0): array
    {
        $groupingIds      = [];
        $elements         = str_replace(['[', ']', '"'], [''], $elements);
        $groupingElements = GeneralUtility::trimExplode(',', $elements, true);
        if (!empty($groupingElements)) {
            foreach ($groupingElements as $element) {
                $groupingElement = $this->{$repository . 'Repository'}->findByTitle(ucfirst($element));
                $l10nGroup       = false;
                if (!$groupingElement->getFirst()) {
                    $newGroupElement = ($repository === 'tag')
                        ? GeneralUtility::makeInstance(
                            'GeorgRinger\\NewsImporticsxml\\Domain\\Model\\' . ucfirst($repository)
                        )
                        : GeneralUtility::makeInstance('GeorgRinger\\News\\Domain\\Model\\' . ucfirst($repository));
                    $newGroupElement->setTitle(ucfirst($element));
                    $newGroupElement->setSlug(strtolower($element));
                    $newGroupElement->setPid($categoryPid);
                    $this->{$repository . 'Repository'}->add($newGroupElement);
                    $this->persistenceManager->persistAll();
                    $groupingElement = $this->{$repository . 'Repository'}->findByTitle(ucfirst($element));
                }
                if ($this->targetLanguage) {
                    $table = $repository === 'tag' ? 'tx_news_domain_model_tag' : 'sys_category';
                    $this->dataHandler->start([], []);
                    $l10nGroup = $this->dataHandler->localize($table, $groupingElement->getFirst()->getUid(), $this->targetLanguage);
                }
                $groupingIds[] = $l10nGroup ? $l10nGroup : $groupingElement->getFirst()->getUid();
            }
        }
        return $groupingIds;
    }

    /**
     * @param string $content
     * @return string
     */
    protected function cleanup(string $content): string
    {
        $search  = [LF . LF, '&nbsp;', '& '];
        $replace = ['', '', '&amp; '];
        $out     = str_replace($search, $replace, $content);
        return $out;
    }

    /**
     * @param string $content
     * @return array
     */
    protected function parseHtmlToContentElements(string $content): array
    {
        $htmlDom = new DOMDocument();
        @$htmlDom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
        $elements = $textImage = [];

        /** @var DOMNodeList $domElements */
        $domElements = $htmlDom->getElementsByTagName('*');

        /** @var DOMElement $domElement */
        foreach ($domElements as $domElement) {
            $element = null;
            if ($domElement->hasChildNodes()) {
                switch ($domElement->nodeName) {
                    case 'h1' :
                    case 'h2' :
                    case 'h3' :
                    case 'h4' :
                    case 'h5' :
                    case 'h6' :
                    case 'blockquote' :
                    case 'p' :
                        if ($domElement->textContent && $domElement->parentNode->nodeName !== 'div') {
                            $element = [
                                'tag'      => $domElement->nodeName,
                                'class'    => $domElement->getAttribute('class'),
                                'style'    => $domElement->getAttribute('style'),
                                'elements' => $this->searchDomChildElements($domElement),
                            ];
                        }
                        break;
                    case 'div' :
                         if ($domElement->parentNode->nodeName !== 'div') {
                            $collage = $this->searchDomChildElements($domElement);
                            $images  = [];
                            $counter = 1;
                            foreach ($collage as $item) {
                                $params = $item['elements'][0]['tag'] === 'img'
                                    ? $item['elements'][0]['params']
                                    : $item['elements'][0]['elements'][0]['params'];

                                $images['img_' . $counter] = [
                                    'tag'    => 'img',
                                    'class'  => $domElement->getAttribute('class'),
                                    'params' => $params,
                                ];
                                $counter++;
                            }
                            $element['collage'] = $images ?? null;
                        }
                        break;
                    default :
                }
            }
            if ($element) {
                $elements[] = $element;
            }
        }
        $newContentElement = [];
        foreach ($elements as $element) {
            if (array_key_exists('collage', $element)) {
                $newContentElement['images'] = $element['collage'];
                $textImage[]                 = $newContentElement;
                $newContentElement           = [];
            }
            if (array_key_exists('tag', $element)) {
                $newContentElement['text'][] = $element;
            }
        }
        if ($newContentElement) {
            $textImage[] = $newContentElement;
        }
        return $textImage;
    }

    /**
     * @param array             $contentElements
     * @param TaskConfiguration $configuration
     * @return string
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \TYPO3\CMS\Core\Resource\Exception\AbstractFileOperationException
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException
     */
    protected function createTextPicContentElements(array $contentElements, TaskConfiguration $configuration): string
    {
        $contentElementIds = [];
        foreach ($contentElements as $element) {
            /** @var TtContent $newContentElement */
            $newContentElement = GeneralUtility::makeInstance('GeorgRinger\\NewsImporticsxml\\Domain\Model\\TtContent');
            $newContentElement->setPid($configuration->getPid());
            $newContentElement->setCruserId($this->userId);
            $newContentElement->setCType('textpic');
            $bodytext = '';
            foreach ($element['text'] ?? [] as $textElement) {
                $bodytext .= $this->getBodyTextElements($textElement);
            }
            $newContentElement->setBodytext($bodytext);
            $this->ttContentRepository->add($newContentElement);
            $this->persistenceManager->persistAll();
            $contentElementIds[] = $newContentElement->getUid();
            foreach ($element['images'] ?? [] as $item) {
                $media = $this->getRemoteFile($item['params'] ?? []);
                if ($media) {
                    $image = $this->imageImportService->createFalRelation(
                        $media[0],
                        $configuration->getPid(),
                        $newContentElement->getUid(),
                        $this->userId
                    );
                }
            }
            $newContentElement->setImage((bool)count($element['images'] ?? []));
            $newContentElement->setImageorient(self::IMAGE_ORIENTATION_BELOW_CENTER);
            $newContentElement->setImagecols(count($element['images'] ?? []) > 1 ? 2 : 1);
            $this->ttContentRepository->update($newContentElement);
        }
        $this->persistenceManager->persistAll();
        return implode(',', $contentElementIds);
    }

    /**
     * @param array $contentElements
     * @return string
     */
    protected function findTeaser(array $contentElements): string
    {
        $teaser = $this->findFirstTag('textnode', $contentElements);
        $teaser = str_replace('&amp;', '&', $teaser);
        return strlen($teaser) > 150 ? substr($teaser, 0, strpos($teaser, ' ',  150)) : $teaser;
    }

    /**
     * @param array $contentElements
     * @return array
     */
    protected function findFirstImage(array $contentElements): array
    {
        $imageTag = $this->findFirstTag('img_1', $contentElements);
        return $imageTag['params'] ?? [];
    }

    /**
     * @param string $tag
     * @param array  $contentElements
     * @return mixed
     */
    protected function &findFirstTag(string $tag, array &$contentElements)
    {
        $result = false;
        if (array_key_exists($tag, $contentElements)) {
            $result =& $contentElements[$tag];
            return $result;
        }
        foreach ($contentElements as $k => $v) {
            if (is_array($v)) {
                $result =& $this->findFirstTag($tag, $contentElements[$k]);
                if ($result) {
                    return $result;
                }
            }
        }
        return $result;
    }

    /**
     * @param array $elements
     * @return string
     */
    protected function &getBodyTextElements(array $elements): string
    {
        if (array_key_exists('tag', $elements)) {
            $href = $style = $class = '';
            if ($elements['tag'] === 'time') {
                $elements['tag'] = 'span';
            }
            if ($elements['href']) {
                $url  = str_replace(['http:', '%20'], ['https:', ''], $elements['href']);
                $href = ' href="' . $url . '"';
            }
            if ($elements['class']) {
                $class = ' class="' . $elements['class'] . '"';
            }
            if ($elements['style']) {
                $style = ' style="' . $elements['style'] . '"';
            }
            $bodytext = $elements['tag'] !== 'br'
                ?
                '<'
                . $elements['tag']
                . $class
                . $style
                . $href
                . '>'
                : '<br>';
            foreach ($elements['elements'] as $element) {
                $bodytext .= $this->getBodytextElements($element);
            }
            $bodytext .= $elements['tag'] !== 'br'
                ? '</' . $elements['tag'] . '>'
                : '';
        } else {
            return $elements['textnode'];
        }
        return $bodytext;
    }

    /**
     * @param DOMElement $domElement
     * @return array
     */
    protected function searchDomChildElements(DOMElement $domElement): array
    {
        $childItems = [];
        foreach ($domElement->childNodes as $item) {
            if ($item instanceof DOMElement) {
                $children = $params = [];
                if ($item->hasChildNodes()) {
                    $children = $this->searchDomChildElements($item);
                } else {
                    $parent = $item->parentNode;
                    $link   = '';
                    if ($parent->nodeName === 'a') {
                        $link = $parent->getAttribute('href');
                    }
                    $params = [
                        'src'   => $item->getAttribute('src'),
                        'alt'   => $item->getAttribute('alt'),
                        'title' => $item->getAttribute('title'),
                        'link'  => $link,
                    ];
                }
                $childItems[] = [
                    'tag'      => $item->nodeName,
                    'class'    => $item->getAttribute('class'),
                    'style'    => $item->getAttribute('style'),
                    'href'     => $item->getAttribute('href'),
                    'elements' => $children,
                    'params'   => $params,
                ];
            } elseif ($item instanceof \DOMText) {
                $childItems[] = [
                    'textnode' => $item->wholeText,
                ];
            }
        }
        return $childItems;
    }

    /**
     * @return string
     */
    public function getImportSource(): string
    {
        return 'newsimporticsxml_csv';
    }

    /**
     * @param        $file
     * @param array  $head
     * @param int    $length
     * @param string $delimiter
     * @return array
     * @throws Exception
     */
    public function readCsvFile($file, array $head = [], $length = 13312, $delimiter = ','): array
    {
        $importArray = [];
        $counter     = 0;
        while (($row = fgetcsv($file, $length, $delimiter)) !== false) {
            if (!$head) {
                $head = $row ? array_map(
                    static function ($a) {
                        // remove all unicode from fieldname
                        $a = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $a);
                        return strtolower(str_replace(['"', '&nbsp;'], '', $a));
                    },
                    $row
                ) : [];
                continue;
            }
            $newRow = array_combine($head, $row);
            if (!$newRow) {
                throw new Exception(
                    "The count of expected fields and imported fields isn't equal! row: " . $counter
                );
            }
            $importArray[] = $newRow;
            $counter++;
        }
        return $importArray;
    }

    /**
     *
     * @throws \InvalidArgumentException
     */
    protected function enableTagSearching()
    {
        $objectManager = GeneralUtility::makeInstance('TYPO3\\CMS\Extbase\\Object\\ObjectManager');
        /** @var Typo3QuerySettings $querySettings */
        $querySettings = $objectManager->get('TYPO3\\CMS\\Extbase\\Persistence\\Generic\\Typo3QuerySettings');
        $querySettings->setRespectStoragePage(false);

        $this->tagRepository->setDefaultQuerySettings($querySettings);
    }

    /**
     * @param array $items
     * @return array
     */
    protected function fieldConverter(array $items): array
    {
        $fieldMapper    = [
            'pubdate'               => 'publishdate',
            'post_date/__text'      => 'post_date',
            'creator/__cdata'       => 'author',
            'encoded/*/__cdata'     => 'content',
            'post_id/__text'        => 'id',
            'status/__text'         => 'status',
            'category/*/__cdata'    => 'taxonomy_title',
        ];
        $convertedItems = [];

        foreach ($items as $item) {
            $convertedItem = [];
            foreach (array_keys($item) as $property) {
                $separator      = '';
                $mappedProperty = preg_replace('/\d+/', '*', $property);
                $newProperty    = array_key_exists($mappedProperty, $fieldMapper)
                    ? $fieldMapper[$mappedProperty]
                    : $property;
                if ($newProperty === 'taxonomy_title') {
                    //find categories or tags and collect them in a new property
                    $taxonomyTypeProperty = str_replace('__cdata', '_domain', $property);
                    $newProperty          = strtolower($item[$taxonomyTypeProperty]) === 'category'
                        ? 'categories'
                        : 'tags';
                    $separator            = $convertedItem[$newProperty] ? ',' : '';
                }
                if ($item[$property]) {
                    $convertedItem[$newProperty] .= $separator . $item[$property];
                }
            }
            $convertedItems[] = $convertedItem;
        }
        return $convertedItems;
    }

    /**
     * @param array $item
     * @return bool
     */
    protected function checkImportedIdAndSource(array $item): bool
    {
        return (bool)$this->newsRepository->findOneByImportSourceAndImportId(
            $this->getImportSource(),
            md5(($item['author'] ?? '') . '_' . ($item['id'] ?? $item['link'] ?? 0)));
    }
}
