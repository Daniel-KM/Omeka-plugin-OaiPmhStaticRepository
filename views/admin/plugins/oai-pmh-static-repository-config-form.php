<fieldset id="fieldset-oai-pmh-static-repository"><legend></legend>
    <div class="field">
        <div class="two columns alpha">
            <?php echo $this->formLabel('oai_pmh_static_repository_force_update',
                __('Force update for current day')); ?>
        </div>
        <div class="inputs five columns omega">
            <p class="explanation">
                <?php
                echo __('The standard for static repositories requires a date stamp without time.');
                echo ' ' . __('Therefore,  successive updates of the static repository on the same day may not be ingested.');
                echo ' ' . __('If checked, this constraint is bypassed for the internal format "documents".');
                ?>
            </p>
            <?php echo $this->formCheckbox('oai_pmh_static_repository_force_update', true,
                array('checked' => (boolean) get_option('oai_pmh_static_repository_force_update'))); ?>
        </div>
    </div>
    <div class="field">
        <div class="two columns alpha">
            <?php echo $this->formLabel('oai_pmh_static_repository_processor',
                __('Command of the processor')); ?>
        </div>
        <div class="inputs five columns omega">
            <p class="explanation">
                <?php echo __('Command of the processor.'); ?>
                <?php echo __('Let empty to use the internal xslt processor of php.'); ?>
                <?php echo __('This is required by some formats that need to parse a xslt 2 stylesheet.'); ?>
                <?php echo __('See format of the command and examples in the readme.'); ?>
            </p>
            <?php echo get_view()->formText('oai_pmh_static_repository_processor', get_option('oai_pmh_static_repository_processor'), null); ?>
        </div>
    </div>
    <div class="field">
        <div class="two columns alpha">
            <?php echo $this->formLabel('oai_pmh_static_repository_short_dispatcher',
                __('Short Job Dispatcher')); ?>
        </div>
        <div class="inputs five columns omega">
            <p class="explanation">
                <?php
                echo __('Processing a folder and import files is usually a long job.');
                echo ' ' . __("Nevertheless, some configurations don't allow to process them.");
                echo ' ' . __("So, if checked, the short dispatcher will be used, but by default, servers limit them to about 30 seconds.");
                ?>
            </p>
            <?php echo $this->formCheckbox('oai_pmh_static_repository_short_dispatcher', true,
                array('checked' => (boolean) get_option('oai_pmh_static_repository_short_dispatcher'))); ?>
        </div>
    </div>
    <div class="field">
        <div class="two columns alpha">
            <?php echo $this->formLabel('oai_pmh_static_repository_memory_limit',
                __('Memory Limit')); ?>
        </div>
        <div class="inputs five columns omega">
            <p class="explanation">
                <?php echo __('The memory limit for the background processes.'); ?>
            </p>
            <?php echo $this->formText('oai_pmh_static_repository_memory_limit', get_option('oai_pmh_static_repository_memory_limit'), null); ?>
        </div>
    </div>
</fieldset>
<fieldset id="fieldset-oai-pmh-static-repository-rights"><legend><?php echo __('Rights and Roles'); ?></legend>
    <div class="field">
        <div class="two columns alpha">
            <?php echo $this->formLabel('oai_pmh_static_repository_allow_roles', __('Roles that can use OAI-PMH Static Repository')); ?>
        </div>
        <div class="inputs five columns omega">
            <div class="input-block">
                <ul style="list-style-type: none;">
                <?php
                    $currentRoles = unserialize(get_option('oai_pmh_static_repository_allow_roles')) ?: array();
                    $userRoles = get_user_roles();
                    foreach ($userRoles as $role => $label) {
                        echo '<li>';
                        echo $this->formCheckbox('oai_pmh_static_repository_allow_roles[]', $role,
                            array('checked' => in_array($role, $currentRoles) ? 'checked' : ''));
                        echo $label;
                        echo '</li>';
                    }
                ?>
                </ul>
            </div>
        </div>
    </div>
</fieldset>
