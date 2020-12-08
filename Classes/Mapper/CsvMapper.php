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
use GeorgRinger\News\Domain\Model\TtContent;
use GeorgRinger\NewsImporticsxml\Domain\Model\Dto\TaskConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class CsvMapper
 *
 * @package GeorgRinger\NewsImporticsxml\Mapper
 */
class CsvMapper extends AbstractMapper implements MapperInterface
{
    const IMAGE_ORIENTATION_BELOW_CENTER = 8;

    /**
     * @var \GeorgRinger\News\Domain\Repository\TtContentRepository
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
     * @param TaskConfiguration $configuration
     * @return array
     * @throws Exception
     */
    public function map(TaskConfiguration $configuration)
    {
        $data = [];

        //Open file
        if (false === ($importFile = fopen(PATH_site . $configuration->getPath(), 'rb'))) {
            throw new RuntimeException("Can't open import file for reading.");
        }

        $items = $this->readCsvFile($importFile);
        $this->enableTagSearching();

        foreach ($items as $item) {
            $content         = $this->cleanup($item['content'] ?? '');
            $contentElements = $this->parseHtmlToContentElements($content);
            $singleItem      = [
                'hidden'           => ($item['status'] !== 'publish'),
                'import_source'    => $this->getImportSource(),
                'import_id'        => $item['link'] ?? '', //ToDo create unique import id from link or from import file
                'crdate'           => $GLOBALS['EXEC_TIME'],
                'cruser_id'        => $GLOBALS['BE_USER']->user['uid'],
                'type'             => 0,
                'pid'              => $configuration->getPid(),
                'title'            => $item['title'] ?? 'no Title(' . uniqid() . ')',
                'bodytext'         => '', //$content,
                'content_elements' => $this->createTextPicContentElements($contentElements, $configuration),
                'teaser'           => $this->findTeaser($contentElements),
                'author'           => '',
                'media'            => $this->getRemoteFile($this->findFirstImage($contentElements)),
                'datetime'         => strtotime($item['publishdate']),
                'categories'       => $this->getGroupingElements($item['categories'], 'category'),
                'tags'             => $this->getGroupingElements($item['tags'], 'tag'),
                '_dynamicData'     => [
                    'reference'         => $item,
                    'news_importicsxml' => [
                        'importDate' => date('d.m.Y h:i:s', $GLOBALS['EXEC_TIME']),
                        'feed'       => $configuration->getPath(),
                        'url'        => $item['link'] ?? '',
                        'guid'       => '', //ToDo import id
                    ],
                ],
            ];
            if ($configuration->isPersistAsExternalUrl()) {
                $singleItem['type']        = 2;
                $singleItem['externalurl'] = $item['link'] ?? '';
            }

            $data[] = $singleItem;
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
                    'link'          => $imageParams['link'] ?? ''
                ];
            }
        }
        return $media;
    }

    /**
     * @param string $elements
     * @param string $repository
     * @return array
     */
    protected function getGroupingElements(string $elements, string $repository): array
    {
        $groupingIds      = [];
        $elements         = str_replace(['[', ']', '"'], [''], $elements);
        $groupingElements = GeneralUtility::trimExplode(',', $elements, true);
        if (!empty($groupingElements)) {
            foreach ($groupingElements as $element) {
                $groupingElement = $this->{$repository . 'Repository'}->findByTitle($element);
                if (!$groupingElement->getFirst()) {
                    $newGroupElement = ($repository === 'tag')
                        ? GeneralUtility::makeInstance(
                            'GeorgRinger\\NewsImporticsxml\\Domain\\Model\\' . ucfirst($repository)
                        )
                        : GeneralUtility::makeInstance('GeorgRinger\\News\\Domain\\Model\\' . ucfirst($repository));
                    $newGroupElement->setTitle($element);
                    $newGroupElement->setSlug(strtolower($element));
                    //ToDo make Pid configurable
                    $newGroupElement->setPid(203);
                    $this->{$repository . 'Repository'}->add($newGroupElement);
                    $this->persistenceManager->persistAll();
                    $groupingElement = $this->{$repository . 'Repository'}->findByTitle($element);
                }
                $groupingIds[] = $groupingElement->getFirst()->getUid();
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
        $search  = ['<br />', '<br>', '<br/>', LF . LF];
        $replace = [''];
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
        $htmlDom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
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
                    case 'p' :
                    case 'div' :
                        if ($domElement->textContent) {
                            $element = [
                                'tag'       => $domElement->nodeName,
                                'class'     => $domElement->getAttribute('class'),
                                'style'     => $domElement->getAttribute('style'),
                                'elements'  => $this->searchDomChildElements($domElement),
                            ];
                        } elseif ($domElement->parentNode->nodeName !== 'div') {
                            $collage = $this->searchDomChildElements($domElement);
                            $images = [];
                            $counter = 1;
                            foreach ($collage as $item) {
                                $params = $item['elements'][0]['tag'] === 'img' ? $item['elements'][0]['params'] : $item['elements'][0]['elements'][0]['params'];
                                $images['img_' . $counter] = [
                                    'tag'   => 'img',
                                    'class' => $domElement->getAttribute('class') . ' ' . $item['class'],
                                    'params' => $params
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
                $textImage[] = $newContentElement;
                $newContentElement = [];
                }
            if (array_key_exists('tag', $element)) {
                $newContentElement['text'][] = $element;
            }
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
     */
    protected function createTextPicContentElements(array $contentElements, TaskConfiguration $configuration): string
    {
        $contentElementIds = [];
        foreach ($contentElements as $element) {
            /** @var TtContent $newContentElement */
            $newContentElement = GeneralUtility::makeInstance('GeorgRinger\\News\\Domain\\Model\\TtContent');
            $newContentElement->setPid($configuration->getPid());
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
                        $newContentElement->getUid()
                    );
                    $newContentElement->setImage((bool)$image);
                    $newContentElement->setImageorient(self::IMAGE_ORIENTATION_BELOW_CENTER);
                }
            }
            $newContentElement->setImagecols(count($element['images'] ?? []) > 1 ? 2 : 1);
        }
        return implode(',', $contentElementIds);
    }

    /**
     * @param array $contentElements
     * @return string
     */
    protected function findTeaser(array $contentElements): string
    {
        $teaser   = $this->findFirstTag('textnode', $contentElements);
        return substr($teaser, 0, strpos($teaser, ' ', 150));
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
            $bodytext = '<' . $elements['tag'] . ' class="' . $elements['class'] . '" style="' . $elements['style'] . '">';
            foreach ($elements['elements'] as $element) {
                $bodytext .= $this->getBodytextElements($element);
            }
            $bodytext .= '</' . $elements['tag'] . '>';
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
                    'tag'       => $item->nodeName,
                    'class'     => $item->getAttribute('class'),
                    'style'     => $item->getAttribute('style'),
                    'elements'  => $children,
                    'params'    => $params,
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
    public function readCsvFile($file, array $head = [], $length = 12288, $delimiter = ','): array
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
     * @throws \InvalidArgumentException
     */
    protected function enableTagSearching()
    {
        $objectManager = GeneralUtility::makeInstance('TYPO3\\CMS\Extbase\\Object\\ObjectManager');
        $querySettings = $objectManager->get('TYPO3\\CMS\\Extbase\\Persistence\\Generic\\Typo3QuerySettings');
        $querySettings->setRespectStoragePage(false);

        $this->tagRepository->setDefaultQuerySettings($querySettings);
    }
}
