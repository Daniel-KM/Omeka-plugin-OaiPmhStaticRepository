<?php
/**
 * @internal This is quite an integration test because Builder is a main class.
 */
class OaiPmhStaticRepository_BuilderTest extends OaiPmhStaticRepository_Test_AppTestCase
{
    protected $_isAdminTest = true;

    protected $expectedBaseDir = '';

    public function setUp()
    {
        parent::setUp();

        // Authenticate and set the current user.
        $this->user = $this->db->getTable('User')->find(1);
        $this->_authenticateUser($this->user);

        $this->_expectedBaseDir = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Results'
            . DIRECTORY_SEPARATOR . 'StaticRepositories';
    }

    public function testConstruct()
    {
        $this->_prepareFolderTest();

        $folder = &$this->_folder;
        $folders = $this->db->getTable('OaiPmhStaticRepository')->findAll();
        $this->assertEquals(1, count($folders), 'There should be one OAI-PMH static repository.');

        $parameters = $folder->getParameters();

        $this->assertEquals('by_file', $parameters['unreferenced_files']);
        $this->assertEquals(TEST_FILES_DIR . DIRECTORY_SEPARATOR . 'Folder_Test', $folder->uri);
        $this->assertEquals(OaiPmhStaticRepository::STATUS_ADDED, $folder->status);

        $this->assertEquals('short_name', $parameters['oai_identifier_format']);
        $this->assertEquals(
            '[' . TEST_FILES_DIR . DIRECTORY_SEPARATOR . 'Folder_Test' . ']',
            $parameters['repository_name']);
        $this->assertEquals(
            array(
                'oai_dc', 'oai_dcterms', 'oai_dcq', 'mets', 'doc',
            ),
            $parameters['metadata_formats']);
        $this->assertEquals('Folder_Test', $parameters['repository_identifier']);
        $this->assertTrue($parameters['oaipmh_gateway']);
        $this->assertTrue($parameters['oaipmh_harvest']);
        $this->assertEquals('doc', $parameters['oaipmh_harvest_prefix']);

        $this->assertEquals(WEB_ROOT . '/repository/Folder_Test/', $parameters['repository_folder']);
        $this->assertEquals(WEB_ROOT . '/repository/Folder_Test.xml', $parameters['repository_url']);
        $this->assertEquals(WEB_FILES . '/' . get_option('oai_pmh_static_repository_static_dir') . '/Folder_Test.xml',
            $folder->getStaticRepositoryBaseUrl());

        $this->assertEquals(FILES_DIR
            . DIRECTORY_SEPARATOR . get_option('oai_pmh_static_repository_static_dir')
            . DIRECTORY_SEPARATOR . 'Folder_Test.xml',
            $folder->getLocalRepositoryFilepath());
        $this->assertEquals(FILES_DIR
            . DIRECTORY_SEPARATOR . get_option('oai_pmh_static_repository_static_dir')
            . DIRECTORY_SEPARATOR . 'Folder_Test',
            $folder->getCacheFolder());
    }

    public function testByFile()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test'
            . DIRECTORY_SEPARATOR . 'Basic';

        $parameters = array(
            'repository_name' => 'Folder Test by File',
            'add_relations' => true,
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_BasicByFile.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    public function testUpdate()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test'
            . DIRECTORY_SEPARATOR . 'Basic';

        $parameters = array(
            'repository_name' => 'Folder Test by File',
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_BasicByFile.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $folder = &$this->_folder;

        // Update the folder (no change).
        $folder->process(OaiPmhStaticRepository_Builder::TYPE_UPDATE);
        $this->assertEquals(OaiPmhStaticRepository::STATUS_COMPLETED,
            $folder->status, 'Folder update failed: ' . $folder->messages);
    }

    public function testByDirectory()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test'
            . DIRECTORY_SEPARATOR . 'Basic';

        $parameters = array(
            'repository_name' => 'Folder Test by Directory',
            'repository_identifier' => 'BasicByDirectory',
            'unreferenced_files' => 'by_directory',
            'add_relations' => true,
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_BasicByDirectory.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    public function testSimpleFolder()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test'
            . DIRECTORY_SEPARATOR . 'Dir_A';

        $parameters = array(
            'repository_name' => 'Folder Test Simple',
            'add_relations' => true,
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_DirA.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    public function testCollections()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test_Collections';

        $parameters = array(
            'repository_name' => 'Folder Test Collections',
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_Collections.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    public function testFullFolder()
    {
        if (!plugin_is_active('OcrElementSet')) {
            $this->markTestSkipped(
                __('This test requires OcrElementSet.')
            );
        }

        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test';

        $parameters = array(
            'repository_name' => 'Folder Test Full',
            'unreferenced_files' => 'by_directory',
            'add_relations' => true,
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_Full.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    public function testMetsAlto()
    {
        if (!plugin_is_active('OcrElementSet')) {
            $this->markTestSkipped(
                __('This test requires OcrElementSet.')
            );
        }

        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test_Mets_Alto';

        $parameters = array(
            'repository_name' => 'Folder Test Mets Alto',
            'records_for_files' => true,
            'add_relations' => true,
            'ocr_fill_text' => true,
            'ocr_fill_data' => true,
            'ocr_fill_process' => true,
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_Mets_Alto.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    public function testXmlOmeka()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test_Xml_Omeka';

        $parameters = array(
            'repository_name' => 'Folder Test Xml Omeka',
            'extra_parameters' => array(
                'base_url' => $uri,
            ),
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_Xml_Omeka.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    /*
    public function testXmlMag()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test_Xml_Mag';

        $parameters = array(
            'repository_name' => 'Folder Test Xml Mag',
            'extra_parameters' => array(
                'base_url' => $uri,
            ),
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_Xml_Mag.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }
    */

    public function testNonLatinCharactersLocal()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test_Characters_Local';

        $parameters = array(
            'repository_name' => 'Folder Test Characters Local',
            'add_relations' => true,
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_CharactersLocal.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->_checkFolder();
    }

    public function testNonLatinCharactersHttp()
    {
        $uri = TEST_FILES_DIR
            . DIRECTORY_SEPARATOR . 'Folder_Test_Characters_Http';

        $parameters = array(
            'repository_name' => 'Folder Test Characters Http',
        );

        $this->_expectedXml = $this->_expectedBaseDir
            . DIRECTORY_SEPARATOR . 'FolderTest_CharactersHttp.xml';

        $this->_prepareFolderTest($uri, $parameters);
        $this->markTestSkipped(
            __('No test for non-latin characters via http.')
        );
        $this->_checkFolder();
    }

    /**
     * @depends testFullFolder
     */
    public function testFullFolderUpdate()
    {
        $this->testFullFolder();

        $this->markTestSkipped(
            __('To be done: replace "document.xml" by the updated one.')
        );
    }
}
