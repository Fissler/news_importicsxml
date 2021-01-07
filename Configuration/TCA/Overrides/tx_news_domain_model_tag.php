<?php
if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}
    $GLOBALS['TCA']['tx_news_domain_model_tag']['columns']['title']['config']['eval'] = 'required, trim';
