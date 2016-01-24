<?php if ($folder): ?>
<div class="folder-status">
    <span class="status <?php echo Inflector::variablize($folder->status); ?>">
        <?php
        switch ($folder->status) {
            case OaiPmhStaticRepository::STATUS_ADDED: $status = __('Folder added'); break;
            case OaiPmhStaticRepository::STATUS_RESET: $status = __('Status reset'); break;
            case OaiPmhStaticRepository::STATUS_QUEUED: $status = __('Process queued'); break;
            case OaiPmhStaticRepository::STATUS_PROGRESS: $status = __('Process in progress'); break;
            case OaiPmhStaticRepository::STATUS_PAUSED: $status = __('Process paused'); break;
            case OaiPmhStaticRepository::STATUS_STOPPED: $status = __('Process stopped'); break;
            case OaiPmhStaticRepository::STATUS_KILLED: $status = __('Process killed'); break;
            case OaiPmhStaticRepository::STATUS_COMPLETED: $status = __('OAI-PMH Static Repository ready'); break;
            case OaiPmhStaticRepository::STATUS_DELETED: $status = __('OAI-PMH Static Repository deleted'); break;
            case OaiPmhStaticRepository::STATUS_ERROR: $status = __('Process Error'); break;
            default: $status = __('Error'); break;
        }
        echo html_escape($status);
        ?>
    </span>
</div>
<?php endif; ?>
