<?php
/*
 * Copyright (c) 2008-2020 dotSource GmbH.
 * All rights reserved.
 * http://www.dotsource.de
 *
 * Contributors:
 * Sven Lamprecht - initial contents
 */
namespace GeorgRinger\NewsImporticsxml\Service;

use GeorgRinger\News\Domain\Service\AbstractImportService;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ImageImportService
 */
class ImageImportService extends AbstractImportService
{

    /**
     * @param array $mediaItem
     * @param int   $pid
     * @param int   $uid
     * @return bool
     * @throws \RuntimeException
     * @throws \TYPO3\CMS\Core\Resource\Exception\AbstractFileOperationException
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
     */
    public function createFalRelation(array $mediaItem, int $pid, int $uid): bool
    {
        // get fileobject by given identifier (file UID, combined identifier or path/filename)
        try {
            $file = $this->getResourceFactory()->retrieveFileOrFolderObject($mediaItem['image']);
        } catch (\TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException $exception) {
            $file = false;
        }

        // no file found skip processing of this item
        if ($file === false) {
            return false;
        }

        // file not inside a storage then search for same file based on hash (to prevent duplicates)
        if ($file->getStorage()->getUid() === 0) {
            $existingFile = $this->findFileByHash($file->getSha1());
            if ($existingFile !== null) {
                $file = $existingFile;
            }
        }

        // file not inside a storage copy the one form storage 0 to the import folder
        if ($file->getStorage()->getUid() === 0) {
            $file = $this->getResourceStorage()->copyFile($file, $this->getImportFolder());
        }

        $data['sys_file_reference']['aStringIsNecessary'] = [
            'table_local'   => 'sys_file',
            'uid_local'     => $file->getUid(),
            'tablenames'    => 'tt_content',
            'uid_foreign'   => $uid,
            'fieldname'     => 'image',
            'pid'           => $pid,
            'title'         => $mediaItem['title'] ?? '',
            'alternative'   => $mediaItem['alt'] ?? '',
            'link'          => $mediaItem['link'] ?? ''
        ];

        /** @var DataHandler $dataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, array());
        $dataHandler->process_datamap();

        // Error or success reporting
        if (count($dataHandler->errorLog) === 0) {
            return true;
        } else {
            return false;
        }
    }

}
