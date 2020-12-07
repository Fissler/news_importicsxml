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
 * Class Tag
 *
 * @package GeorgRinger\NewsImporticsxml\Domain\Model
 */
class Tag extends \GeorgRinger\News\Domain\Model\Tag
{
    /** @var int */
    protected $pid;

    /**
     * @return int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @param int $pid
     * @return $this
     */
    public function setPid($pid)
    {
        $this->pid = $pid;
        return $this;
    }
}
