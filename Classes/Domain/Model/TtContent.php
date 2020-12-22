<?php
/*
 * Copyright (c) 2008-2020 dotSource GmbH.
 * All rights reserved.
 * http://www.dotsource.de
 *
 * Contributors:
 * Sven Lamprecht - initial contents
 */
namespace GeorgRinger\NewsImporticsxml\Domain\Model;
/**
 * Class TtContent
 *
 * @package GeorgRinger\NewsImporticsxml\Domain\Model
 */
class TtContent extends \GeorgRinger\News\Domain\Model\TtContent
{
    /** @var int */
    protected $cruser_id;

    /** @var int */
    protected $sys_language_uid;

    /** @var int */
    protected $_languageUid;

    /** @var int */
    protected $l18n_parent;

    /**
     * @return mixed
     */
    public function getSysLanguageUid()
    {
        return $this->sys_language_uid;
    }

    /**
     * @param mixed $sys_language_uid
     * @return $this
     */
    public function setSysLanguageUid($sys_language_uid)
    {
        $this->sys_language_uid = $sys_language_uid;
        return $this;
    }

    /**
     * @return int
     */
    public function getLanguageUid()
    {
        return $this->_languageUid;
    }

    /**
     * @param int $languageUid
     * @return $this
     */
    public function setLanguageUid($languageUid)
    {
        $this->_languageUid = $languageUid;
        return $this;
    }

    /**
     * @return int
     */
    public function getL18nParent()
    {
        return $this->l18n_parent;
    }

    /**
     * @param int $l18n_parent
     * @return $this
     */
    public function setL18nParent($l18n_parent)
    {
        $this->l18n_parent = $l18n_parent;
        return $this;
    }

    /**
     * @return int
     */
    public function getCruserId()
    {
        return $this->cruser_id;
    }

    /**
     * @param int $cruser_id
     * @return $this
     */
    public function setCruserId($cruser_id)
    {
        $this->cruser_id = $cruser_id;
        return $this;
    }

}
