<?php

namespace GeorgRinger\NewsImporticsxml\Domain\Model\Dto;

/**
 * This file is part of the "news_importicsxml" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Configuration of the import task
 */
class TaskConfiguration
{

    /** @var string */
    protected $email;

    /** @var string */
    protected $path;

    /** @var string */
    protected $mapping;

    /** @var string */
    protected $format;

    /** @var int */
    protected $pid;

    /** @var bool */
    protected $persistAsExternalUrl = false;

    /** @var int */
    protected $cat_pid;

    /** @var int */
    protected $lang;

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param string $email
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @param string $path
     */
    public function setPath($path)
    {
        $this->path = $path;
    }

    /**
     * @return string
     */
    public function getMapping()
    {
        return $this->mapping;
    }

    /**
     * @param string $mapping
     */
    public function setMapping($mapping)
    {
        $this->mapping = $mapping;
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param string $format
     */
    public function setFormat($format)
    {
        $this->format = $format;
    }

    /**
     * @return int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @param int $pid
     */
    public function setPid($pid)
    {
        $this->pid = $pid;
    }

    /**
     * @return bool
     */
    public function isPersistAsExternalUrl()
    {
        return $this->persistAsExternalUrl;
    }

    /**
     * @param bool $persistAsExternalUrl
     */
    public function setPersistAsExternalUrl($persistAsExternalUrl)
    {
        $this->persistAsExternalUrl = (bool)$persistAsExternalUrl;
    }

    /**
     * Split the configuration from multiline to array
     * 123:This is a category title
     * 345:And another one
     *
     * @return array
     */
    public function getMappingConfigured()
    {
        $out = [];
        $lines = GeneralUtility::trimExplode(LF, $this->mapping, true);
        foreach ($lines as $line) {
            $split = GeneralUtility::trimExplode(':', $line, true, 2);
            $out[$split[1]] = $split[0];
        }

        return $out;
    }

    /**
     * @return int
     */
    public function getCatPid()
    {
        return $this->cat_pid;
    }

    /**
     * @param int $cat_pid
     * @return TaskConfiguration
     */
    public function setCatPid(int $cat_pid): TaskConfiguration
    {
        $this->cat_pid = $cat_pid;
        return $this;
    }

    /**
     * @return int
     */
    public function getLang()
    {
        return $this->lang;
    }

    /**
     * @param int $lang
     * @return TaskConfiguration
     */
    public function setLang(int $lang): TaskConfiguration
    {
        $this->lang = $lang;
        return $this;
    }

}
