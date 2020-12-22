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
use RuntimeException;
use TYPO3\CMS\Core\Resource\Exception\AbstractFileOperationException;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Class ImageImportService
 */
class ImageImportService extends AbstractImportService
{
    /**
     * @var \TYPO3\CMS\Core\DataHandling\DataHandler
     * @inject
     */
    protected $dataHandler;

    /**
     * @var \TYPO3\CMS\Core\Resource\FileRepository
     * @inject
     */
    protected $fileRepository;

    /**
     * @param array $mediaItem
     * @param int   $pid
     * @param int   $uid
     * @param int   $userId
     * @param int   $sys_language_uid
     * @return bool
     * @throws AbstractFileOperationException
     * @throws ExistingTargetFileNameException
     * @throws RuntimeException
     */
    public function createFalRelation(array $mediaItem, int $pid, int $uid, $userId = 1, $sys_language_uid = 0): bool
    {
        // get fileobject by given identifier (file UID, combined identifier or path/filename)
        try {
            $file = $this->getResourceFactory()->retrieveFileOrFolderObject($mediaItem['image']);
        } catch (ResourceDoesNotExistException $exception) {
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

        $l10n_parent = 0;
        if (MathUtility::canBeInterpretedAsInteger($mediaItem['uid'])) {
            $l10n_parent = $mediaItem['uid'];
        }
        // create a new entry in sys_file_reference, a string is necessary for a new entry
        $data['sys_file_reference'][''] = [
            'table_local'      => 'sys_file',
            'cruser_id'        => $userId,
            'uid_local'        => $file->getUid(),
            'tablenames'       => 'tt_content',
            'uid_foreign'      => $uid,
            'fieldname'        => 'image',
            'pid'              => $pid,
            'sys_language_uid' => $sys_language_uid,
            'l10n_parent'      => $l10n_parent,
            'title'            => $mediaItem['title'] ?? '',
            'alternative'      => $mediaItem['alt'] ?? '',
            'link'             => $mediaItem['link'] ?? '',
        ];

        $this->dataHandler->start($data, array());
        $this->dataHandler->process_datamap();

        // Error or success reporting
        return count($this->dataHandler->errorLog) === 0;
    }

    /**
     * @param int $uid
     * @return array
     * @throws \InvalidArgumentException
     */
    public function getFalRelationUids(int $uid): array
    {
        return $this->fileRepository->findByRelation('tt_content', 'image', $uid);
    }
}
