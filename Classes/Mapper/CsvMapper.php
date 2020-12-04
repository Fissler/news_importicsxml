<?php

namespace GeorgRinger\NewsImporticsxml\Mapper;

/**
 * This file is part of the "news_importicsxml" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use GeorgRinger\NewsImporticsxml\Domain\Model\Dto\TaskConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class CsvMapper
 *
 * @package GeorgRinger\NewsImporticsxml\Mapper
 */
class CsvMapper extends AbstractMapper implements MapperInterface
{

    /**
     * @param TaskConfiguration $configuration
     * @return array
     * @throws \Exception
     */
    public function map(TaskConfiguration $configuration)
    {
        $data = [];

        //Open file
        if (false === ($importFile = fopen(PATH_site . $configuration->getPath(), 'rb'))) {
            throw new RuntimeException("Can't open import file for reading.");
        }

        $items = $this->readCsvFile($importFile);

        foreach ($items as $item) {
            $content = $this->cleanup($item['content'] ?? '');
            $contentElements = $this->parseHtmlToContentElements($content);
            $singleItem = [
                'import_source' => $this->getImportSource(),
                'import_id'     => $item['link'] ?? '',
                'crdate'        => $GLOBALS['EXEC_TIME'],
                'cruser_id'     => $GLOBALS['BE_USER']->user['uid'],
                'type'          => 0,
                'pid'           => $configuration->getPid(),
                'title'         => $item['title'] ?? 'no Title(' . uniqid() . ')',
                'bodytext'      => '', //$content,
                'content'       => $contentElements,
                'content_elements' => '',
                'teaser'        => $this->findTeaser($contentElements),
                'author'        => '',
                'media'         => $this->getRemoteFile($this->findFirstImage($contentElements)),
                'datetime'      => strtotime($item['publishdate']),
                'categories'    => $this->getGroupingElements($item['categories']),
                'tags'          => $this->getGroupingElements($item['tags']),
                '_dynamicData'  => [
                    'reference'         => $item,
                    'news_importicsxml' => [
                        'importDate' => date('d.m.Y h:i:s', $GLOBALS['EXEC_TIME']),
                        'feed'       => $configuration->getPath(),
                        'url'        => $item['link'] ?? '',
                        'guid'       => '',
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
     * @param     $url
     * @param int $id
     * @return array
     */
    protected function getRemoteFile($url)
    {
        $extensions = [
            'image/jpeg'      => 'jpg',
            'image/gif'       => 'gif',
            'image/png'       => 'png',
            'application/pdf' => 'pdf',
        ];

        $media = [];
        $fileInfo = pathinfo($url);
        if (!empty($url)) {
            GeneralUtility::mkdir(PATH_site . 'uploads/tx_newsimporticsxml/');
            $file = 'uploads/tx_newsimporticsxml/' . $fileInfo['basename'];
            if (is_file(PATH_site . $file)) {
                $status = true;
            } else {
                $content = GeneralUtility::getUrl($url);
                $status  = GeneralUtility::writeFile(PATH_site . $file, $content);
            }

            if ($status) {
                $media[] = [
                    'image'         => $file,
                    'showinpreview' => true,
                ];
            }
        }
        return $media;
    }

    /**
     * @param string $elements
     * @return array
     */
    protected function getGroupingElements($elements): array
    {
        $groupingIds = [];
        $elements = str_replace(['[', ']', '"'], [''], $elements);
        $groupingElements = explode(',', $elements);
        if (!empty($groupingElements)) {
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
        $replace = [LF, LF, LF, LF];
        $out     = str_replace($search, $replace, $content);
        return $out;
    }

    /**
     * @param string $content
     * @return array
     */
    protected function parseHtmlToContentElements(string $content): array
    {
        $htmlDom = new \DOMDocument();
        $htmlDom->loadHTML('<html>' . $content . '</html>');
        $elements = $textImage = [];

        $domElements = $htmlDom->getElementsByTagName('*');

        /** @var \DOMElement $domElement */
        foreach ($domElements as $domElement) {
            $element = null;
            switch ($domElement->nodeName) {
                case 'img' :
                    $parent = $domElement->parentNode;
                    $link   = '';
                    if ($parent->nodeName === 'a') {
                        $link = $parent->getAttribute('href');
                    }
                    $element = [
                        'tag'    => 'img',
                        'params' => [
                            'src'   => $domElement->getAttribute('src'),
                            'alt'   => $domElement->getAttribute('alt'),
                            'title' => $domElement->getAttribute('title'),
                            'link'  => $link,
                        ],
                    ];
                    break;
                case 'p' :
                case 'div' :
                    if (($textContent = $domElement->textContent)) {
                        $element = [
                            'tag'    => 'p',
                            'params' => [
                                'textNode' => $textContent,
                                ]
                        ];
                    }
                    break;
                default :
            }
            if ($element) {
                $elements[] = $element;
            }
        }
        $elementsClone = $elements;
        foreach ($elements as $key => $element) {
            if (!array_key_exists($key, $elementsClone)) {
                continue;
            }
            $newContentElement = [];
            if ($element['tag'] === 'p') {
                $newContentElement['text'] = $element;
                if ($elements[$key + 1]['tag'] === 'img') {
                    $newContentElement['image'] = $elements[$key + 1];
                    unset($elementsClone[$key + 1]);
                }
            } else {
                $newContentElement['image'] = $element;
            }
            $textImage[] = $newContentElement;
        }
        return $textImage;
    }

    /**
     * @param array $contentElements
     * @return string
     */
    protected function findTeaser(array $contentElements): string
    {
        $pTag = $this->findFirstTag('text', $contentElements);
        $teaser = $pTag['params']['textNode'] ?? '';
        return substr($teaser, 0, strpos($teaser, ' ', 150));
    }

    /**
     * @param array $contentElements
     * @return string
     */
    protected function findFirstImage(array $contentElements): string
    {
        $imageTag = $this->findFirstTag('image', $contentElements);
        return $imageTag['params']['src'] ?? '';
    }

    /**
     * @param string $tag
     * @param array  $contentElements
     * @return mixed
     */
    protected function findFirstTag(string $tag, array $contentElements)
    {
        $tags = array_column($contentElements, $tag) ?? [];
        return reset($tags);
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
     * @throws \Exception
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
                throw new \Exception(
                    "The count of expected fields and imported fields isn't equal! row: " . $counter
                );
            }
            $importArray[] = $newRow;
            $counter++;
        }
        return $importArray;
    }
}
