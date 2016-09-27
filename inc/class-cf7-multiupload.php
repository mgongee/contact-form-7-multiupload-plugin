<?php

/**
 * Basic class that governs [multiupload] field
 */
class Cf7_Multiupload extends Cf7_Extension {

	public $contact_form;
	public $multiupload_tags;
	public $multiupload_settings = array();
	
	const STATUS_OK = 'ok';
	const STATUS_ERROR = 'error';
	const STATUS_FAIL = 'fail';
		
	public function __construct($plugin_root) {

		$this->plugin_root = $plugin_root;
		
		parent::__construct();

		/* Register [multiupload] shortcode within CF7 */
		add_action('wpcf7_init', array($this, 'add_shortcode'));

		/* Register [multiupload] showtcode tag generator within CF7 */
		add_action('admin_init', array($this, 'add_tag_generator'), 30);

		/* Register style sheet and scripts for the admin area. */
		add_action('admin_enqueue_scripts', array($this, 'register_admin_styles_and_scripts'));

		/* Register style sheet and scripts for the front. */
		add_action('wp_enqueue_scripts', array($this, 'register_front_styles_and_scripts'));

		/* Encode type filter */
		add_filter('wpcf7_form_enctype', array($this, 'form_enctype_filter'));

		/* Form class filter */
		add_filter('wpcf7_form_class_attr', array($this, 'form_class_filter'));
		add_filter('wpcf7_form_id_attr', array($this, 'form_id_filter'));

		/* Handle uploaded files */
		add_action('admin_post_cf7_dropzone_handle_dropped_media', array($this, 'handle_dropped_media'));
		add_action('admin_post_nopriv_cf7_dropzone_handle_dropped_media', array($this, 'handle_dropped_media'));

		/* Validation + upload handling filter */
		add_filter('wpcf7_validate_' . CFMU_FIELD_NAME, array($this, 'validation_filter'), 10, 2);
		add_filter('wpcf7_validate_' . CFMU_FIELD_NAME . '*', array($this, 'validation_filter'), 10, 2);
		
		/* Allow extra file types to be uploaded */
		//add_filter('upload_mimes', array( $this, 'add_mime_types' ), 10, 1);

	}

	public function get_dropzone_parameters() {
		$dropzone_parameters = array(
			'action' => 'cf7_dropzone_handle_dropped_media',
			'upload_url' => admin_url('admin-post.php?action=cf7_dropzone_handle_dropped_media'),
			'delete_url' => admin_url('admin-post.php?action=cf7_dropzone_handle_deleted_media'),
			'accepted_files' => 'image/*,application/pdf',
			'max_files' => 15,
			'max_filesize' => 12800000
		);

		return $dropzone_parameters;
	}
	
	function add_mime_types($mime_types) {

		self::log('add_mime_types');
		if (isset($_REQUEST['cf7_form_id'])) {
			$cf7_form_id = (int) $_REQUEST['cf7_form_id'];
			$contact_form = wpcf7_contact_form( $cf7_form_id );
			self::log($contact_form);
		}
		
		
		$mime_types['zip'] = 'application/x-zip-compressed'; //Adding zip extension
		return $mime_types;
	}

	public function register_front_styles_and_scripts() {

		$dropzone_parameters = $this->get_dropzone_parameters();

		// Register scripts
		wp_register_script('cf7-multiupload-front-js', plugins_url('/js/cf7-multiupload-front.js', $this->plugin_root), array(), CFMU_VERSION, true);
		wp_enqueue_script('dropzone-js', plugins_url('/js/dropzone.js', $this->plugin_root), array(), CFMU_VERSION, true);
		wp_enqueue_script('cf7-multiupload-front-js');

		// Register styles
		wp_enqueue_style('cf7-multiupload-front', plugins_url('/css/cf7-multiupload-front.css', $this->plugin_root, array(), CFMU_VERSION));
		wp_localize_script('cf7-multiupload-front-js', 'dropzoneParameters', $dropzone_parameters);
	}

	public function register_admin_styles_and_scripts() {
		// Register scripts
		wp_enqueue_script('cf7-multiupload-admin-js', plugins_url('/js/cf7-multiupload-admin.js', $this->plugin_root), array(), CFMU_VERSION, true);
	}

	public function add_shortcode() {
		wpcf7_add_shortcode(
				array(CFMU_FIELD_NAME, CFMU_FIELD_NAME . '*'), array($this, 'shortcode_handler'), true // $has_name
		);
	}

	public function shortcode_handler($tag) {

		$tag = new WPCF7_Shortcode($tag);

		if (empty($tag->name)) {
			return '';
		}

		$id = $tag->get_id_option();

		$id = $id ? $id : $tag->name;

		$allowed_file_types = str_replace('|', ',', array_shift($tag->get_option('filetypes')));

		if (!$allowed_file_types) {
			$allowed_file_types = 'jpg,jpeg,png,gif,pdf,doc,docx,ppt,pptx,odt,avi,ogg,m4a,mov,mp3,mp4,mpg,wav,wmv';
		}

		$allowed_size = 1; // default size 1 MB
		$allowed_filesize_limit = $tag->get_option('limit');

		//$limit_pattern = '/^([1-9][0-9]*)([kKmM]?[bB])?$/';
		$limit_pattern = '/^([1-9][0-9]*)$/';

		foreach ($allowed_filesize_limit as $file_size) {
			if (preg_match($limit_pattern, $file_size, $matches)) {
				$allowed_size = (int) $matches[1];
/*
				if (!empty($matches[2])) {
					$kbmb = strtolower($matches[2]);

					if ('kb' == $kbmb)
						$allowed_size *= 1024;
					elseif ('mb' == $kbmb)
						$allowed_size *= 1024 * 1024;
				}
*/
				break;
			}
		}
		
		$max_files = 15;   // default limit
		$allowed_filecount_limit = $tag->get_option('max_files');


		if ($allowed_filecount_limit) {
			$max_files = $allowed_filecount_limit;
		}

		$out = '<div class="cf7_dropzone dropzone" id="' . $id . '" '
				. ' data-max-files="' . $max_files . '" '
				. ' data-max-file-size="' . $allowed_size . '" '
				/*. ' data-allowed-filetypes="' . $allowed_file_types . '" '*/
				. '></div>';
		$out .= '<input type="hidden" name="dropzone_uploaded_file_urls" class="uploaded_file_urls" value="">';
		$out .= '<input type="hidden" name="dropzone_uploaded_file_ids" class="uploaded_file_ids" value"">';
		//$out .= wp_nonce_field( 'cf7_dropzone_upload_files' );

		return $out;
	}

	public function add_tag_generator() {
		if (function_exists('wpcf7_add_tag_generator')) {
			//  wpcf7_add_tag_generator( $name, $title, $elm_id, $callback, $options = array() )
			wpcf7_add_tag_generator('multiupload', __('multiupload', CFMU_TEXT_DOMAIN), 'wpcf7-multiupload', array($this, 'tag_generator'));
		}
	}

	public function tag_generator($args) {
		$args = wp_parse_args($args, array(
			'content' => 'upload'
				));

		$type = CFMU_FIELD_NAME;

		$description = __("Generate a form-tag for a multi file upload.", CFMU_TEXT_DOMAIN);
		?>
		<div class="control-box">
			<fieldset>
				<legend><?php echo $description; ?></legend>

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html(__('Field type', 'contact-form-7')); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php echo esc_html(__('Field type', 'contact-form-7')); ?></legend>
									<label><input type="checkbox" name="required" /> <?php echo esc_html(__('Required field', 'contact-form-7')); ?></label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="<?php echo esc_attr($args['content'] . '-name'); ?>"><?php echo esc_html(__('Name', 'contact-form-7')); ?></label></th>
							<td><input type="text" name="name" class="tg-name oneline" id="<?php echo esc_attr($args['content'] . '-name'); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="<?php echo esc_attr($args['content'] . '-limit'); ?>"><?php echo esc_html(__("File size limit (Mb)", 'contact-form-7')); ?></label></th>
							<td><input type="text" name="limit" class="filesize oneline option" id="<?php echo esc_attr($args['content'] . '-limit'); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="<?php echo esc_attr($args['content'] . '-filetypes'); ?>"><?php echo esc_html(__('Acceptable file types', 'contact-form-7')); ?></label></th>
							<td><input type="text" name="filetypes" class="filetype oneline option" id="<?php echo esc_attr($args['content'] . '-filetypes'); ?>" /></td>
						</tr>
						
						<tr>
							<th scope="row"><label for="<?php echo esc_attr($args['content'] . '-imageonly'); ?>"><?php echo esc_html(__('Allow images only', 'contact-form-7')); ?></label></th>
							<td><input type="checkbox" name="imageonly" class="option" id="<?php echo esc_attr($args['content'] . '-imageonly'); ?>" /></td>
						</tr>
					</tbody>
				</table>
			</fieldset>
		</div>

		<div class="insert-box">
			<input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr(__('Insert Tag', 'contact-form-7')); ?>" />
			</div>

			<br class="clear" />

			<p class="description mail-tag"><label for="<?php echo esc_attr($args['content'] . '-mailtag'); ?>"><?php echo sprintf(esc_html(__("To attach the file uploaded through this field to mail, you need to insert the corresponding mail-tag (%s) into the File Attachments field on the Mail tab.", 'contact-form-7')), '<strong><span class="mail-tag"></span></strong>'); ?><input type="text" class="mail-tag code hidden" readonly="readonly" id="<?php echo esc_attr($args['content'] . '-mailtag'); ?>" /></label></p>
		</div>
		<?php
	}

	/**
	 * Sets enctype for any form containing 'multiupload' field
	 * @param string $enctype
	 * @return string
	 */
	public function form_enctype_filter($enctype) {
		$multipart = (bool) wpcf7_scan_shortcode(array('type' => array(CFMU_FIELD_NAME, CFMU_FIELD_NAME . '*')));

		if ($multipart) {
			$enctype = 'multipart/form-data';
		}

		return $enctype;
	}

	/**
	 * Sets CSS class for any form containing 'multiupload' field
	 * @param string $classes
	 * @return string
	 */
	public function form_class_filter($class) {
		$multipart = (bool) wpcf7_scan_shortcode(array('type' => array(CFMU_FIELD_NAME, CFMU_FIELD_NAME . '*')));
		$multiupload_class = 'dropzone';

		if ($multipart) {
			$class = $class ? $class . ' ' . $multiupload_class : $multiupload_class;
		}

		return $class;
	}

	/**
	 * Sets #id for any form containing 'multiupload' field
	 * @param string $id
	 * @return string
	 */
	public function form_id_filter($id) {

		$multipart = (bool) wpcf7_scan_shortcode(array('type' => array(CFMU_FIELD_NAME, CFMU_FIELD_NAME . '*')));
		$multiupload_id = 'test-dropzone';

		if ($multipart) {
			$id = $multiupload_id;
		}

		return $id;
	}

	/**
	 * see contact-form-7\modules\file.php
	 * @param type $result
	 * @param WPCF7_Shortcode $tag
	 * @return type
	 */
	public function validation_filter($result, $tag) {
		self::log('validation_filter');
		self::log($tag);
		$name = explode( '-', $tag['name'] );
		
		$uploaded_files = $this->get_uploaded_file_paths();

		if ($submission = WPCF7_Submission::get_instance()) {
			$i = 0;
			foreach ($uploaded_files as $file_name => $file_path) {
				$i++;
				$sub_name = $name[0] . '-' . $i;
				$submission->add_uploaded_file( $sub_name, $file_path );
			}
		}

		return $result;
	}

	public function get_uploaded_file_paths( $name ) {
		$uploaded_files_urls = $_POST['dropzone_uploaded_file_urls'];
		//$uploaded_files_ids = $_POST['dropzone_uploaded_file_ids'];
		$uploaded_files = array();

		if ($uploaded_files_urls) {
			$uploaded_files_urls = explode(',', $uploaded_files_urls);
			foreach ($uploaded_files_urls as $file_path) {
				$file_name = basename($file_path);
				$uploaded_files[$file_name] = $file_path;
			}
		}

		return $uploaded_files;
	}

	
	public function get_multiupload_settings() {
		$this->multiupload_tags = $this->get_multiupload_tags();
		if (is_array( $this->multiupload_tags )) {
			foreach ( $this->multiupload_tags as $tag ) {
				$this->multiupload_atts = $tag['options'];
				if ( is_array( $this->multiupload_atts ) && count( $this->multiupload_atts ) ) {
					foreach ( $this->multiupload_atts as $attribute ) {
						$this->parse_multiupload_attribute( $attribute );
					}
				}
				break;
			}			
		}
		return false;
	}
	
	public function parse_multiupload_attribute( $attribute ) {
		$temp = explode( ':', $attribute );
		switch ( $temp[0] ) {
			case 'imageonly':
				$this->settings['imageonly'] = true;
				break;
			case 'filetypes':
				break;
			case 'limit':
				break;
			default:
				break;
		}
	}
	
	public function get_current_contactform() {
		$cf7_form_id = $_REQUEST['cf7_form_id'];
		$cf7_form_id = explode( '-',$cf7_form_id );
		$cf7_form_id = substr( $cf7_form_id[1], 1);

		$this->contact_form = wpcf7_contact_form( $cf7_form_id );
		return $this->contact_form;
	}
	
	public function get_multiupload_tags() {
		if ( is_object( $this->contact_form ) ) {
			$multiupload_tags = $this->contact_form->form_scan_shortcode(
				array( 'type' => array( CFMU_FIELD_NAME, CFMU_FIELD_NAME . '*' ) ) 
			);
			return $multiupload_tags;
		}
		return false;
	}
	
	public function get_wp_error(WP_Error $wp_error) {
		return array_shift( $wp_error->errors['upload_error'] );
	}
	
	public function handle_dropped_media() {
		self::log($_REQUEST);
		self::log($_FILES);
		if ($this->get_current_contactform()) {
			$this->get_multiupload_settings();
			self::log('get_multiupload_settings');
			self::log($this->settings);
			if (!empty($_FILES)) {
				foreach ($_FILES as $file) {
					if ($this->settings['imageonly']) {
						$this->handle_dropped_image($file);
					} else {
						$this->handle_dropped_file($file);
					}
				}
			}
		}
	}
	
	public function handle_dropped_file($newfile) {
		// filetype-independent upload
		$upload_overrides = array(
			'test_form' => false,
			'mimes' => $this->get_allowed_mime_types(),
		);
		$movefile = wp_handle_upload( $newfile, $upload_overrides );
		if ( $movefile && !isset( $movefile['error'] ) ) {
			self::log( "File is valid, and was successfully uploaded.\n" ); 
			self::log( $movefile );
			$response = array(
				'status' => self::STATUS_OK,
				'file_id' => 0,
				'file_url' => $movefile['file'],
			);
		} else {
			/**
			 * Error generated by _wp_handle_upload()
			 * @see _wp_handle_upload() in wp-admin/includes/file.php
			 */
			self::log( $movefile );
			$response = array(
				'status' => self::STATUS_ERROR,
				'file_id' => 0,
				'file_url' => 0,
				'error' => $movefile['error']
			);
		}
		$this->respond_to_dropzonejs( $response );
	}
		
	public function handle_dropped_image( $newfile ) {
		$_FILES = array( 'upload' => $newfile );
		
		// WP media variant
		foreach ( $_FILES as $file => $array ) {
			$attachment_id = media_handle_upload( $file, 0 );
		}
		
		if (is_wp_error($attachment_id)) {
			self::log( 'There was an error uploading the image.' );
			self::log( $attachment_id );
			$response = array(
				'status' => self::STATUS_ERROR,
				'file_id' => 0,
				'file_url' => 0,
				'error' => $this->get_wp_error($attachment_id)
			);
		} else {
			// The image was uploaded successfully!
			$attachment_url = wp_get_attachment_url( $attachment_id );
			$response = array(
				'status' => self::STATUS_OK,
				'file_id' => $attachment_id,
				'file_url' => $attachment_url,
			);
		}
		
		self::log('Response');
		self::log($response);
		$this->respond_to_dropzonejs( $response );
	}

	private function handle_cf7( $newfile ) {
		
		/*
		  // CF7 variant
		  $filename = $file['name'];
		  $filename = wpcf7_canonicalize( $filename );
		  $filename = sanitize_file_name( $filename );
		  $filename = wpcf7_antiscript_file_name( $filename );
		  $filename = wp_unique_filename( $uploads_dir, $filename );
		  $new_file = trailingslashit( $uploads_dir ) . $filename;

		  if ( false === @move_uploaded_file( $file['tmp_name'], $new_file ) ) {

		  }
		 *
		 */

	}
	
	private function respond_to_dropzonejs($data) {
		
		/* From https://github.com/enyo/dropzone/wiki/FAQ :
		 * 
		 * If you want Dropzone to display any error encountered on the server, 
		 * all you have to do, is send back a proper HTTP status code in the range of 400 - 500.
		 * In PHP you can set it with the header command.
		 * Dropzone will then know that the file upload was invalid, 
		 * and will display the returned text as error message.
		 * If the Content-Type of your response is text/plain, you can just return the text without any further markup. 
		 * If the Content-Type is application/json, Dropzone will use the error property of the provided object. 
		 * Eg.: { "error": "File could not be saved." }
		 * 
		 */
		
		if ($data['status'] == self::STATUS_ERROR) {
			status_header(422);
		}
		else {
			status_header(200);
		}
		@header('Content-Type: application/json');
		echo( json_encode( $data ) );
		die();
	}
}
