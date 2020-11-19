<?php

namespace GeorgRinger\NewsImporticsxml\Mapper;

/**
 * This file is part of the "news_importicsxml" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use GeorgRinger\NewsImporticsxml\Domain\Model\Dto\TaskConfiguration;
use SimpleXMLElement;
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
            $singleItem = [
                'import_source' => $this->getImportSource(),
                'import_id'     => $item['link'] ?? '',
                'crdate'        => $GLOBALS['EXEC_TIME'],
                'cruser_id'     => $GLOBALS['BE_USER']->user['uid'],
                'type'          => 0,
                'pid'           => $configuration->getPid(),
                'title'         => $item['title'],
                'bodytext'      => $this->cleanup($item['content'] ?? ''),
                'author'        => '',
                'media'         => '',
                //$this->getRemoteFile($item->getEnclosureUrl(), $item->getEnclosureType(), $item->getId()),
                'datetime'      => strtotime($item['publishdate']),
                'categories'    => '',
                //$this->getCategories($item->xml, $configuration),
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
     * @param $url
     * @param $mimeType
     * @param $id
     * @return array
     */
    protected function getRemoteFile($url, $mimeType, $id)
    {
        $extensions = [
            'image/jpeg'      => 'jpg',
            'image/gif'       => 'gif',
            'image/png'       => 'png',
            'application/pdf' => 'pdf',
        ];

        $media = [];
        if (!empty($url) && isset($extensions[$mimeType])) {
            $file = 'uploads/tx_newsimporticsxml/' . $id . '_' . md5($url) . '.' . $extensions[$mimeType];
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
     * @param SimpleXMLElement  $xml
     * @param TaskConfiguration $configuration
     * @return array
     */
    protected function getCategories(SimpleXMLElement $xml, TaskConfiguration $configuration)
    {
        $categoryIds = $categoryTitles = [];
        $categories  = $xml->category;
        if ($categories) {
            foreach ($categories as $cat) {
                $categoryTitles[] = (string)$cat;
            }
        }
        if (!empty($categoryTitles)) {
            if (!$configuration->getMapping()) {
                $this->logger->info('Categories found during import but no mapping assigned in the task!');
            } else {
                $categoryMapping = $configuration->getMappingConfigured();
                foreach ($categoryTitles as $title) {
                    if (!isset($categoryMapping[$title])) {
                        $this->logger->warning(sprintf('Category mapping is missing for category "%s"', $title));
                    } else {
                        $categoryIds[] = $categoryMapping[$title];
                    }
                }
            }
        }

        return $categoryIds;
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
