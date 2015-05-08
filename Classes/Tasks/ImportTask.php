<?php
namespace Cyberhouse\NewsImporticsxml\Tasks;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Cyberhouse\NewsImporticsxml\Domain\Model\Dto\TaskConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Provides testing procedures
 *
 * @author Markus Friedrich <markus.friedrich@dkd.de>
 */
class ImportTask extends AbstractTask {

	/** @var string */
	public $email;

	/** @var string */
	public $path;

	/** @var string */
	public $mapping;

	/** @var string */
	public $format;

	public function execute() {
		$success = TRUE;
		/** @var \TYPO3\CMS\Extbase\Object\ObjectManager $objectManager */
		$objectManager = GeneralUtility::makeInstance('TYPO3\CMS\Extbase\Object\ObjectManager');

		/** @var \Cyberhouse\NewsImporticsxml\Jobs\ImportJob $importJob */
		$importJob = $objectManager->get('Cyberhouse\\NewsImporticsxml\\Jobs\\ImportJob', $this->createConfiguration());
		$importJob->run();

		return $success;
	}

	/**
	 * This method returns additional information about the specific task
	 *
	 * @return string Information to display
	 */
	public function getAdditionalInformation() {
		return sprintf('%s: %s, %s: %s',
			$this->getLanguageService()->sL('LLL:EXT:news_importicsxml/Resources/Private/Language/locallang.xlf:path'), $this->path,
			$this->getLanguageService()->sL('LLL:EXT:scheduler/mod1/locallang.xlf:label.email'), $this->email);
	}

	/**
	 * @return TaskConfiguration
	 */
	protected function createConfiguration() {
		$configuration = new TaskConfiguration();
		$configuration->setEmail($this->email);
		$configuration->setPath($this->path);
		$configuration->setMapping($this->mapping);
		$configuration->setFormat($this->format);

		return $configuration;
	}

	/**
	 * Returns LanguageService
	 *
	 * @return \TYPO3\CMS\Lang\LanguageService
	 */
	protected function getLanguageService() {
		return $GLOBALS['LANG'];
	}

}