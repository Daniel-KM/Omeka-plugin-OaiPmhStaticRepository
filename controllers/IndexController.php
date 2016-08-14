<?php
/**
 * Controller for Achive Folder admin pages.
 *
 * @package OaiPmhStaticRepository
 */
class OaiPmhStaticRepository_IndexController extends Omeka_Controller_AbstractActionController
{
    /**
     * The number of records to browse per page.
     *
     * @var string
     */
    protected $_browseRecordsPerPage = 100;

    protected $_autoCsrfProtection = true;

    /**
     * Controller-wide initialization. Sets the underlying model to use.
     */
    public function init()
    {
        $this->_db = $this->_helper->db;
        $this->_db->setDefaultModelName('OaiPmhStaticRepository');
    }

    /**
     * Retrieve and render a set of records for the controller's model.
     *
     * @uses Omeka_Controller_Action_Helper_Db::getDefaultModelName()
     * @uses Omeka_Db_Table::findBy()
     */
    public function browseAction()
    {
        if (!$this->hasParam('sort_field')) {
            $this->setParam('sort_field', 'modified');
        }

        if (!$this->hasParam('sort_dir')) {
            $this->setParam('sort_dir', 'd');
        }

        parent::browseAction();
    }

    public function addAction()
    {
        $form = new OaiPmhStaticRepository_Form_Add();
        $form->setAction($this->_helper->url('add'));
        $this->view->form = $form;

        // From parent::addAction(), to allow to set parameters as array.
        $class = $this->_helper->db->getDefaultModelName();
        $varName = $this->view->singularize($class);

        if ($this->_autoCsrfProtection) {
            $csrf = new Omeka_Form_SessionCsrf;
            $this->view->csrf = $csrf;
        }

        $record = new $class();
        if ($this->getRequest()->isPost()) {
            if ($this->_autoCsrfProtection && !$csrf->isValid($_POST)) {
                $this->_helper->_flashMessenger(__('There was an error on the form. Please try again.'), 'error');
                $this->view->$varName = $record;
                return;
            }

            // Specific is here.
            if (!$form->isValid($this->getRequest()->getPost())) {
                $this->_helper->_flashMessenger(__('There was an error on the form. Please try again.'), 'error');
                $this->view->$varName = $record;
                return;
            }

            $record->setPostData($_POST);

            if ($record->save(false)) {
                $successMessage = $this->_getAddSuccessMessage($record);
                if ($successMessage != '') {
                    $this->_helper->flashMessenger($successMessage, 'success');
                }
                set_option('oai_pmh_static_repository_unreferenced_files', $record->getParameter('unreferenced_files'));
                $this->_redirectAfterAdd($record);
            } else {
                $this->_helper->flashMessenger($record->getErrors());
            }
        }
        $this->view->$varName = $record;
    }

    public function stopAction()
    {
        $folder = $this->_db->findById();
        if (empty($folder)) {
            $message = __('Folder #%d does not exist.', $this->_getParam('id'));
            $this->_helper->flashMessenger($message, 'error');
            return $this->_helper->redirector->goto('browse');
        }

        $folder->setStatus(OaiPmhStaticRepository::STATUS_STOPPED);
        $folder->save();

        $this->_helper->redirector->goto('browse');
    }

    public function resetStatusAction()
    {
        $folder = $this->_db->findById();
        if (empty($folder)) {
            $message = __('Folder #%d does not exist.', $this->_getParam('id'));
            $this->_helper->flashMessenger($message, 'error');
            return $this->_helper->redirector->goto('browse');
        }

        $folder->setStatus(OaiPmhStaticRepository::STATUS_RESET);
        $folder->save();

        $this->_helper->redirector->goto('browse');
    }

    /**
     * Check change in a folder.
     */
    public function checkAction()
    {
        $result = $this->_launchJob(OaiPmhStaticRepository_Builder::TYPE_CHECK);
        $this->_helper->redirector->goto('browse');
    }

    /**
     * Update a folder.
     *
     * Currently, a first process is the same than update (no token or check).
     */
    public function updateAction()
    {
        $result = $this->_launchJob(OaiPmhStaticRepository_Builder::TYPE_UPDATE);
        $this->_helper->redirector->goto('browse');
    }

    /**
     * Batch editing of records.
     *
     * @return void
     */
    public function batchEditAction()
    {
        $folderIds = $this->_getParam('folders');
        if (empty($folderIds)) {
            $this->_helper->flashMessenger(__('You must choose some folders to batch edit.'), 'error');
        }
        // Normal process.
        else {
            // Action can be Check (default) or Update.
            $action = $this->getParam('submit-batch-update')
                ? OaiPmhStaticRepository_Builder::TYPE_UPDATE
                : OaiPmhStaticRepository_Builder::TYPE_CHECK;

            foreach ($folderIds as $folderId) {
                $result = $this->_launchJob($action, $folderId);
            }
        }

        $this->_helper->redirector->goto('browse');
    }

    public function logsAction()
    {
        $folder = $this->_db->findById();

        $this->view->folder = $folder;
    }

    /**
     * Launch a process on a record.
     *
     * @param string $processType
     * @param integer $recordId
     * @return boolean Success or failure.
     */
    protected function _launchJob($processType = null, $recordId = 0)
    {
        $id = (integer) ($recordId ?: $this->_getParam('id'));

        $folder = $this->_db->find($id);
        if (empty($folder)) {
            $message = __('Folder # %d does not exist.', $id);
            $this->_helper->flashMessenger($message, 'error');
            return false;
        }

        if (in_array($folder->status, array(
                OaiPmhStaticRepository::STATUS_QUEUED,
                OaiPmhStaticRepository::STATUS_PROGRESS,
            ))) {
            return true;
        }

        $folder->setStatus(OaiPmhStaticRepository::STATUS_QUEUED);
        $folder->save();

        $options = array(
            'folderId' => $folder->id,
            'processType' => $processType,
        );

        $jobDispatcher = Zend_Registry::get('bootstrap')->getResource('jobs');
        $jobDispatcher->setQueueName(OaiPmhStaticRepository_UpdateJob::QUEUE_NAME);

        // Short dispatcher if user wants it.
        if (get_option('oai_pmh_static_repository_short_dispatcher')) {
            try {
                $jobDispatcher->send('OaiPmhStaticRepository_UpdateJob', $options);
            } catch (Exception $e) {
                $message = __('Error when processing folder.');
                $folder->setStatus(OaiPmhStaticRepository::STATUS_ERROR);
                $folder->addMessage($message, OaiPmhStaticRepository::MESSAGE_CODE_ERROR);
                _log('[OaiPmhStaticRepository] ' . __('Folder #%d [%s]: %s',
                    $folder->id, $folder->uri, $message), Zend_Log::ERR);
                $this->_helper->flashMessenger($message, 'error');
                return false;
            }

            $message = __('Folder "%s" has been updated.', $folder->uri);
            $this->_helper->flashMessenger($message, 'success');
            return true;
        }

        // Normal dispatcher for long processes.
        $jobDispatcher->sendLongRunning('OaiPmhStaticRepository_UpdateJob', $options);
        $message = __('Folder "%s" is being updated.', $folder->uri)
            . ' ' . __('This may take a while. Please check below for status.');
        $this->_helper->flashMessenger($message, 'success');
        return true;
    }

    public function createGatewayAction()
    {
        $folder = $this->_db->findById();
        if (empty($folder)) {
            $message = __('Folder #%d does not exist.', $this->_getParam('id'));
            $this->_helper->flashMessenger($message, 'error');
            return $this->forward('browse');
        }

        if (!plugin_is_active('OaiPmhGateway')) {
            $message = __('The folder cannot be harvested: the plugin OAI-PMH Gateway is not enabled.');
            $this->_helper->flashMessenger($message, 'error');
            return $this->forward('browse');
        }

        // Get or create the gateway.
        $gateway = $folder->getGateway();
        if (empty($gateway)) {
            $gateway = new OaiPmhGateway();
            $gateway->url = $folder->getStaticRepositoryUrl();
            $gateway->public = false;
            $gateway->save();
        }

        if ($gateway) {
            $message = __('The gateway for folder "%" has been created.', $gateway->url);
            $this->_helper->flashMessenger($message, 'success');
        }
        else {
            $message = __('The gateway for folder "%" cannot be created.', $folder->getStaticRepositoryUrl());
            $this->_helper->flashMessenger($message, 'error');
        }

        $this->forward('browse');
    }

    public function harvestAction()
    {
        $folder = $this->_db->findById();
        if (empty($folder)) {
            $message = __('Folder #%d does not exist.', $this->_getParam('id'));
            $this->_helper->flashMessenger($message, 'error');
            return $this->forward('browse');
        }

        if (!plugin_is_active('OaiPmhGateway')) {
            $message = __('The folder cannot be harvested: the plugin OAI-PMH Gateway is not enabled.');
            $this->_helper->flashMessenger($message, 'error');
            return $this->forward('browse');
        }

        if (!plugin_is_active('OaipmhHarvester')) {
            $message = __('The folder cannot be harvested: the plugin OAI-PMH Harvester is not enabled.');
            $this->_helper->flashMessenger($message, 'error');
            return $this->forward('browse');
        }

        $folder->setParameter('oaipmh_gateway', true);
        $folder->setParameter('oaipmh_harvest', true);
        $folder->save();

        // Get or create the gateway.
        $gateway = $folder->getGateway();
        if (empty($gateway)) {
            $gateway = new OaiPmhGateway();
            $gateway->url = $folder->getStaticRepositoryUrl();
            $gateway-> public = false;
            $gateway->save();
        }

        $options = array();
        $options['update_metadata'] = $folder->getParameter('oaipmh_harvest_update_metadata');
        $options['update_files'] = $folder->getParameter('oaipmh_harvest_update_files');
        $harvest = $gateway->harvest($folder->getParameter('oaipmh_harvest_prefix'), $options);

        // No harvest and no prefix, so go to select page.
        if ($harvest === false) {
            $message = __('A metadata prefix should be selected to harvest the repository "%s".', $gateway->url);
            $this->_helper->flashMessenger($message, 'success');
            $url = absolute_url(array(
                    'module' => 'oaipmh-harvester',
                    'controller' => 'index',
                    'action' => 'sets',
                ), null, array(
                    'base_url' => $gateway->url,
            ));
            $this->redirect($url);
        }
        elseif (is_null($harvest)) {
            $message = __('The repository "%s" cannot be harvested.', $gateway->url)
               . ' ' . __('Check the gateway.');
            $this->_helper->flashMessenger($message, 'error');
            $url = absolute_url(array(
                    'module' => 'oai-pmh-gateway',
                    'controller' => 'index',
                    'action' => 'browse',
                ), null, array(
                    'id' => $gateway->id,
            ));
            $this->redirect($url);
        }

        // Else the harvest is launched.
        $message = __('The harvest of the folder "%" is launched.', $gateway->url);
        $this->_helper->flashMessenger($message, 'success');
        $this->forward('browse');
    }

    protected function _getDeleteConfirmMessage($record)
    {
        return __('When a folder is removed, the static repository file and the imported collections, items and files are not deleted from Omeka.');
    }

    /**
     * Return the number of records to display per page.
     *
     * @return integer|null
     */
    protected function _getBrowseRecordsPerPage($pluralName = null)
    {
        return $this->_browseRecordsPerPage;
    }
}
