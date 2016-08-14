<?php
/**
 * Map metadata files into Omeka elements for each item and file.
 *
 * If the record contains an xml file, it will be copied directly in the xml of
 * the folder for the record if its format is managed, like Mets. So there won't
 * be intermediate conversions before the harvest, so this is the recommended
 * way to import metadata if they aren't Dublin Core. So this class is mainly
 * used to create the required default oai_dc format, the only managed metadata.
 *
 * File paths or urls are not checked: it will be done by the harvester.
 *
 * Checks on Dublin Core elements can be made here, but another one is done
 * during formatting.
 *
 * @package OaiPmhStaticRepository
 */
abstract class OaiPmhStaticRepository_Mapping_Abstract
{
    // The xsi is required for each record according to oai-pmh protocol.
    const XSI_PREFIX = 'xsi';
    const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    protected $_uri;
    protected $_parameters;

    // Tools that will be used.
    protected $_managePaths;
    protected $_validateFile;
    protected $_processXslt;

    // The processed metadata for each file path, to avoid two processes.
    protected $_processedFiles = array();

    // The full path to current metadata file.
    protected $_metadataFilepath;

    // The list of tests to check if a file is a metadata file.
    protected $_checkMetadataFile = array('false');

    // The lower case extension, to check if the file is a metadata one.
    protected $_extension;

    /**
     * When the source contains an xml file, it can be copied directly in the
     * xml of the record for the specified format. It can be changed before to
     * remove or to add some elements.
     * These variables are only used by xml formats.
     */
    // The format to add to.
    protected $_formatXml;
    protected $_xmlRoot = '';
    protected $_xmlNamespace = '';
    protected $_xmlPrefix = '';
    protected $_xslMain = '';
    // The content of the file via SimpleXML.
    protected $_xml;
    // This tool is used only when the xsl generates an xml document.
    protected $_mappingDocument;

    // List of the Dublin Core terms. Can be enlarged to qualified ones.
    protected $_dcTerms = array(
        'title' => 'Title',
        'creator' => 'Creator',
        'subject' => 'Subject',
        'description' => 'Description',
        'publisher' => 'Publisher',
        'contributor' => 'Contributor',
        'date' => 'Date',
        'type' => 'Type',
        'format' => 'Format',
        'identifier' => 'Identifier',
        'source' => 'Source',
        'language' => 'Language',
        'relation' => 'Relation',
        'coverage' => 'Coverage',
        'rights' => 'Rights',
    );

    // Internal values.
    // These headers have special meanings in Omeka or in the fork of CsvImport.
    protected $_specialHeaders = array(
        // Name or index of the document, even for attached files with metadata.
        'name' => 'name',
        'item' => 'name',
        'document' => 'name',
        // Attached files.
        'file' => 'files',
        'files' => 'files',
        // 'fileurl' => 'files', // deprecated
        'record type' => 'record type',
        // 'recordtype' => 'record type', // deprecated

        // These ones are used only if a format manages them.
        'item type' => 'item type',
        // 'itemtype' => 'item type', // deprecated
        /*
        // Tags, collection, featured, public, are useless, because there are
        // automatically managed as extra metadata.
        'tags' => 'tags',
        'collection' => 'collection',
        'featured' => 'featured',
        'public => 'public',

        // These ones allow to use same files than the fork of Csv Import.
        'sourceitemid' => 'name', // deprecated
        'updatemode' => 'action', // deprecated
        'updateidentifier' => 'name', // deprecated
        'record identifier' => 'name', // deprecated
        'recordidentifier' => 'name', // deprecated
        // Identifier cannot be used, because it can be a Dublin Core element.
        // 'identifier' => 'name', // not managed
        'action' => 'action', // not managed
        'identifierfield' => 'identifier field', // not managed
        */
    );

    // Element separator is used for the name of the element for some formats.
    protected $_elementNameSeparator = ':';
    protected $_endOfLine = PHP_EOL;

    /**
     * Constructor of the class.
     *
     * @param string $uri The uri of the folder.
     * @param array $parameters The parameters to use for the mapping.
     * @return void
     */
    public function __construct($uri, $parameters)
    {
        $this->_uri = $uri;
        $this->_parameters = $parameters;

        $this->_managePaths = new OaiPmhStaticRepository_Tool_ManagePaths($uri, $parameters);
        $this->_validateFile = new OaiPmhStaticRepository_Tool_ValidateFile();
        $this->_processXslt = new OaiPmhStaticRepository_Tool_ProcessXslt();

        $this->_elementNameSeparator = $this->_getParameter('element_name_separator') ?: ':';

        if ($this->_getParameter('use_dcterms')) {
            // Prepare labels of dc terms.
            require PLUGIN_DIR
                . DIRECTORY_SEPARATOR . 'ArchiveDocument'
                . DIRECTORY_SEPARATOR . 'libraries'
                . DIRECTORY_SEPARATOR . 'elements_dcterms.php';
            $this->_dcTerms = array();
            foreach ($elements as $element) {
                // Checks are done on lower case names and labels.
                $this->_dcTerms[strtolower($element['name'])] = $element['label'];
                $this->_dcTerms[strtolower($element['label'])] = $element['label'];
            }
        }
    }

    /**
     * Get parameter by name.
     *
     * @return mixed Value, if any, else null.
     */
    protected function _getParameter($name)
    {
        return isset($this->_parameters[$name]) ? $this->_parameters[$name] : null;
    }

    /**
     * Check if the a file is a metadata file via extension and/or content.
     *
     * @param string $filepath The path to the metadata file.
     * @return boolean
     */
    public function isMetadataFile($filepath)
    {
        return $this->_validateFile->isMetadataFile(
            $filepath,
            $this->_checkMetadataFile,
            array(
                'extension' => $this->_extension,
                'xmlRoot' => $this->_xmlRoot,
                'xmlNamespace' => $this->_xmlNamespace,
                'xmlPrefix' => $this->_xmlPrefix,
            ));
    }

    /**
     * List items and attached files in the current metadata file.
     *
     * @param string $filepath The path to the metadata file.
     * @return array Stored documents.
     */
    public function listDocuments($filepath)
    {
        if (!isset($this->_processedFiles[$filepath])) {
            $this->_metadataFilepath = $filepath;
            $this->_managePaths->setMetadataFilepath($filepath);
            $this->_prepareDocuments();
            $this->_setXmlFormat();
            $this->_validateDocuments();
            $this->_removeDuplicateMetadata();
        }
        return $this->_processedFiles[$filepath];
    }

    /**
     * If the source is xml, return the format in order to append it directly.
     *
     * @return string
     */
    public function getFormatXml()
    {
        return $this->_formatXml;
    }

    /**
     * If the source is xml, return it, eventually modified, without the xml
     * declaration.
     *
     * @internal Internal uris should be the final ones (relative or absolute).
     * The namespaces and schema location should be set.
     * The namespace for "xsi" is automatically added here if needed.
     *
     * @return string|null
     */
    protected function _asXml()
    {
        if (empty($this->_formatXml)) {
            return;
        }

        // The xml should be built in a previous step.
        if (empty($this->_xml)) {
            return;
        }

        // Add the "xsi" namespace, standard in the oai-pmh protocol.
        $dom = dom_import_simplexml($this->_xml)->ownerDocument;
        if ($dom === false) {
            return;
        }
        $dom->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . self::XSI_PREFIX, self::XSI_NAMESPACE);
        $this->_xml = simplexml_import_dom($dom);

        // Return without the xml declaration.
        return $dom->saveXML($dom->documentElement);
    }

    /**
     * Prepare the list of documents set inside the current metadata file.
     */
    abstract protected function _prepareDocuments();

    /**
     * When an xsl creates a generic xml document, the process can be automatic.
     */
    protected function _prepareXmlDocuments()
    {
        $this->_mappingDocument = new OaiPmhStaticRepository_Mapping_Document($this->_uri, $this->_parameters);

        $this->_processedFiles[$this->_metadataFilepath] = array();
        $documents = &$this->_processedFiles[$this->_metadataFilepath];

        // If the xml is too large, the php memory may be increased so it can be
        // processed directly via SimpleXml.
        $this->_xml = simplexml_load_file($this->_metadataFilepath, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_PARSEHUGE);
        if ($this->_xml === false) {
            return;
        }

        // Only one document by mets file is managed (the main use of Mag).
        $doc = &$this->_doc;

        $this->_xml->registerXPathNamespace($this->_xmlPrefix, $this->_xmlNamespace);

        $extraParameters = $this->_getParameter('extra_parameters');

        // Process the xml file via the stylesheet.
        $xmlpath = $this->_processXslt($this->_metadataFilepath, $this->_xslMain, '', $extraParameters);
        if (filesize($xmlpath) == 0) {
            return;
        }

        // Now, the xml is a standard document, so process it with the class.
        $documents = $this->_mappingDocument->listDocuments($xmlpath);
    }

    /**
     * Convert one record (e.g. one row of a spreadsheet) into a document.
     *
     * @internal Currently, this is used only with the Archive Document.
     *
     * @param var $record The record to process.
     * @param boolean $withSubRecords Add sub records if any (files...).
     * @return array The document.
     */
    protected function _getDocument($record, $withSubRecords)
    {
    }

    /**
     * Validate documents, secure paths of files and make them absolute.
     *
     * @internal Only local filepaths are checked.
     */
    protected function _validateDocuments()
    {
        $documents = &$this->_processedFiles[$this->_metadataFilepath];

        // Check file paths and names (if one is absent, the other is used).
        $nameBase = $this->_managePaths->getRelativePathToFolder($this->_metadataFilepath);
        foreach ($documents as $key => &$document) {
            $document = $this->_normalizeDocument($document, 'Item');
            // Check if the document is empty.
            if (empty($document['metadata'])
                    && empty($document['files'])
                    && empty($document['extra'])
                ) {
                unset($documents[$key]);
                continue;
            }

            // Add an internal name if needed.
            // Warning: this should not be the same than the one defined inside
            // a metadata file, even if the issue is very rare. Nevertheless, it
            // should be enough stable to be updatable in main normal cases.
            if (empty($document['name'])) {
                $document['name'] = $nameBase . ':0' . ($key + 1);
            }

            // Remove a possible null value.
            if (empty($document['files'])) {
                $document['files'] = array();
                continue;
            }

            foreach ($document['files'] as $order => &$file) {
                // The path and the fullpath are set during normalization, but
                // not checked for security. They are the same.
                $file = $this->_normalizeDocument($file, 'File');

                // The path is not required if the file can be identified with
                // another metadata, for example for update or deletion.
                if (!strlen($file['path'])) {
                    // TODO Check other metadata (name...).
                    continue;
                }

                // Secure the absolute filepath.
                $absoluteFilepath = $this->_managePaths->getAbsolutePath($file['path']);
                if (empty($absoluteFilepath)) {
                    throw new OaiPmhStaticRepository_BuilderException(__('The file "%s" inside document "%s" is incorrect.',
                        $file['path'], $document['name']));
                }

                // No relative path if the file is external to the folder.
                $relativeFilepath = $this->_managePaths->isInsideFolder($absoluteFilepath)
                ? $this->_managePaths->getRelativePathToFolder($absoluteFilepath)
                : $absoluteFilepath;
                if (empty($relativeFilepath)) {
                    throw new OaiPmhStaticRepository_BuilderException(__('The file path "%s" is incorrect.',
                        $file['path']));
                }

                if (empty($file['name'])) {
                    $file['name'] = $relativeFilepath;
                }
            }
        }

        return $documents;
    }

    /**
     * Check and normalize a document (move extra data in process and specific).
     *
     * No default is added here, except the record type.
     *
     * @todo To be completed (see ArchiveFolder).
     *
     * @param array $document The document to normalize.
     * @param array $recordType Optional The record type if not set
     * @return array The normalized document.
     */
    protected function _normalizeDocument($document, $recordType = null)
    {
        if ($recordType) {
            $document['record type'] = $recordType;
        }
        if (!isset($document['metadata'])) {
            $document['metadata'] = array();
        }

        // Specific normalization according to the record type: separate Omeka
        // metadata and element texts, that are standard metadata.
        switch ($document['record type']) {
            case 'File':
                if (empty($document['path'])) {
                    $message = __('The path "%s" is empty.', $document['path']);
                    throw new OaiPmhStaticRepository_BuilderException($message);
                }
                $absoluteFilePath = $this->_managePaths->getAbsoluteUri($document['path']);
                if (empty($absoluteFilePath)) {
                    $message = __('The path "%s" is forbidden or incorrect.', $document['path']);
                    throw new OaiPmhStaticRepository_BuilderException($message);
                }
                $document['path'] = $absoluteFilePath;
                break;

            case 'Item':
                break;

            case 'Collection':
                break;
        }

        // Normalize the element texts.
        // Remove the Omeka 'html', that slows down process and that can be
        // determined automatically when it is really needed.
        /*
        foreach ($document['metadata'] as $elementSetName => &$elements) {
            foreach ($elements as $elementName => &$elementTexts) {
                foreach ($elementTexts as &$elementText) {
                    if (is_array($elementText)) {
                        $elementText = $elementText['text'];
                    }
                }
                // Trim the metadata too to avoid useless spaces.
                $elementTexts = array_map('trim', $elementTexts);
            }
        }
        */

        return $document;
    }

    /**
     * Get the data or element set and element name from a string.
     *
     * @param string $string The string to identify and clean.
     * @return string|array|null If recognized, the array with element set name
     * and the element name, else the cleaned string, else null.
     */
    protected function _getDataName($string)
    {
        $name = trim($string);

        // If no name, this is a comment.
        if (strlen($name) == 0) {
            return null;
        }

        // Prepare element.
        $elementSetName = '';
        $elementName = '';

        $name = trim(trim($name, $this->_elementNameSeparator . ' '));
        $posSepareElement = mb_strpos($name, $this->_elementNameSeparator);
        if (empty($posSepareElement)) {
            $lowerName = strtolower($name);

            // Manage special headers.
            if (isset($this->_specialHeaders[$lowerName])) {
                return $this->_specialHeaders[$lowerName];
            }

            if (isset($this->_dcTerms[$lowerName])) {
                $elementSetName = 'Dublin Core';
                $elementName = $this->_dcTerms[$lowerName];
            }
            // Empty element set name.
            else {
                $elementName = $name;
            }
        }
        // Full element.
        else {
            $elementSetName = trim(trim(mb_substr($name, 0, $posSepareElement), $this->_elementNameSeparator . ' '));
            $elementName = trim(trim(mb_substr($name, $posSepareElement), $this->_elementNameSeparator . ' '));
        }

        // Check the field name. If none, this is a comment.
        if (empty($elementName)) {
            return null;
        }

        // Save the element name.
        return array($elementSetName, $elementName);
    }

    /**
     * Remove duplicate metadata that can be found in all documents.
     */
    protected function _removeDuplicateMetadata()
    {
        $documents = &$this->_processedFiles[$this->_metadataFilepath];

        foreach ($documents as &$document) {
            $document = $this->_removeDuplicateMetadataForRecord($document);
            foreach ($document['files'] as &$file) {
                $file = $this->_removeDuplicateMetadataForRecord($file);
            }
        }
    }

    /**
     * Remove duplicate metadata of a single record.
     *
     * @param array $record A document or a file.
     * @return array
     */
    protected function _removeDuplicateMetadataForRecord($record)
    {
        foreach ($record as $key => &$value) {
            switch ($key) {
                case 'metadata':
                    foreach ($value as $elementSetName => &$elementName) {
                        $elementName = array_map('array_unique', $elementName);
                    }
                    break;

                case 'extra':
                    foreach ($value as &$data) {
                        if (is_array($data)) {
                            $data = array_unique($data);
                        }
                    }
                    break;
            }
        }
        return $record;
    }

    /**
     * Unzip a file to get the selected file content.
     *
     * @uses Extension php-zip or command line unzip.
     *
     * @param string $zipFile
     * @param string $filename The path to extract from the zip file.
     * @return string|null The content of the requested file. Null if error.
     */
    protected function _extractZippedContent($zipFile, $filename)
    {
        // First, save the file in the temp directory, because ZipArchive and
        // unzip don't manage url.
        $content = null;
        $input = tempnam(sys_get_temp_dir(), basename($zipFile));
        $result = file_put_contents($input, file_get_contents($zipFile));
        if (!empty($result)) {
            // Unzip via php-zip.
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive;
                if ($zip->open($input) === true) {
                    $index = $zip->locateName($filename);
                    if ($index !== false) {
                        $content = $zip->getFromIndex($index);
                    }
                    $zip->close();
                }
            }

            // Unzip via command line
            else {
                // Check if the zip command exists.
                $this->_executeCommand('unzip', $status, $output, $errors);
                // A return value of 0 indicates the convert binary is working correctly.
                if ($status == 0) {
                    $outputFile = tempnam(sys_get_temp_dir(), basename($zipFile));
                    $command = 'unzip -p ' . escapeshellarg($input) . ' content.xml > ' . escapeshellarg($outputFile);
                    $this->_executeCommand($command, $status, $output, $errors);
                    if ($status == 0 && filesize($outputFile)) {
                        $content = file_get_contents($outputFile);
                    }
                    unlink($outputFile);
                }
            }
        }
        unlink($input);
        return $content;
    }

    /**
     * Apply a process (xslt stylesheet) on an input (xml file) and save output.
     *
     * @param string $input Path of input file.
     * @param string $stylesheet Path of the stylesheet.
     * @param string $output Path of the output file. If none, a temp file will
     * be used.
     * @param array $parameters Parameters array.
     * @return string|null Path to the output file if ok, null else.
     */
    protected function _processXslt($input, $stylesheet, $output = '', $parameters = array())
    {
        return $this->_processXslt->processXslt($input, $stylesheet, $output, $parameters);
    }

    /**
     * Set the xml format of all documents, specially if a sub class is used.
     */
    protected function _setXmlFormat()
    {
        $documents = &$this->_processedFiles[$this->_metadataFilepath];

        foreach ($documents as &$document) {
            if (isset($document['xml'])) {
                $document['format_xml'] = $this->_formatXml;
            }
            else {
                unset($document['format_xml']);
            }
            if (isset($document['files'])) {
                foreach ($document['files'] as &$file) {
                    if (isset($file['xml'])) {
                        $file['format_xml'] = $this->_formatXml;
                    }
                    else {
                        unset($file['format_xml']);
                    }
                }
            }
        }
    }

    /**
     * Check if a string is an Xml one.
     *
     * @param string $string
     * @return boolean
     */
    protected function _isXml($string)
    {
        $string = trim($string);
        return !empty($string)
            && strpos($string, '<') !== false
            && strpos($string, '>') !== false
            // A main tag is added to allow inner ones.
            && (boolean) simplexml_load_string('<xml>' . $string . '</xml>', 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
    }

    /**
     * Convert a string into a list of key / values.
     *
     * @internal The input is already checked via Zend form validator.
     *
     * @param array|string $input
     * @return array
     */
    protected function _stringParametersToArray($input)
    {
        if (is_array($input)) {
            return $input;
        }

        $parameters = array();

        $parametersAdded = array_values(array_filter(array_map('trim', explode(PHP_EOL, $input))));
        foreach ($parametersAdded as $parameterAdded) {
            list($paramName, $paramValue) = explode('=', $parameterAdded);
            $parameters[trim($paramName)] = trim($paramValue);
        }

        return $parameters;
    }

    /**
     * Execute a shell command without exec().
     *
     * @see Omeka_File_Derivative_Strategy_ExternalImageMagick::executeCommand()
     *
     * @param string $cmd
     * @param integer $status
     * @param string $output
     * @param array $errors
     * @throws OaiPmhStaticRepository_BuilderException
     */
    protected function _executeCommand($cmd, &$status, &$output, &$errors)
    {
        // Using proc_open() instead of exec() solves a problem where exec('convert')
        // fails with a "Permission Denied" error because the current working
        // directory cannot be set properly via exec().  Note that exec() works
        // fine when executing in the web environment but fails in CLI.
        $descriptorSpec = array(
            0 => array("pipe", "r"), //STDIN
            1 => array("pipe", "w"), //STDOUT
            2 => array("pipe", "w"), //STDERR
        );
        if ($proc = proc_open($cmd, $descriptorSpec, $pipes, getcwd())) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($proc);
        } else {
            throw new OaiPmhStaticRepository_BuilderException(__('Failed to execute command: %s', $cmd));
        }
    }
}
