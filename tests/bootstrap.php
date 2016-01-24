<?php
define('OAI_PMH_STATIC_REPOSITORY_DIR', dirname(dirname(__FILE__)));
define('TEST_FILES_DIR', OAI_PMH_STATIC_REPOSITORY_DIR
    . DIRECTORY_SEPARATOR . 'tests'
    . DIRECTORY_SEPARATOR . 'suite'
    . DIRECTORY_SEPARATOR . '_files');
require_once dirname(dirname(OAI_PMH_STATIC_REPOSITORY_DIR)) . '/application/tests/bootstrap.php';
require_once 'OaiPmhStaticRepository_Test_AppTestCase.php';
