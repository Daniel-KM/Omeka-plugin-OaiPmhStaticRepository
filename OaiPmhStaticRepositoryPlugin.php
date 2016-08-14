<?php
/**
 * OAI-PMH Static Repository
 *
 * Automatically build a standard OAI-PMH archive from files and metadata of a
 * local or a distant folder.
 *
 * @copyright Copyright Daniel Berthereau, 2015
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 * @package OaiPmhStaticRepository
 */

/**
 * The OAI-PMH Static Repository plugin.
 */
class OaiPmhStaticRepositoryPlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array This plugin's hooks.
     */
    protected $_hooks = array(
        'initialize',
        'install',
        'upgrade',
        'uninstall',
        'uninstall_message',
        'config_form',
        'config',
        'define_routes',
        'define_acl',
    );

    /**
     * @var array This plugin's filters.
     */
    protected $_filters = array(
        'admin_navigation_main',
        'oai_pmh_static_repository_oai_identifiers',
        'oai_pmh_static_repository_mappings',
        'oai_pmh_static_repository_ingesters',
        'oai_pmh_static_repository_formats',
    );

    /**
     * @var array This plugin's options.
     */
    protected $_options = array(
        'oai_pmh_static_repository_force_update' => true,
        'oai_pmh_static_repository_static_dir' => 'repositories',
        'oai_pmh_static_repository_processor' => '',
        'oai_pmh_static_repository_short_dispatcher' => null,
        'oai_pmh_static_repository_memory_limit' => null,
        // With roles, in particular if Guest User is installed.
        'oai_pmh_static_repository_allow_roles' => 'a:1:{i:0;s:5:"super";}',
    );

    /**
     * Initialize the plugin.
     */
    public function hookInitialize()
    {
        add_translation_source(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'languages');

        // Get the backend settings from the security.ini file.
        // This simplifies tests too (use of local paths instead of urls).
        // TODO Probably a better location to set this.
        if (!Zend_Registry::isRegistered('oai_pmh_static_repository')) {
            $iniFile = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'security.ini';
            $settings = new Zend_Config_Ini($iniFile, 'oai-pmh-static-repository');
            Zend_Registry::set('oai_pmh_static_repository', $settings);
        }
    }

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        $db = $this->_db;

        $sql = "
        CREATE TABLE IF NOT EXISTS `{$db->OaiPmhStaticRepository}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `uri` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `identifier` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `parameters` text collate utf8_unicode_ci NOT NULL,
            `status` enum('added', 'reset', 'queued', 'progress', 'paused', 'stopped', 'killed', 'completed', 'deleted', 'error') NOT NULL,
            `messages` longtext COLLATE utf8_unicode_ci NOT NULL,
            `owner_id` int unsigned NOT NULL DEFAULT '0',
            `added` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00',
            `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `uri` (`uri`),
            UNIQUE `identifier` (`identifier`),
            INDEX `modified` (`modified`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ";
        $db->query($sql);

        $this->_installOptions();

        // Check if there is a folder for the static repositories files, else
        // create one and protect it.
        $staticDir = FILES_DIR . DIRECTORY_SEPARATOR . get_option('oai_pmh_static_repository_static_dir');
        if (!file_exists($staticDir)) {
            mkdir($staticDir, 0755, true);
            copy(FILES_DIR . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . 'index.html',
                $staticDir . DIRECTORY_SEPARATOR . 'index.html');
        }
    }

    /**
     * Upgrade the plugin.
     */
    public function hookUpgrade($args)
    {
        $oldVersion = $args['old_version'];
        $newVersion = $args['new_version'];
        $db = $this->_db;

        if (version_compare($oldVersion, '2.4.1', '<')) {
            $sql = "
                ALTER TABLE `{$db->OaiPmhStaticRepository}`
                ALTER `added` SET DEFAULT '2000-01-01 00:00:00'
            ";
            $db->query($sql);
        }
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $db = $this->_db;
        $sql = "DROP TABLE IF EXISTS `$db->OaiPmhStaticRepository`";
        $db->query($sql);

        $this->_uninstallOptions();
    }

    /**
     * Add a message to the confirm form for uninstallation of the plugin.
     */
    public function hookUninstallMessage()
    {
        echo __('The folder "%s" where the xml files of the static repositories are saved will not be removed automatically.', get_option('oai_pmh_static_repository_static_dir'));
    }

    /**
     * Shows plugin configuration page.
     */
    public function hookConfigForm($args)
    {
        $view = get_view();
        echo $view->partial(
            'plugins/oai-pmh-static-repository-config-form.php'
        );
    }

    /**
     * Handle a submitted config form.
     *
     * @param array Options set in the config form.
     */
    public function hookConfig($args)
    {
        $post = $args['post'];
        foreach ($this->_options as $optionKey => $optionValue) {
            if (in_array($optionKey, array(
                    'oai_pmh_static_repository_allow_roles',
                ))) {
                $post[$optionKey] = serialize($post[$optionKey]) ?: serialize(array());
            }
            if (isset($post[$optionKey])) {
                set_option($optionKey, $post[$optionKey]);
            }
        }
    }

    /**
     * Defines route for direct download count.
     */
    public function hookDefineRoutes($args)
    {
        $args['router']->addConfig(new Zend_Config_Ini(dirname(__FILE__) . '/routes.ini', 'routes'));
    }

    /**
     * Define the plugin's access control list.
     *
     * @param array $args This array contains a reference to the zend ACL.
     */
    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];
        $resource = 'OaiPmhStaticRepository_Index';

        // TODO This is currently needed for tests for an undetermined reason.
        if (!$acl->has($resource)) {
            $acl->addResource($resource);
        }
        // Hack to disable CRUD actions.
        $acl->deny(null, $resource, array('show', 'add', 'edit', 'delete'));
        $acl->deny(null, $resource);

        $roles = $acl->getRoles();

        // Check that all the roles exist, in case a plugin-added role has
        // been removed (e.g. GuestUser).
        $allowRoles = unserialize(get_option('oai_pmh_static_repository_allow_roles')) ?: array();
        $allowRoles = array_intersect($roles, $allowRoles);
        if ($allowRoles) {
            $acl->allow($allowRoles, $resource);
        }

        $denyRoles = array_diff($roles, $allowRoles);
        if ($denyRoles) {
            $acl->deny($denyRoles, $resource);
        }

        $resource = 'OaiPmhStaticRepository_Request';
        if (!$acl->has($resource)) {
            $acl->addResource($resource);
        }
        $acl->allow(null, $resource,
            array('index', 'file', 'folder'));
    }

    /**
     * Add the plugin link to the admin main navigation.
     *
     * @param array Navigation array.
     * @return array Filtered navigation array.
    */
    public function filterAdminNavigationMain($nav)
    {
        $link = array(
            'label' => __('OAI-PMH Static Repositories'),
            'uri' => url('oai-pmh-static-repository'),
            'resource' => 'OaiPmhStaticRepository_Index',
            'privilege' => 'index',
        );
        $nav[] = $link;

        return $nav;
    }

    /**
     * Add the oai identifiers for all records that are available.
     *
     * @param array $oaiIdentifiers Oai identifiers array.
     * @return array Filtered oai identifiers array.
    */
    public function filterOaiPmhStaticRepositoryOaiIdentifiers($oaiIdentifiers)
    {
        // Available default identifiers in the plugin.
        // TODO Use Ark.
        // The only one that allows updates currently, so it is set by default.
        $oaiIdentifiers['short_name'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_ShortName',
            'description' => __('Hashed path + name: repository_id:aUi3[:name]'),
        );
        // These are usable for update only if new documents are listed at end.
        $oaiIdentifiers['position_folder'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_PositionFolder',
            'description' => __('Alphabetic position of item and file: repository_id:17:14'),
        );
        $oaiIdentifiers['position'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_Position',
            'description' => __('Alphabetic position of record: repository_id:17'),
        );
        // Hashs are currently useless for updates: use the paths / names only.
        $oaiIdentifiers['hash_md5'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_HashMd5',
            'description' => __('MD5 hash of the record'),
        );
        $oaiIdentifiers['hash_sha1'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_HashSha1',
            'description' => __('SHA1 hash of the record'),
        );
        /*
        // TODO Uses paths as identifiers (to be finished).
        $oaiIdentifiers['path'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_Path',
            'description' => __('Path in the folder: "repository_id/my_subfolder/my_file"'),
        );
        $oaiIdentifiers['path_item'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_PathItem',
            'description' => __('Path in the folder, with item for files'),
        );
        $oaiIdentifiers['path_colon'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_PathColon',
            'description' => __('Path with colon ":": "repository_id:my_subfolder:my_file"'),
        );
        $oaiIdentifiers['path_colon_item'] = array(
            'class' => 'OaiPmhStaticRepository_Identifier_PathColonItem',
            'description' => __('Path with colon, with item for files'),
        );
        */

        return $oaiIdentifiers;
    }

    /**
     * Add the mappings to convert metadata files into Omeka elements.
     *
     * @param array $mappings Available mappings.
     * @return array Filtered mappings array.
    */
    public function filterOaiPmhStaticRepositoryMappings($mappings)
    {
        // Available mappings in the plugin at first place to keep order.
        $oaiPmhStaticRepositoryMappings = array();
        // Available default mappings in the plugin.
        $oaiPmhStaticRepositoryMappings['text'] = array(
            'class' => 'OaiPmhStaticRepository_Mapping_Text',
            'description' => __('Text (extension: ".metadata.txt")'),
        );
        $oaiPmhStaticRepositoryMappings['json'] = array(
            'class' => 'OaiPmhStaticRepository_Mapping_Json',
            'description' => __('Json (extension: ".json")'),
        );
        $oaiPmhStaticRepositoryMappings['odt'] = array(
            'class' => 'OaiPmhStaticRepository_Mapping_Odt',
            'description' => __('Open Document Text (extension: ".odt")'),
        );
        $oaiPmhStaticRepositoryMappings['ods'] = array(
            'class' => 'OaiPmhStaticRepository_Mapping_Ods',
            'description' => __('Open Document Spreadsheet (extension: ".ods")'),
        );
        $oaiPmhStaticRepositoryMappings['mets'] = array(
            'class' => 'OaiPmhStaticRepository_Mapping_Mets',
            'description' => __('METS xml (with a profile compliant with Dublin Core)'),
        );

        return array_merge($oaiPmhStaticRepositoryMappings, $mappings);
    }

    /**
     * Add the ingesters for associated files that are available.
     *
     * @internal The prefix is a value to allow multiple ways to format data.
     *
     * @param array $ingesters Ingesters array.
     * @return array Filtered Ingesters array.
    */
    public function filterOaiPmhStaticRepositoryIngesters($ingesters)
    {
        // Available ingesters in the plugin at first place to keep order.
        $oaiPmhStaticRepositoryIngesters = array();
        $oaiPmhStaticRepositoryIngesters['alto'] = array(
            'prefix' => 'alto',
            'class' => 'OaiPmhStaticRepository_Ingester_Alto',
            'description' => __('Alto xml files for OCR'),
        );

        return array_merge($oaiPmhStaticRepositoryIngesters, $ingesters);
    }

    /**
     * Add the metadata formats that are available.
     *
     * @internal The prefix is a value to allow multiple ways to format data.
     *
     * @param array $metadataFormats Metadata formats array.
     * @return array Filtered metadata formats array.
    */
    public function filterOaiPmhStaticRepositoryFormats($formats)
    {
        // Available formats in the plugin at first place to keep order.
        $oaiPmhStaticRepositoryFormats = array();
        $oaiPmhStaticRepositoryFormats['oai_dc'] = array(
            'prefix' => 'oai_dc',
            'class' => 'OaiPmhStaticRepository_Format_OaiDc',
            'description' => __('Dublin Core'),
        );
        $oaiPmhStaticRepositoryFormats['oai_dcterms'] = array(
            'prefix' => 'oai_dcterms',
            'class' => 'OaiPmhStaticRepository_Format_OaiDcterms',
            'description' => __('Dublin Core Terms'),
        );
        $oaiPmhStaticRepositoryFormats['oai_dcq'] = array(
            'prefix' => 'oai_dcq',
            'class' => 'OaiPmhStaticRepository_Format_OaiDcq',
            'description' => __('Qualified Dublin Core (deprecated)'),
        );
        $oaiPmhStaticRepositoryFormats['mets'] = array(
            'prefix' => 'mets',
            'class' => 'OaiPmhStaticRepository_Format_Mets',
            'description' => __('METS'),
        );

        return array_merge($oaiPmhStaticRepositoryFormats, $formats);
    }
}
