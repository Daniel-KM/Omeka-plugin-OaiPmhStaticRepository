<?php

/**
 * @package OaiPmhStaticRepository
 */
class OaiPmhStaticRepository extends Omeka_Record_AbstractRecord implements Zend_Acl_Resource_Interface
{
    /**
     * Error message codes, used for status messages.
     *
     * @see Zend_Log
     */
    const MESSAGE_CODE_ERROR = 3;
    const MESSAGE_CODE_NOTICE = 5;
    const MESSAGE_CODE_INFO = 6;
    const MESSAGE_CODE_DEBUG = 7;

    // Build.
    const STATUS_ADDED = 'added';
    const STATUS_QUEUED = 'queued';
    const STATUS_PROGRESS = 'progress';
    const STATUS_COMPLETED = 'completed';
    // Deletion of the xml document.
    const STATUS_DELETED = 'deleted';
    // Process.
    const STATUS_PAUSED = 'paused';
    const STATUS_STOPPED = 'stopped';
    const STATUS_KILLED = 'killed';
    const STATUS_RESET = 'reset';
    const STATUS_ERROR = 'error';

    /**
     * @var int The record ID.
     */
    public $id;

    /**
     * @example http://institution.org:8080/path/to/folder
     * @example http://example.org/path/to/folder
     * @example /home/user/path/to/folder
     */
    public $uri;

    /**
     * The identifier part of the repository, used for integrated repositories
     * only. It must be unique. It is used to cache files too, if needed.
     * @example institution_org8080pathtorepository_identifier
     * @example repository_identifier
     */
    public $identifier;

    /**
     * Contains all the parameters of the folder and the resulting urls.
     *
     * "Repository_url": the url to the xml file.
     * "Repository_base_url": the url to the xml file via the gateway.
     * "Repository_short_url": the url to the xml file, without the scheme.
     * - Omeka used as a gateway for a static repository:
     * @example institution.org%3A8080/path/to/repository_identifier.xml
     * - Integrated static repository inside Omeka:
     * @example example.org/web_root_path/repository/repository_identifier.xml
     */
    public $parameters;
    public $status;
    public $messages;
    public $owner_id;
    public $added;
    public $modified;

    // Temporary unjsoned parameters.
    private $_parameters;

    // Temporary total of collections, items and files.
    private $_totalCollections;
    private $_totalItems;
    private $_totalFiles;

    protected function _initializeMixins()
    {
        $this->_mixins[] = new Mixin_Owner($this);
        $this->_mixins[] = new Mixin_Timestamp($this, 'added', 'modified');
    }

    /**
     * Get the user object.
     *
     * @return User|null
     */
    public function getOwner()
    {
        if ($this->owner_id) {
            return $this->getTable('User')->find($this->owner_id);
        }
    }

    /**
     * Returns the parameters of the folder.
     *
     * @throws UnexpectedValueException
     * @return array The parameters of the folder.
     */
    public function getParameters()
    {
        if ($this->_parameters === null) {
            $parameters = json_decode($this->parameters, true);
            if (empty($parameters)) {
                $parameters = array();
            }
            elseif (!is_array($parameters)) {
                throw new UnexpectedValueException(__('Parameters must be an array. '
                    . 'Instead, the following was given: %s.', var_export($parameters, true)));
            }
            $this->_parameters = $parameters;
        }
        return $this->_parameters;
    }

    /**
     * Returns the specified parameter of the folder.
     *
     * @param string $name
     * @return string The specified parameter of the folder.
     */
    public function getParameter($name)
    {
        $parameters = $this->getParameters();
        return isset($parameters[$name]) ? $parameters[$name] : null;
    }

    /**
     * Indicate if the folder is cached locally.
     *
     * @todo A full cache check.
     *
     * @return boolean
     */
    public function isCached()
    {
        return $this->status != OaiPmhStaticRepository::STATUS_ADDED
            && !$this->getParameter('repository_remote');
    }

    /**
     * Indicate if the process of the folder has been stopped in the background.
     *
     * @return boolean
     */
    public function hasBeenStopped()
    {
        // Check the true status, that may have been updated in background.
        $currentStatus = $this->getTable('OaiPmhStaticRepository')->getCurrentStatus($this->id);
        return $currentStatus == OaiPmhStaticRepository::STATUS_STOPPED;
    }

    /**
     * Indicate if the folder is set to be harvested.
     *
     * @return boolean
     */
    public function isSetToBeHarvested()
    {
        return $this->getParameter('oaipmh_harvest');
    }

    /**
     * Returns the path to a file that is cached or not.
     *
     * @param string $filepath The public filepath from the url.
     * @return string|boolean|null File path, false if not exist in this folder,
     * null if not available.
     */
    public function getFilepath($filepath)
    {
        if ($this->isCached()) {
            return $this->getCacheFolder() . '/' . $filepath;
        }
    }

    public function getProperty($property)
    {
        switch($property) {
            case 'added_username':
                $user = $this->getOwner();
                return $user
                    ? $user->username
                    : __('Anonymous');
            default:
                return parent::getProperty($property);
        }
    }

    public function getGateway()
    {
        if (!plugin_is_active('OaiPmhGateway') || !$this->getParameter('oaipmh_gateway')) {
            return null;
        }

        $gateway = $this->_db->getTable('OaiPmhGateway')
            ->findByUrl($this->getStaticRepositoryUrl());

        return $gateway;
    }

    public function getHarvest()
    {
        if (!plugin_is_active('OaiPmhGateway') || !$this->getParameter('oaipmh_gateway')) {
            return null;
        }

        if (!plugin_is_active('OaipmhHarvester') || !$this->getParameter('oaipmh_harvest')) {
            return null;
        }

        $gateway = $this->getGateway();
        if (empty($gateway)) {
            return null;
        }

        $prefix = $this->getParameter('oaipmh_harvest_prefix');
        $harvest = $gateway->getHarvest($prefix);

        return $harvest;
    }

    /**
     * Sets the status of the folder.
     *
     * @param string The status of the folder.
     */
    public function setStatus($status)
    {
        $this->status = (string) $status;
    }

    /**
     * Sets parameters.
     *
     * @param array $parameters
     */
    public function setParameters($parameters)
    {
        // Check null.
        if (empty($parameters)) {
            $parameters = array();
        }
        elseif (!is_array($parameters)) {
            throw new InvalidArgumentException(__('Parameters must be an array.'));
        }
        $this->_parameters = $parameters;
    }

    /**
     * Set the specified parameter of the folder.
     *
     * @param string $name
     * @param var $value
     * @return string The specified parameter of the folder.
     */
    public function setParameter($name, $value)
    {
        // Initialize parameters if needed.
        $parameters = $this->getParameters();
        $this->_parameters[$name] = $value;
    }

    /**
     * Filter the form input according to some criteria.
     *
     * @todo Move part of these filter inside save().
     *
     * @param array $post
     * @return array Filtered post data.
     */
    protected function filterPostData($post)
    {
        // Remove superfluous whitespace.
        $options = array('inputNamespace' => 'Omeka_Filter');
        $filters = array(
            'uri' => array('StripTags', 'StringTrim'),
            // 'item_type_id'  => 'ForeignKey',
            'records_for_files' => 'Boolean',
        );
        $filter = new Zend_Filter_Input($filters, null, $post, $options);
        $post = $filter->getUnescaped();

        // Avoid some notices with missed values.
        $basePost = array(
            'uri' => '',
            'item_type_id' => 0,
        );
        $post = array_merge($basePost, $post);

        $post['uri'] = rtrim(trim($post['uri']), '/.');

        $transferStrategy = $this->_getTransferStrategy($post['uri']);

        // Unset immutable or specific properties from $_POST.
        $immutable = array('id', 'identifier', 'parameters', 'status', 'messages', 'owner_id', 'added', 'modified');
        foreach ($immutable as $value) {
            unset($post[$value]);
        }

        // This filter move all parameters inside 'parameters' of the folder.
        $parameters = $post;
        // Property level.
        unset($parameters['uri']);
        unset($parameters['item_type_id']);
        // Not properties.
        unset($parameters['csrf_token']);
        unset($parameters['submit']);

        // Default parameters if not set.
        $defaults = array(
            'unreferenced_files' => 'by_file',
            'exclude_extensions' => '',
            'allow_no_extension' => false,
            'element_delimiter' => '',
            'extra_parameters' => array(),
            'records_for_files' => false,
            'oai_identifier_format' => 'short_name',
            'item_type_name' => '',

            'repository_name' => '[' . $this->uri . ']',
            'admin_emails' => get_option('administrator_email'),
            'metadata_formats' => array_keys(apply_filters('oai_pmh_static_repository_formats', array())),
            'use_dcterms' => true,

            'repository_remote' => false,
            'repository_scheme' => '',
            'repository_domain' => '',
            'repository_port' => '',
            'repository_path' => '',
            'repository_identifier' => basename($this->uri),

            'oaipmh_gateway' => true,
            'oaipmh_harvest' => true,
            'oaipmh_harvest_prefix' => 'doc',
            'oaipmh_harvest_update_metadata' => 'element',
            'oaipmh_harvest_update_files' => 'full',
        );
        $parameters = array_merge($defaults, $parameters);

        // Manage some exceptions.
        $parameters['repository_identifier'] = $this->_keepAlphanumericOnly($parameters['repository_identifier']);

        // Create the repository url.
        $parameters['repository_remote'] = $parameters['repository_remote'] && $transferStrategy == 'Url';
        // Remote folder.
        if ($parameters['repository_remote']) {
            $parsedUrl = parse_url($post['uri']);
            $parameters['repository_scheme'] = $parsedUrl['scheme'];
            if (empty($parameters['repository_domain']) && isset($parsedUrl['host'])) {
                $parameters['repository_domain'] = $parsedUrl['host'];
            }
            if (empty($parameters['repository_port']) && isset($parsedUrl['port'])) {
                $parameters['repository_port'] = $parsedUrl['port'];
            }
            if (empty($parameters['repository_path']) && isset($parsedUrl['path'])) {
                $parameters['repository_path'] = $parsedUrl['path'];
            }
            else {
                $parameters['repository_path'] = $this->_keepAlphanumericOnlyForDir($parameters['repository_path']);
            }
            $parameters['repository_path'] = trim($parameters['repository_path'], '/');

            $parameters['repository_folder_human'] = $post['uri'] . '/';
        }
        // Local folder.
        else {
            $parsedUrl = parse_url(WEB_ROOT);
            $parameters['repository_scheme'] = $parsedUrl['scheme'];
            $parameters['repository_domain'] = $parsedUrl['host'];
            if (empty($parameters['repository_port']) && isset($parsedUrl['port'])) {
                $parameters['repository_port'] = $parsedUrl['port'];
            }
            // TODO Use the name set in routes.ini or use the repository_path?
            $parameters['repository_path'] = (isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') . '/' : '')
                . 'repository';

            // There is no function for absolute public url.
            set_theme_base_url('public');
            $parameters['repository_folder_human'] = absolute_url(
                array(
                    'repository' => $parameters['repository_identifier'],
                    'filepath' => '',
                ),
                'oaipmhstaticrepository_file', array(), false, false);
            revert_theme_base_url();
            $parameters['repository_folder_human'] = rtrim($parameters['repository_folder_human'], '/.') . '/';
        }

        $parameters['repository_folder'] = $this->_urlEncodePath($parameters['repository_folder_human']);

        $parameters['repository_url_human'] = $this->_setStaticRepositoryUrlFromParameters(
            $parameters['repository_scheme'],
            $parameters['repository_domain'],
            $parameters['repository_port'],
            $parameters['repository_path'],
            $parameters['repository_identifier']);

        $parameters['repository_url'] = $this->_urlEncodePath($parameters['repository_url_human']);
        $parameters['repository_base_url'] = $this->_urlEncodePath(
            $this->_setStaticRepositoryBaseUrl($parameters['repository_url_human']));

        // Add the required format.
        if (!in_array('oai_dc', $parameters['metadata_formats'])) {
            array_unshift($parameters['metadata_formats'], 'oai_dc');
        }

        $parameters['item_type_name'] = $this->_getItemTypeName($post['item_type_id']);

        $parameters['extra_parameters'] = $this->_getExtraParameters($parameters['extra_parameters']);

        if (empty($parameters['unreferenced_files'])) {
            $parameters['unreferenced_files'] = $defaults['unreferenced_files'];
        }

        $parameters['oaipmh_harvest'] = (boolean) $parameters['oaipmh_harvest'];
        $parameters['oaipmh_gateway'] = $parameters['oaipmh_gateway'] || $parameters['oaipmh_harvest'];

        // Other parameters are not changed, so save them.
        $this->setParameters($parameters);

        $post['identifier'] = $parameters['repository_remote']
            ? $this->_keepAlphanumericOnly($parameters['repository_url_human'])
            : $parameters['repository_identifier'];

        return $post;
    }

    /**
     * Set the repository of this folder, that will be used to set the base url.
     *
     * The user can choose repository url, that can be local or remote.
     *
     * @param string $scheme
     * @param string $domain
     * @param string $port
     * @param string $path
     * @param string $identifier
     * @return string Static repository url (with or without scheme and encoded
     * port).
     */
    private function _setStaticRepositoryUrlFromParameters($scheme, $domain, $port, $path, $identifier)
    {
        return $scheme . '://'
            . $domain
            . ($port ? ':' . $port : '')
            . ($path ? '/' . $path : '')
            . '/' . $identifier
            . '.xml';
    }

    /**
     * Url-encode each part of a full url (RFC 3986).
     *
     * @see http://www.faqs.org/rfcs/rfc3986.html
     *
     * @example From https://example.org:8080/gateway/institution:6789/my path/to/my file!.xml
     * @example To https://example.org:8080/gateway/institution%3A6789/my%20path/to/my%20file%21.xml
     *
     * @param string $fullUrl The simple url to encode, without user, password,
     * query and fragment (only scheme, hostname, port and path).
     * @return string The url encoded url.
     */
    protected function _urlEncodePath($fullUrl)
    {
        $parsedUrl = parse_url($fullUrl);
        if (!isset($parsedUrl['path'])) {
            return $fullUrl;
        }

        $paths = explode('/', $parsedUrl['path']);
        $paths = array_map('rawurlencode', $paths);

        return $parsedUrl['scheme'] . '://'
            . $parsedUrl['host']
            . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '')
            . implode('/', $paths);
    }

    /**
     * Set the base url of the static repository (gateway + static repository
     * url without scheme, but with encoded delimiter of port if any).
     *
     * @example http://example.org/gateway/institution.org%3A8080/path/to/repository_identifier.xml
     * @example http://example.org/gateway/example.org/repository/repository_identifier.xml
     *
     * @internal Should be run after _setStaticRepositoryUrlFromParameters()
     *
     * @param string $repositoryUrl The url of the repository.
     * @return string The base url.
     */
    private function _setStaticRepositoryBaseUrl($url)
    {
        if (plugin_is_active('OaiPmhGateway')) {
            // There is no function for absolute public url.
            set_theme_base_url('public');
            $baseUrl = absolute_url(
                array(
                    // Remove the scheme of the url.
                    'repository' => substr(strstr($url,'://'), 3),
                ),
                'oaipmhgateway_query', array(), false, false);
            revert_theme_base_url();
        }
        // This static repository will not be really available.
        else {
            $baseUrl = WEB_FILES . '/' . get_option('oai_pmh_static_repository_static_dir') . '/' . basename($url);
        }

        return $baseUrl;
    }

    /**
     * Get the transfer strategy of files, according to uri.
     */
    protected function _getTransferStrategy($uri = null)
    {
        if (empty($uri)) {
            $uri = $this->uri;
        }

        $scheme = parse_url($uri, PHP_URL_SCHEME);
        if (in_array($scheme, array('http', 'https'))) {
            $transferStrategy = 'Url';
        }
        // Ftp files should be imported locally to conform to OAI standard, that
        // only accept http or https requests.
        elseif (in_array($scheme, array('ftp', 'sftp'))) {
            $transferStrategy = 'Ftp';
        }
        elseif ($scheme == 'file' || (!empty($uri) && $uri[0] == '/')) {
            $transferStrategy = 'Filesystem';
        }
        else {
            $transferStrategy = '';
        }

        return $transferStrategy;
    }

    /**
     * Get url of the static repository (scheme + repository + ".xml").
     *
     * @example http://institution.org:8080/path/to/repository_identifier.xml
     * @example http://example.org/repository/repository_identifier.xml
     *
     * @return string
     */
    public function getStaticRepositoryUrl()
    {
        return $this->getParameter('repository_url');
    }

    /**
     * Get the base url of the static repository (gateway + static repository
     * url without scheme).
     *
     * @example http://example.org/gateway/institution.org%3A8080/path/to/repository_identifier.xml
     * @example http://example.org/gateway/example.org/repository/repository_identifier.xml
     *
     * @return string
     */
    public function getStaticRepositoryBaseUrl()
    {
        return $this->getParameter('repository_base_url');
    }

    /**
     * Get the url to the folder of files (original uri for remote folder, files
     * one for local). This will be used to build quickly the path for local
     * files.
     *
     * @example http://institution.org:8080/path/to/folder/
     * @example http://example.org/repository/repository_identifier/
     *
     * @return string
     */
    public function getStaticRepositoryUrlFolder()
    {
        return $this->getParameter('repository_folder');
    }

    /**
     * Convert a string into a list of extra parameters.
     *
     * @internal The parameters are already checked via Zend form validator.
     *
     * @param array|string $extraParameters
     * @return array
     */
    protected function _getExtraParameters($extraParameters)
    {
        if (is_array($extraParameters)) {
            return $extraParameters;
        }

        $parameters = array();

        $parametersAdded = array_values(array_filter(array_map('trim', explode(PHP_EOL, $extraParameters))));
        foreach ($parametersAdded as $parameterAdded) {
            list($paramName, $paramValue) = explode('=', $parameterAdded);
            $parameters[trim($paramName)] = trim($paramValue);
        }

        return $parameters;
    }

    /**
     * Get the local filepath of the xml file of the static repository.
     *
     * @return string
     */
    public function getLocalRepositoryFilepath()
    {
        return FILES_DIR
            . DIRECTORY_SEPARATOR . get_option('oai_pmh_static_repository_static_dir')
            . DIRECTORY_SEPARATOR . $this->identifier . '.xml';
    }

    /**
     * Return the full path to the xml local static repository folder.
     *
     * @return string.
     */
    public function getCacheFolder()
    {
        return FILES_DIR
            . DIRECTORY_SEPARATOR . get_option('oai_pmh_static_repository_static_dir')
            . DIRECTORY_SEPARATOR . $this->identifier;
    }

    /**
     * Allow to set the item type name from filename (default) or to force it.
     *
     * @param string $itemTypeId
     * @return string
     */
    protected function _getItemTypeName($itemTypeId)
    {
        if (empty($itemTypeId)) {
            $itemTypeName = '';
        }
        elseif ($itemTypeId == 'default') {
            $itemTypeName = $itemTypeId;
        }
        // Check if item type exists.
        elseif (is_numeric($itemTypeId)) {
            $itemType = get_record_by_id('ItemType', $itemTypeId);
            if ($itemType) {
                $itemTypeName = $itemType->name;
            }
        }
        return $itemTypeName;
    }

    /**
     * Executes before the record is saved.
     *
     * @param array $args
     */
    protected function beforeSave($args)
    {
        $values = $this->getParameters();
        $this->parameters = version_compare(phpversion(), '5.4.0', '<')
            ? json_encode($values)
            : json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_null($this->messages)) {
            $this->messages = '';
        }

        if (is_null($this->owner_id)) {
            $this->owner_id = 0;
        }
    }

    /**
     * @todo Put some of these checks in the form.
     */
    protected function _validate()
    {
        $uri = rtrim(trim($this->uri), '/.');
        if (empty($uri)) {
            $this->addError('uri', __('An url or a path is required to add a folder.'));
        }
        else {
            $scheme = parse_url($uri, PHP_URL_SCHEME);
            if (!(in_array($scheme, array('http', 'https', /*'ftp', 'sftp',*/ 'file')) || $uri[0] == '/')) {
                $this->addError('uri', __('The url or path should be written in a standard way.'));
            }
        }

        if (empty($this->identifier)) {
            $this->addError('repository_identifier', __('There is no repository identifier.'));
        }

        if (empty($this->id)) {
            $folder = $this->getTable('OaiPmhStaticRepository')->findByUri($this->uri);
            if (!empty($folder)) {
               $this->addError('uri', __('The folder for uri "%s" exists already.', $this->uri));
            }

            $folder = $this->getTable('OaiPmhStaticRepository')->findByIdentifier($this->identifier);
            if (!empty($folder)) {
               $this->addError('repository_identifier', __('The repository identifier "%s" exists already.', $this->identifier));
            }

            if (trim($this->status) == '') {
                $this->status = OaiPmhStaticRepository::STATUS_ADDED;
            }
        }

        if (!in_array($this->status, array(
                OaiPmhStaticRepository::STATUS_ADDED,
                OaiPmhStaticRepository::STATUS_RESET,
                OaiPmhStaticRepository::STATUS_QUEUED,
                OaiPmhStaticRepository::STATUS_PROGRESS,
                OaiPmhStaticRepository::STATUS_PAUSED,
                OaiPmhStaticRepository::STATUS_STOPPED,
                OaiPmhStaticRepository::STATUS_KILLED,
                OaiPmhStaticRepository::STATUS_COMPLETED,
                OaiPmhStaticRepository::STATUS_DELETED,
                OaiPmhStaticRepository::STATUS_ERROR,
            ))) {
            $this->addError('status', __('The status "%s" does not exist.', $this->status));
        }
    }

    public function process($type = OaiPmhStaticRepository_Builder::TYPE_CHECK)
    {
        $message = __('Process "%s" started.', $type);
        $this->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_DEBUG);
        _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message), Zend_Log::DEBUG);

        $this->setStatus(OaiPmhStaticRepository::STATUS_PROGRESS);
        $this->save();

        // Create collection if it is set to be the name of the repository. It
        // can't be removed automatically.
        $this->_createCollectionIfNeeded();

        $builder = new OaiPmhStaticRepository_Builder();
        try {
            $documents = $builder->process($this, $type);
        } catch (OaiPmhStaticRepository_BuilderException $e) {
            $this->setStatus(OaiPmhStaticRepository::STATUS_ERROR);
            $message = __('Error during process: %s', $e->getMessage());
            $this->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_ERROR);
            _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message), Zend_Log::ERR);
            return;
        } catch (Exception $e) {
            $this->setStatus(OaiPmhStaticRepository::STATUS_ERROR);
            $message = __('Unknown error during process: %s', $e->getMessage());
            $this->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_ERROR);
            _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message), Zend_Log::ERR);
            return;
        }

        if ($this->hasBeenStopped()) {
            $message = __('Process has been stopped by user.');
            $this->addMessage($message);
            _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message));
            return;
        }

        $message = $this->_checkDocuments($documents);
        $this->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_NOTICE);
        _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message));

        switch ($type) {
            case OaiPmhStaticRepository_Builder::TYPE_CHECK:
                _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: Check finished.', $this->id, $this->uri));
                $this->setStatus(OaiPmhStaticRepository::STATUS_COMPLETED);
                $this->save();
                break;
            case OaiPmhStaticRepository_Builder::TYPE_UPDATE:
                $this->setStatus(OaiPmhStaticRepository::STATUS_COMPLETED);
                $message = __('Update finished.');
                $this->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_NOTICE);
                _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message));

                $this->_postProcess();
                break;
        }
    }

    /**
     * The post process is the import process (harvesting) if set.
     *
     * @internal This process should be managed as a job.
     *
     * @see OaipmhHarvester_IndexController::harvestAction()
     *
     * @param OaiPmhStaticRepository $folder
     */
    private function _postProcess()
    {
        if (plugin_is_active('OaiPmhGateway') && $this->getParameter('oaipmh_gateway')) {
            $gateway = $this->getGateway();
            if (empty($gateway)) {
                $gateway = new OaiPmhGateway();
                $gateway->url = $this->getStaticRepositoryUrl();
                $gateway->public = false;
                $gateway->save();
            }

            if (plugin_is_active('OaipmhHarvester') && $this->getParameter('oaipmh_harvest')) {
                $prefix = $this->getParameter('oaipmh_harvest_prefix');
                $updateMetadata = $this->getParameter('oaipmh_harvest_update_metadata');
                $updateFiles = $this->getParameter('oaipmh_harvest_update_files');
                $harvest = $gateway->getHarvest($prefix);
                if (empty($harvest)) {
                    $harvest = new OaipmhHarvester_Harvest;
                    $harvest->base_url = $gateway->getBaseUrl();
                    $harvest->metadata_prefix = $prefix;
                }

                // The options are always updated.
                $harvest->update_metadata = $updateMetadata ?: OaipmhHarvester_Harvest::UPDATE_METADATA_ELEMENT;
                $harvest->update_files = $updateFiles ?: OaipmhHarvester_Harvest::UPDATE_FILES_FULL;

                $message = __('Harvester launched.');
                $this->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_NOTICE);
                _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message));

                // Insert the harvest.
                $harvest->status = OaipmhHarvester_Harvest::STATUS_QUEUED;
                $harvest->initiated = date('Y:m:d H:i:s');
                $harvest->save();

                $jobDispatcher = Zend_Registry::get('bootstrap')->getResource('jobs');
                $jobDispatcher->setQueueName('imports');

                try {
                    $jobDispatcher->sendLongRunning('OaipmhHarvester_Job', array('harvestId' => $harvest->id));
                } catch (Exception $e) {
                    $message = __('Harvester crashed: %s', get_class($e) . ': ' . $e->getMessage());
                    $this->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_NOTICE);
                    _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s', $this->id, $this->uri, $message), Zend_Log::ERR);
                    $harvest->status = OaipmhHarvester_Harvest::STATUS_ERROR;
                    $harvest->save();
                    $this->addMessage(
                        get_class($e) . ': ' . $e->getMessage(),
                        OaipmhHarvester_Harvest_Abstract::MESSAGE_CODE_ERROR
                    );
                }
            }

            // TODO For remote archive folders, upload the xml file to them.
        }
    }

    /**
     * Check the list of documents.
     *
     * @param array $documents Documents to checks.
     * @return string Message.
     */
    protected function _checkDocuments($documents)
    {
        if (empty($documents)) {
            $message = __('No document or resource unavailable.');
        }
        // Computes total.
        else {
            $this->countRecordsOfDocuments($documents);
            $message = __('Result: %d collections, %d items and %d files.',
                $this->_totalCollections, $this->_totalItems, $this->_totalFiles);
        }
        return $message;
    }

    /**
     * Count the total of documents (all or by type).
     *
     * @param null|array $documents If null, computes for the existing
     * documents.
     * @param string $recordType "Collection", "Item" or "File", else all types.
     * @return integer The total of the selected record type.
     */
    public function countRecordsOfDocuments($documents = null, $recordType = null)
    {
        // Docs shouldn't be a null.
        if (empty($documents)) {
            $documents = array();
        }

        $type =  ucfirst(strtolower($recordType));
        $totalDocuments = 0;
        switch ($type) {
            case 'Collection':
                foreach ($documents as $document) {
                    if (isset($document['record type']) && $document['record type'] == 'Collection') {
                        ++$totalDocuments;
                    }
                }
                $this->_totalCollections = $totalDocuments;
                break;
            case 'Item':
                foreach ($documents as $document) {
                    if (!isset($document['record type']) || $document['record type'] == 'Item') {
                        ++$totalDocuments;
                    }
                }
                $this->_totalItems = $totalDocuments;
                break;
            // In Omeka, files aren't full records because they depend of items.
            case 'File':
                foreach ($documents as $document) {
                    if (isset($document['files'])) {
                        $totalDocuments += count($document['files']);
                    }
                }
                $this->_totalFiles = $totalDocuments;
                break;
            default:
                $totalCollections = $this->countRecordsOfDocuments($documents, 'Collection');
                $totalItems = $this->countRecordsOfDocuments($documents, 'Item');
                $totalFiles = $this->countRecordsOfDocuments($documents, 'File');
                $totalDocuments = $totalCollections + $totalItems + $totalFiles;
                break;
        }

        return $totalDocuments;
    }

    /**
     * Create collection if it is set to be the name of the repository and if it
     * is not already created.
     *
     * @return integer|null The id of the collection, or null if no collection
     */
    protected function _createCollectionIfNeeded()
    {
        $collectionId = $this->getParameter('collection_id');
        if (empty($collectionId)) {
            return null;
        }

        // Check if collection exists.
        if (is_numeric($collectionId)) {
            $collection = get_record_by_id('Collection', $collectionId);
            if ($collection) {
                return (integer) $collectionId;
            }
        }

        // Collection doesn't exist, so set it with the name.
        if ($collectionId != 'default') {
            return null;
        }

        // Prepare collection.
        $metadata = array(
            'public' => $this->getParameter('records_are_public'),
            'featured' => $this->getParameter('records_are_featured'),
        );

        // The title of the collection is the url of the repository.
        $repositoryName = $this->getParameter('repository_name');

        $elementTexts = array();
        $elementTexts['Dublin Core']['Title'][] =
            array('text' => $repositoryName, 'html' => false);
        $elementTexts['Dublin Core']['Identifier'][] =
            array('text' => $this->uri, 'html' => false);

        $collection = insert_collection($metadata, $elementTexts);

        // Save the collection as parameter of the folder.
        $this->setParameter('collection_id', $collection->id);

        return $collection->id;
    }

    public function addMessage($message, $messageCode = null, $delimiter = PHP_EOL)
    {
        if (strlen($this->messages) == 0) {
            $delimiter = '';
        }
        $date = date('Y-m-d H:i:s');
        $messageCodeText = $this->_getMessageCodeText($messageCode);
        $this->messages .= $delimiter . "[$date] $messageCodeText: $message";
        $this->save();
    }

    /**
     * Return a message code text corresponding to its constant.
     *
     * @param int $messageCode
     * @return string
     */
    private function _getMessageCodeText($messageCode)
    {
        switch ($messageCode) {
            case OaiPmhStaticRepository::MESSAGE_CODE_ERROR:
                return __('Error');
            case OaiPmhStaticRepository::MESSAGE_CODE_NOTICE:
            default:
                return __('Notice');
        }
    }

    /**
     * Declare the representative model as relating to the record ACL resource.
     *
     * Required by Zend_Acl_Resource_Interface.
     *
     * @return string
     */
    public function getResourceId()
    {
        return 'OaiPmhStaticRepositories';
    }

    /**
     * Clean a string to keep only standard alphanumeric characters and "-_.".
     *
     * @todo Make fully compliant with specs.
     *
     * @see http://www.openarchives.org/OAI/2.0/guidelines-oai-identifier.htm
     *
     * @param string $string Dirty string.
     * @return string Clean string.
     */
    private function _keepAlphanumericOnly($string)
    {
        return preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $string);
    }

    /**
     * Clean a string to keep only standard alphanumeric characters and "-_./".
     * Spaces are replaced with '_'.
     *
     * @todo Make fully compliant with specs.
     *
     * @see http://www.openarchives.org/OAI/2.0/guidelines-oai-identifier.htm
     *
     * @param string $string Dirty string.
     * @return string Clean string.
     */
    private function _keepAlphanumericOnlyForDir($string)
    {
        return preg_replace('/[^a-zA-Z0-9\-_\.\/]/', '', str_replace(' ', '_', $string));
    }
}
