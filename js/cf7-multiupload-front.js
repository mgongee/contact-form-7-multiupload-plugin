/**
 * Variables:
 * dropzoneParameters - initialized by Wordpress wp_localize_script() function 
 */

jQuery(document).ready(function () {
    Dropzone.autoDiscover = false;

    var dropzoneForm = jQuery("form.dropzone");
    var formContainer = jQuery(".wpcf7");
    var dropzoneEl = jQuery(".cf7_dropzone");
    var dropzoneUploadedFiles = dropzoneForm.find('.uploaded_file_urls');
    var dropzoneUploadedFileIds = dropzoneForm.find('.uploaded_file_ids');

    /*
     console.log(dropzoneUploadedFiles);
     console.log(dropzoneEl);
     console.log(formContainer);
     */
    var sendDataObject = {
        'action': dropzoneParameters.action,
        'dropzone_id': dropzoneEl.attr('id'),
        'cf7_form_id': formContainer.attr('id'),
        'dropzone_upload_nonce': dropzoneParameters.nonce,
        'do_action': 'uploadFile'
    };

    //console.log("dropzoneEl.data('max_file-size')", dropzoneEl.data('max-file-size'), dropzoneEl.data('allowed-filetypes'), dropzoneEl.data('max-files'));

    dropzoneEl.dropzone({
        url: dropzoneParameters.upload_url,
        addRemoveLinks: true,
        maxFilesize: dropzoneEl.data('max-file-size'),
        params: sendDataObject,
        acceptedFiles: dropzoneEl.data('allowed-mimetypes'),
        maxFiles: dropzoneEl.data('max-files'),
        success: function (file, response) {
            var uploadedFileUrl = response.file_url;
            var uploadedFileId = response.file_id;
            if (uploadedFileUrl != 0) {
                var uploadedFileUrls = dropzoneUploadedFiles.val();
                var newUrlsValue = uploadedFileUrls ? uploadedFileUrls + ',' + uploadedFileUrl : uploadedFileUrl;
                dropzoneUploadedFiles.val(newUrlsValue);

                if (uploadedFileId != 0) {
                    var uploadedFileIds = dropzoneUploadedFileIds.val();
                    var newIdsValue = uploadedFileIds ? uploadedFileIds + ',' + uploadedFileId : uploadedFileId;
                    dropzoneUploadedFileIds.val(newIdsValue);
                }

                file.previewElement.classList.add("dz-success");
                /*
                console.log("Successfully uploaded :" + uploadedFileUrl + ', ' + uploadedFileId);
                console.log("value:" + uploadedFileUrls);
                console.log("new value:" + newUrlsValue);
                console.log("new value:" + newIdsValue);

                console.log(dropzoneUploadedFiles);
                */
            }
        },
	maxfilesexceeded: function() {
	    console.log('maxfilesexceeded');
	}
    });

});


