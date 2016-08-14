if (!Omeka) {
    var Omeka = {};
}

Omeka.OaiPmhStaticRepositoryBrowse = {};

(function ($) {

    Omeka.OaiPmhStaticRepositoryBrowse.setupBatchEdit = function () {
        var oaiPmhStaticRepositoryCheckboxes = $("table#oai-pmh-static-repositories tbody input[type=checkbox]");
        var globalCheckbox = $('th.batch-edit-heading').html('<input type="checkbox">').find('input');
        var batchEditSubmit = $('.batch-edit-option input');
        /**
         * Disable the batch submit button first, will be enabled once records
         * checkboxes are checked.
         */
        batchEditSubmit.prop('disabled', true);

        /**
         * Check all the oaiPmhStaticRepositoryCheckboxes if the globalCheckbox is checked.
         */
        globalCheckbox.change(function() {
            oaiPmhStaticRepositoryCheckboxes.prop('checked', !!this.checked);
            checkBatchEditSubmitButton();
        });

        /**
         * Uncheck the global checkbox if any of the oaiPmhStaticRepositoryCheckboxes are
         * unchecked.
         */
        oaiPmhStaticRepositoryCheckboxes.change(function(){
            if (!this.checked) {
                globalCheckbox.prop('checked', false);
            }
            checkBatchEditSubmitButton();
        });

        /**
         * Check whether the batchEditSubmit button should be enabled.
         * If any of the oaiPmhStaticRepositoryCheckboxes is checked, the batchEditSubmit button
         * is enabled.
         */
        function checkBatchEditSubmitButton() {
            var checked = false;
            oaiPmhStaticRepositoryCheckboxes.each(function() {
                if (this.checked) {
                    checked = true;
                    return false;
                }
            });

            batchEditSubmit.prop('disabled', !checked);
        }
    };

    $(document).ready(function() {
        // Delete a simple record.
        $('.oai-pmh-static-repository input[name="submit-batch-delete"]').click(function(event) {
            event.preventDefault();
            if (!confirm(Omeka.messages.oaiPmhStaticRepository.confirmation)) {
                return;
            }
            $('table#oai-pmh-static-repositories thead tr th.batch-edit-heading input').attr('checked', false);
            $('.batch-edit-option input').prop('disabled', true);
            $('table#oai-pmh-static-repositories tbody input[type=checkbox]:checked').each(function(){
                var checkbox = $(this);
                var row = $(this).closest('tr.oai-pmh-static-repository');
                var current = $('#oai-pmh-static-repository-' + this.value);
                var ajaxUrl = current.attr('href') + '/oai-pmh-static-repository/ajax/delete';
                checkbox.addClass('transmit');
                $.post(ajaxUrl,
                    {
                        id: this.value
                    },
                    function(data) {
                        row.remove();
                    }
                );
            });
        });

        // Toggle details for the current row.
        $('.oai-pmh-static-repository-details').click(function (event) {
            event.preventDefault();
            $(this).closest('td').find('.last-message').slideToggle('fast');
            $(this).closest('td').find('.details').slideToggle('fast');
        });
    });

})(jQuery);
