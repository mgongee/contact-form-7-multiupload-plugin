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

	public function __construct( $plugin_root ) {

		$this->plugin_root = $plugin_root;

		parent::__construct();

		/* Register [multiupload] shortcode within CF7 */
		add_action( 'wpcf7_init', array( $this, 'add_shortcode' ) );

		/* Register [multiupload] shortcode tag generator within CF7 */
		add_action( 'admin_init', array( $this, 'add_tag_generator' ), 30);

		/* Register style sheet and scripts for the admin area. */
		add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_styles_and_scripts' ) );

		/* Register style sheet and scripts for the front. */
		add_action( 'wp_enqueue_scripts', array( $this, 'register_front_styles_and_scripts' ) );
		
		/* Text domain */
		add_action('plugins_loaded', array( $this,'load_textdomain' ) );

		/* Encode type filter */
		add_filter( 'wpcf7_form_enctype', array( $this, 'form_enctype_filter' ) );

		/* Form class filter */
		add_filter( 'wpcf7_form_class_attr', array( $this, 'form_class_filter' ) );

		/* Handle uploaded files */
		add_action( 'admin_post_cf7_dropzone_handle_dropped_media', array( $this, 'handle_dropped_media' ) );
		add_action( 'admin_post_nopriv_cf7_dropzone_handle_dropped_media', array( $this, 'handle_dropped_media' ) );

		/* Validation + upload handling filter */
		add_filter( 'wpcf7_validate_' . CFMU_FIELD_NAME, array( $this, 'validation_filter' ), 10, 2 );
		add_filter( 'wpcf7_validate_' . CFMU_FIELD_NAME . '*', array( $this, 'validation_filter'), 10, 2 );

		/* Allow extra file types to be uploaded */
		add_filter( 'upload_mimes', array( $this, 'add_mime_types' ), 10, 1 );

	}

	public function load_textdomain() {
		load_plugin_textdomain( CFMU_TEXT_DOMAIN, false, dirname( plugin_basename(__FILE__) ) . '/lang/' );
	}


	public function get_dropzone_parameters() {
		$dropzone_parameters = array(
			'action' => 'cf7_dropzone_handle_dropped_media',
			'upload_url' => admin_url( 'admin-post.php?action=cf7_dropzone_handle_dropped_media' ),
			// TODO 'delete_url' => admin_url('admin-post.php?action=cf7_dropzone_handle_deleted_media')
		);

		return $dropzone_parameters;
	}

	/**
	 * Finds corresponding file extensions for mime type
	 * @param string $mime_type e.g. "image/*"
	 * @param array $all_mime_types
	 * @return array
	 */
	public function find_extensions_for_mime_type( $mime_type, $all_mime_types) {
		$extra_mime_types = array();

		if ( false === strpos( $mime_type, '/*' ) ) { // this is a certain MIME type, find its extension in the list
			$compare_mode = 'non-strict';
		}
		else {  // this is a set of MIME types
			$compare_mode = 'strict';
		}

		foreach ( $all_mime_types as $extension_pattern => $mime_type_to_test ) {
			if ( $this->compare_mime_types( $mime_type, $mime_type_to_test, $compare_mode ) ) {
				$extension_pattern = trim( $extension_pattern, '|' );
				$extensions =  explode( '|', $extension_pattern );

				foreach ( $extensions as $extension ) {
					$ext = str_replace('.', '', $extension );
					$extra_mime_types[$ext] = $mime_type_to_test;
				}
			}
		}


		return $extra_mime_types;
	}

	/**
	 * Compares MIME types, e.g "image/*" to "image/jpg"
	 *
	 * @param string $mime_type
	 * @param string $mime_type_to_test
	 * @param string $compare_mode
	 * @return boolean
	 */
	public function compare_mime_types( $mime_type, $mime_type_to_test, $compare_mode = 'non-strict' ) {
		if ( $compare_mode == 'non-strict' ) {
			$mime_start = str_replace( '*' , '', $mime_type ); // turn "image/*" into "image/"
			return ( 0 === strpos( $mime_type_to_test, $mime_start ) ); // find "image/" in "image/png"
		}
		else {
			return ( $mime_type == $mime_type_to_test );
		}
	}

	/**
	 * Finds corresponding mime type for file extension
	 * @param string $extension e.g. ".zip"
	 * @param array $all_mime_types
	 * @return array
	 */
	public function find_mime_type_for_extension( $extension, $all_mime_types ) {
		$mime_types = array();

		foreach ($all_mime_types as $extension_pattern => $mime_type) {
			$extension_pattern = trim( $extension_pattern, '|' );
			$extension_pattern = '(' . $extension_pattern . ')';
			$extension_pattern = '/\.' . $extension_pattern . '$/i';

			if ( preg_match( $extension_pattern, $extension ) ) {
				$ext = str_replace('.', '', $extension );
				$mime_types[$ext] = $mime_type;
			}
		}

		return $mime_types;
	}

	public function add_mime_types($mime_types) {

		self::log('add_mime_types');
		if ( $this->get_current_contactform() ) {
			$this->get_multiupload_settings();
			self::log( $this->settings );
			if ( isset( $this->settings['mimetypes'] ) ) {
				$all_mime_types = wp_get_mime_types();

				foreach ( $this->settings['mimetypes'] as $mime_type ) {
					if ( 0 === strpos( $mime_type, '.' ) ) { // this is a file extension
						$extra_mime_types = $this->find_mime_type_for_extension( $mime_type, $all_mime_types );
						self::log( $mime_type . '$extra_mime_types<pre>' . print_r($extra_mime_types, 1) . '</pre>' );
						$mime_types = array_merge( $mime_types, $extra_mime_types );
					}
					else {
						if ( false === strpos( $mime_type, '/' ) ) {
							$mime_type .= '/*'; // assume that it is a set of MIME types
						}
						$extra_mime_types = $this->find_extensions_for_mime_type( $mime_type, $all_mime_types );
						self::log( $mime_type . 'a set of <pre>' . print_r( $extra_mime_types, 1 ) . '</pre>' );
						$mime_types = array_merge( $mime_types, $extra_mime_types );
					}
				}
			}

			self::log( ' final mime_types' );
			self::log( $mime_types );
		}
		else {
			self::log( 'failed to get contact form' );
		}


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

		$file_types = $tag->get_option( 'mimetypes' );
		$allowed_file_types = false;
		if ( is_array( $file_types ) ) {
			$file_types = $this->parse_mimetypes_option( array_shift( $file_types ) );
			$allowed_file_types = implode( ',', $file_types );
		}

		if ( ! $allowed_file_types ) {
			// same as in Contact Form 7
			$allowed_file_types = '.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.ppt,.pptx,.odt,.avi,.ogg,.m4a,.mov,.mp3,.mp4,.mpg,.wav,.wmv';
		}


		$allowed_size = 1; // default size 1 MB
		$allowed_filesize_limit = $tag->get_option('limit');

		$limit_pattern = '/^([1-9][0-9]*)$/';

		foreach ( $allowed_filesize_limit as $file_size ) {
			if ( preg_match( $limit_pattern, $file_size, $matches ) ) {
				$allowed_size = (int) $matches[1];
				break;
			}
		}

		$max_files = 15;   // default limit
		$allowed_filecount_limit = $tag->get_option( 'max_files' );


		if ( is_array( $allowed_filecount_limit ) && count( $allowed_filecount_limit ) ) {
			$max_files = array_pop($allowed_filecount_limit);
		}

		$out = '<div class="cf7_dropzone dropzone" id="' . $id . '" '
				. ' data-max-files="' . $max_files . '" '
				. ' data-max-file-size="' . $allowed_size . '" '
				. ' data-allowed-mimetypes="' . $allowed_file_types . '" '
				. '></div>';
		$out .= '<input type="hidden" name="dropzone_uploaded_file_urls" class="uploaded_file_urls" value="">';
		$out .= '<input type="hidden" name="dropzone_uploaded_file_ids" class="uploaded_file_ids" value="">';
		//$out .= wp_nonce_field( 'cf7_dropzone_upload_files' );

		return $out;
	}

	public function add_tag_generator() {
		if ( function_exists( 'wpcf7_add_tag_generator' ) ) {
			//  wpcf7_add_tag_generator( $name, $title, $elm_id, $callback, $options = array() )
			wpcf7_add_tag_generator( 'multiupload', __( 'multiupload', CFMU_TEXT_DOMAIN ), 'wpcf7-multiupload', array( $this, 'tag_generator' ) );
		}
	}

	public function tag_generator($args) {
		$args = wp_parse_args( $args, array(
			'content' => 'upload'
				) );

		$type = CFMU_FIELD_NAME;

		$description = __( "Generate a form-tag for a multi file upload.", CFMU_TEXT_DOMAIN );
		?>
		<div class="control-box">
			<fieldset>
				<legend><?php echo $description; ?></legend>

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html( __( 'Field type', 'contact-form-7' ) ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php echo esc_html(__('Field type', 'contact-form-7' ) ); ?></legend>
									<label><input type="checkbox" name="required" /> <?php echo esc_html( __( 'Required field', 'contact-form-7' ) ); ?></label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-name' ); ?>"><?php echo esc_html( __( 'Name', 'contact-form-7' ) ); ?></label></th>
							<td><input type="text" name="name" class="tg-name oneline" id="<?php echo esc_attr( $args['content'] . '-name'); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-limit' ); ?>"><?php echo esc_html( __("File size limit (Mb)", 'contact-form-7' ) ); ?></label></th>
							<td><input type="text" name="limit" class="filesize oneline option" id="<?php echo esc_attr( $args['content'] . '-limit'); ?>" /></td>
						</tr>
						
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-max_files' ); ?>"><?php echo esc_html( __("Max. number of files", CFMU_TEXT_DOMAIN ) ); ?></label></th>
							<td><input type="text" name="max_files" class="oneline option" id="<?php echo esc_attr( $args['content'] . '-max_files'); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-mimetypes' ); ?>"><?php echo esc_html( __('Acceptable MIME types', 'contact-form-7' ) ); ?></label></th>
							<td>
								<input type="text" name="mimetypes" class="filetype oneline option" id="<?php echo esc_attr( $args['content'] . '-mimetypes' ); ?>" />
								<br />
								<small>Example: <em><?php echo esc_html( __('"image" for "image/*" MIME type, ".zip" for ZIP files', CFMU_TEXT_DOMAIN) ); ?></em></small>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="<?php echo esc_attr($args['content'] . '-mediaupload'); ?>"><?php echo esc_html(__('Put files in media library', CFMU_TEXT_DOMAIN)); ?></label></th>
							<td><input type="checkbox" name="mediaupload" class="option" id="<?php echo esc_attr($args['content'] . '-mediaupload'); ?>" /></td>
						</tr>
					</tbody>
				</table>
			</fieldset>
		</div>

		<div class="insert-box">
			<input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'contact-form-7')); ?>" />
			</div>

			<br class="clear" />

			<p class="description mail-tag"><label for="<?php echo esc_attr( $args['content'] . '-mailtag' ); ?>"><?php echo sprintf( esc_html( __( "To attach the file uploaded through this field to mail, you need to insert the corresponding number of [multiupload-XX] mail-tags into the File Attachments field on the Mail tab, like this: [multiupload-1] [multiupload-2] [multiupload-3] .", CFMU_TEXT_DOMAIN ) ), '<strong><span class="mail-tag"></span></strong>'); ?><input type="text" class="mail-tag code hidden" readonly="readonly" id="<?php echo esc_attr( $args['content'] . '-mailtag' ); ?>" /></label></p>
		</div>
		<?php
	}

	/**
	 * Sets enctype for any form containing 'multiupload' field
	 * @param string $enctype
	 * @return string
	 */
	public function form_enctype_filter( $enctype ) {
		$multipart = (bool) wpcf7_scan_shortcode( array( 'type' => array( CFMU_FIELD_NAME, CFMU_FIELD_NAME . '*' ) ) );

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
		$multipart = (bool) wpcf7_scan_shortcode( array( 'type' => array( CFMU_FIELD_NAME, CFMU_FIELD_NAME . '*') ) );
		$multiupload_class = 'dropzone';

		if ( $multipart ) {
			$class = $class ? $class . ' ' . $multiupload_class : $multiupload_class;
		}

		return $class;
	}

	/**
	 * @param type $result
	 * @param WPCF7_Shortcode $tag
	 * @return type
	 */
	public function validation_filter( $result, $tag ) {
		self::log( 'validation_filter' );
		self::log( $tag );
		$name = explode( '-', $tag['name'] );

		$uploaded_files = $this->get_uploaded_file_paths();
		$copied_files = $this->copy_uploaded_files( $uploaded_files );

		if ( $copied_files === false ) {
			$result->invalidate( $tag, wpcf7_get_message( 'upload_failed' ) );
		}
		elseif ( $submission = WPCF7_Submission::get_instance() ) {
			$i = 0;
			foreach ( $copied_files as $file_name => $file_path ) {
				$i++;
				$sub_name = $name[0] . '-' . $i;
				self::log("$sub_name , $file_path ");
				$submission->add_uploaded_file( $sub_name, $file_path );
			}
		}

		return $result;
	}

	/**
	 * see contact-form-7\modules\file.php - function wpcf7_file_validation_filter()
	 * Uploaded files handled same as there
	 * (need to copy them into CF7 location because CF7 deletes them after emailing )
	 *
	 * @param type $result
	 * @param array $uploaded_files
	 * @return type
	 */
	public function copy_uploaded_files( $uploaded_files ) {
		self::log( 'preparing to copy_uploaded_files' ) ;
		self::log( $uploaded_files ) ;
		wpcf7_init_uploads(); // Confirm upload dir
		$uploads_dir = wpcf7_upload_tmp_dir();
		$uploads_dir = wpcf7_maybe_add_random_dir( $uploads_dir );
		$copied_files = array();

		foreach ($uploaded_files as $file_path) {
			$filename = basename($file_path);
			$filename = wpcf7_canonicalize( $filename );
			$filename = sanitize_file_name( $filename );
			$filename = wpcf7_antiscript_file_name( $filename );
			$filename = wp_unique_filename( $uploads_dir, $filename );

			$new_file = trailingslashit( $uploads_dir ) . $filename;

			self::log("Copying file from $file_path to $new_file ... ");

			if ( false === @copy( $file_path, $new_file ) ) {
				self::log("Copying FAILED ");
				return false;
			}


			$copied_files[$filename] = $new_file;
		}

		return $copied_files;
	}

	public function get_uploaded_file_paths( ) {
		$uploaded_files_urls = $_POST['dropzone_uploaded_file_urls'];
		//$uploaded_files_ids = $_POST['dropzone_uploaded_file_ids'];
		$uploaded_files = array();
		$upload_dir = wp_upload_dir();

		if ( $uploaded_files_urls ) {
			$uploaded_files_urls = explode( ',', $uploaded_files_urls );
			
			foreach ($uploaded_files_urls as $file_path ) {
				$file_name = basename( $file_path );
				if ( false === strpos( $file_path, $upload_dir['basedir'] ) ) {
					$uploaded_files[$file_name] = $upload_dir['basedir'] . DIRECTORY_SEPARATOR .  $file_path;
				}
				else {
					$uploaded_files[$file_name] = $file_path;
				}
			}
		}

		return $uploaded_files;
	}


	public function get_multiupload_settings() {
		$this->multiupload_tags = $this->get_multiupload_tags();
		if ( is_array( $this->multiupload_tags ) ) {
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

	/**
	 * Parses MIME type option string
	 * @param string $mime_types MIME type codes, separated by "|"
	 * @return array
	 */
	public function parse_mimetypes_option( $mime_types ) {


		if ( ! $mime_types ) return false;
		$mime_types = explode( '|', $mime_types );
		$parsed_mimetypes = array();

		foreach ( $mime_types as $mime_type ) {
			if ( 0 === strpos( $mime_type, '.' ) ) {
				// dot at the start of the string denotes file extension, e.g ".zip" or ".mp3"
				$parsed_mimetypes[] = $mime_type;
			}
			else {
				// this is a codename for a MIME type, like "image" or "application"
				$parsed_mimetypes[] = $mime_type . '/*';
			}
		}

		return $parsed_mimetypes;
	}

	public function parse_multiupload_attribute( $attribute ) {
		$temp = explode( ':', $attribute );
		switch ( $temp[0] ) {
			case 'mediaupload':
				$this->settings['mediaupload'] = true;
				break;
			case 'mimetypes':
				$this->settings['mimetypes'] = $this->parse_mimetypes_option( $temp[1] );

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

	public function get_wp_error( WP_Error $wp_error ) {
		return array_shift( $wp_error->errors['upload_error'] );
	}

	/**
	 * Receive file from DropzoneJS
	 */
	public function handle_dropped_media() {
		self::log( $_REQUEST );
		self::log( $_FILES );
		if ( $this->get_current_contactform() ) {
			$this->get_multiupload_settings();
			self::log( 'get_multiupload_settings' );
			self::log( $this->settings );
			if ( ! empty( $_FILES ) ) {
				foreach ( $_FILES as $file ) {
					if ( $this->settings['mediaupload'] ) {
						$this->handle_dropped_image( $file );
					} else {
						$this->handle_dropped_file( $file );
					}
				}
			}
		}
	}

	/**
	 * Receive file from DropzoneJS
	 * Process uploaded file via Wordpress upload mechanism
	 * @param string $newfile file  url
	 */
	public function handle_dropped_file( $newfile ) {
		// filetype-independent upload
		$upload_overrides = array(
			'test_form' => false,
//			'mimes' => $this->get_allowed_mime_types(),
		);
		$movefile = wp_handle_upload( $newfile, $upload_overrides );
		if ( $movefile && ! isset( $movefile['error'] ) ) {
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

	/**
	 * Receive file from DropzoneJS
	 * Process uploaded file via Wordpress Media Library
	 * @param string $newfile file  url
	 */
	public function handle_dropped_image( $newfile ) {
		$_FILES = array( 'upload' => $newfile );

		// WP media variant
		foreach ( $_FILES as $file => $array ) {
			$attachment_id = media_handle_upload( $file, 0 );
		}

		if ( is_wp_error( $attachment_id ) ) {
			self::log( 'There was an error uploading the image.' );
			self::log( $attachment_id );
			$response = array(
				'status' => self::STATUS_ERROR,
				'file_id' => 0,
				'file_url' => 0,
				'error' => $this->get_wp_error( $attachment_id )
			);
		} else {
			// The image was uploaded successfully! 
			//$attachment_url = wp_get_attachment_url( $attachment_id );

			$attachment_url = get_post_meta( $attachment_id, '_wp_attached_file', true );

			$response = array(
				'status' => self::STATUS_OK,
				'file_id' => $attachment_id,
				'file_url' => $attachment_url,
			);
		}

		self::log( 'Response' );
		self::log( $response );
		$this->respond_to_dropzonejs( $response );
	}

	private function respond_to_dropzonejs( $data ) {

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
		@header( 'Content-Type: application/json' );
		echo( json_encode( $data ) );
		die();
	}
}
