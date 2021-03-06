<?php
/**
 * @version 1.5 stable $Id: filemanager.php 1846 2014-02-14 02:36:41Z enjoyman@gmail.com $
 * @package Joomla
 * @subpackage FLEXIcontent
 * @copyright (C) 2009 Emmanuel Danan - www.vistamedia.fr
 * @license GNU/GPL v2
 * 
 * FLEXIcontent is a derivative work of the excellent QuickFAQ component
 * @copyright (C) 2008 Christoph Lukes
 * see www.schlu.net for more information
 *
 * FLEXIcontent is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

defined( '_JEXEC' ) or die( 'Restricted access' );

use Joomla\String\StringHelper;

// Register autoloader for parent controller, in case controller is executed by another component
JLoader::register('FlexicontentController', JPATH_BASE.DS.'components'.DS.'com_flexicontent'.DS.'controller.php');   // we use JPATH_BASE since parent controller exists in frontend too


/**
 * FLEXIcontent Component Files Controller
 *
 * @package Joomla
 * @subpackage FLEXIcontent
 * @since 1.0
 */
class FlexicontentControllerFilemanager extends FlexicontentController
{
	var $runMode = 'standalone';

	var $exitHttpHead = null;
	var $exitMessages = array();
	var $exitLogTexts = array();
	var $exitSuccess  = true;


	/**
	 * Constructor
	 *
	 * @since 1.0
	 */
	function __construct()
	{
		parent::__construct();
		$this->registerTask( 'uploads', 	'upload' );

		$this->option = $this->input->get('option', '', 'cmd');
		$this->task   = $this->input->get('task', '', 'cmd');
		$this->view   = $this->input->get('view', '', 'cmd');
		$this->format = $this->input->get('format', '', 'cmd');

		// Get return URL
		$this->returnURL = $this->input->get('return-url', null, 'base64');
		$this->returnURL = $this->returnURL ? base64_decode($this->returnURL) : $this->returnURL;

		// Compatibility for upload and addlocal tasks:  null return URL means return IDs
		if ( ($this->task=='upload' || $this->task=='addurl' || $this->task=='addlocal') && $this->returnURL === null )
		{
			$this->runMode = 'interactive';
		}

		// Check return URL if empty or not safe and set a default one
		if ( ! $this->returnURL || ! flexicontent_html::is_safe_url($this->returnURL) )
		{
			if ($this->view == 'filemanager')
			{
				$this->returnURL = 'index.php?option=com_flexicontent&view=filemanager';
			}
			else if ( !empty($_SERVER['HTTP_REFERER']) && flexicontent_html::is_safe_url($_SERVER['HTTP_REFERER']) )
			{
				$this->returnURL = $_SERVER['HTTP_REFERER'];
			}
			else
			{
				$this->returnURL = null;
			}
		}
	}


	/**
	 * Upload files
	 *
	 * @since 1.0
	 */
	function upload($Fobj = null, & $exitMessages = null)
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		
		$app    = JFactory::getApplication();
		$user   = JFactory::getUser();
		$session = JFactory::getSession();

		// Force interactive run mode, if given parameters
		$this->runMode = $Fobj ? 'interactive' : $this->runMode;
		$file_id = 0;

		// Force JSON format for 'uploads' task
		$this->format = $this->format != '' ? $this->format : ($this->task=='uploads' ? 'json' : 'html');

		// calculate access
		$canuploadfile = $user->authorise('flexicontent.uploadfiles', 'com_flexicontent');
		$is_authorised = $canuploadfile;

		// check access
		if ( !$is_authorised )
		{
			$this->exitHttpHead = array( 0 => array('status' => '403 Forbidden') );
			$this->exitMessages = array( 0 => array('error' => 'FLEXI_YOUR_ACCOUNT_CANNOT_UPLOAD') );
			$this->exitLogTexts = array();
			$this->exitSuccess  = false;

			return $this->terminate($file_id, $exitMessages);
		}

		$file_row_id = $data_from_sess = $uploader_file_data = false;

		if ($this->task=='uploads')
		{
			$file = $this->input->files->get('file', '', 'array');
			$file_row_id = $this->input->get('file_row_id', '', 'string');

			$uploader_file_data = $session->get('uploader_file_data', array(), 'flexicontent');

			$data_from_sess = @ $uploader_file_data[$file_row_id];
			if ($data_from_sess)
			{
				$filetitle  = $data_from_sess['filetitle'];
				$filedesc   = $data_from_sess['filedesc'];
				$filelang   = $data_from_sess['filelang'];
				$fileaccess = $data_from_sess['fileaccess'];
				$secure     = $data_from_sess['secure'];

				$fieldid    = $data_from_sess['fieldid'];
				$u_item_id  = $data_from_sess['u_item_id'];
				$file_mode  = $data_from_sess['file_mode'];
			}
			//print_r($file_row_id); echo "\n"; print_r($uploader_file_data); exit();
		}
		else
		{
			// Default field <input type="file" is name="Filedata" ... get the file
			$ffname = $this->input->get('file-ffname', 'Filedata', 'cmd');
			$file   = $this->input->files->get($ffname, '', 'array');
			
			// Refactor the array swapping positions
			$file = $this->refactorFilesArray($file);
			
			// Get nested position, and reach the final file data array
			$fname_level1 = $this->input->get('fname_level1', null, 'string');
			$fname_level2 = $this->input->get('fname_level2', null, 'string');
			$fname_level3 = $this->input->get('fname_level3', null, 'string');
			
			if (strlen($fname_level1))  $file = $file[$fname_level1];
			if (strlen($fname_level2))  $file = $file[$fname_level2];
			if (strlen($fname_level3))  $file = $file[$fname_level3];
		}
		
		if (empty($data_from_sess))
		{
			$secure  = $this->input->get('secure', 1, 'int');
			$secure  = $secure ? 1 : 0;

			$filetitle  = $this->input->get('file-title', '', 'string');
			$filedesc   = flexicontent_html::dataFilter($this->input->get('file-desc', '', 'string'), 32000, 'STRING', 0);  // Limit number of characters
			$filelang   = $this->input->get('file-lang', '*', 'string');
			$fileaccess = $this->input->get('file-access', 1, 'int');
			$fileaccess = flexicontent_html::dataFilter($fileaccess, 11, 'ACCESSLEVEL', 0);  // Validate access level exists (set to public otherwise)

			$fieldid    = $this->input->get('fieldid', 0, 'int');
			$u_item_id  = $this->input->get('u_item_id', 0, 'cmd');
			$file_mode  = $this->input->get('folder_mode', 0, 'int') ? 'folder_mode' : 'db_mode';
		}

		$model = $this->getModel('filemanager');
		if ($file_mode != 'folder_mode' && $fieldid)
		{
			// Check if FORCED secure/media mode parameter exists and if it is forced
			$field_params = $model->getFieldParams($fieldid);
			$target_dir = $field_params->get('target_dir', '');
			
			if ( strlen($target_dir) && $target_dir!=2 ) {
				$secure = $target_dir ? 1 : 0; // force secure / media
			} else {
				// allow filter secure via form/URL variable
			}
		}


		// *****************************************
		// Check that a file was provided / uploaded
		// *****************************************
		if ( !isset($file['name']) )
		{
			$this->exitHttpHead = array( 0 => array('status' => '400 Bad Request') );
			$this->exitMessages = array( 0 => array('error' => 'Filename has invalid characters (or other error occured)') );
			$this->exitLogTexts = array();
			$this->exitSuccess  = false;

			return $this->terminate($file_id, $exitMessages);
		}

		// Chunking might be enabled
		$chunks = $this->input->get('chunks', 0, 'int');
		if ($chunks)
		{
			$chunk = $this->input->get('chunk', 0, 'int');
			
			// Get / Create target directory
			$targetDir = (ini_get("upload_tmp_dir") ? ini_get("upload_tmp_dir") : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . "fc_fileselement";
			if (!file_exists($targetDir))  @mkdir($targetDir);
			
			// Create name of the unique temporary filename to use for concatenation of the chunks, or get the filename from session
			$fileName = $this->input->get('filename', '', 'string');

			$fileName_tmp = $app->getUserState( $fileName, date('Y_m_d_').uniqid() );
			$app->setUserState( $fileName, $fileName_tmp );
			$filePath_tmp = $targetDir . DIRECTORY_SEPARATOR . $fileName_tmp;

			// CREATE tmp file inside SERVER tmp directory, but if this FAILS, then CREATE tmp file inside the Joomla temporary folder
			if (!$out = @fopen("{$filePath_tmp}", "ab"))
			{
				$targetDir = $app->getCfg('tmp_path') . DIRECTORY_SEPARATOR . "fc_fileselement";
				if (!file_exists($targetDir))  @mkdir($targetDir);
				$filePath_tmp = $targetDir . DIRECTORY_SEPARATOR . $fileName_tmp;

				if (!$out = @fopen("{$filePath_tmp}", "ab"))
				{
					die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream: '.$filePath_tmp. ' fopen failed. reason: ' . implode(' ', error_get_last()) . '"}, "id" : "id"}');
				}
			}
			
			if (!empty($_FILES)) {
				if ($_FILES["file"]["error"] || !is_uploaded_file($_FILES["file"]["tmp_name"]))
					die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
				if (!$in = @fopen($_FILES["file"]["tmp_name"], "rb"))
					die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
			} else {	
				if (!$in = @fopen("php://input", "rb"))
					die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
			}
			// Read binary input stream and append it to temp file
			while ($buff = fread($in, 4096)) {
				fwrite($out, $buff);
			}
			
			@fclose($out);
			@fclose($in);
			
			// If not last chunk terminate further execution
			if ($chunk < $chunks - 1)
			{
				// Return Success JSON-RPC response
				die('{"jsonrpc" : "2.0", "result" : null, "id" : "id"}');
			}

			// Remove no longer needed file properties from session data
			else if ( $file_row_id && isset($uploader_file_data[$file_row_id]) )
			{
				unset($uploader_file_data[$file_row_id]);
				$session->set('uploader_file_data', $uploader_file_data, 'flexicontent');
			}

			// Clear the temporary filename, from user state
			$app->setUserState( $fileName, null );
			
			// Cleanup left-over files
			if (file_exists($targetDir))
			{
				foreach (new DirectoryIterator($targetDir) as $fileInfo)
				{
					if ($fileInfo->isDot()) {
						continue;
					}
					if (time() - $fileInfo->getCTime() >= 60) {
						unlink($fileInfo->getRealPath());
					}
				}
			}
			
			//echo "-- chunk: $chunk \n-- chunks: $chunks \n-- targetDir: $targetDir \n--filePath_tmp: $filePath_tmp \n--fileName: $fileName";
			//echo "\n"; print_r($_REQUEST);
			$file['name'] = $fileName;
			$file['tmp_name'] = $filePath_tmp;
			$file['size'] = filesize($filePath_tmp);
			$file['error'] = 0;
			//echo "\n"; print_r($file);
		}
		
		if ($file_mode == 'folder_mode') {
			$upload_path_var = 'fc_upload_path_'.$fieldid.'_'.$u_item_id;
			$path = $app->getUserState( $upload_path_var, '' ).DS;
			if ($this->task!='uploads') $app->setUserState( $upload_path_var, '');  // Do not clear in multi-upload
		} else {
			$path = $secure ? COM_FLEXICONTENT_FILEPATH.DS : COM_FLEXICONTENT_MEDIAPATH.DS;
		}
		
		jimport('joomla.utilities.date');

		// Set FTP credentials, if given
		jimport('joomla.client.helper');
		JClientHelper::setCredentialsFromRequest('ftp');

		// Make the filename safe
		jimport('joomla.filesystem.file');

		// Sanitize filename further and make unique
		$params = null;
		$err_text = null;
		$filename_original = strip_tags($file['name']);  // Store original filename before sanitizing the filename
		$upload_check = flexicontent_upload::check($file, $err_text, $params);  // Check that file contents are safe, and also make the filename safe, transliterating it according to given language (this forces lowercase)
		$filename     = flexicontent_upload::sanitize($path, $file['name']);    // Sanitize the file name (filesystem-safe, (this should have been done above already)) and also return an unique filename for the given folder
		$filepath 	  = JPath::clean($path.$filename);
		
		// Check if uploaded file is valid
		if (!$upload_check)
		{
			$this->exitHttpHead = array( 0 => array('status' => '415 Unsupported Media Type') );
			$this->exitMessages = array( 0 => array('error' => $err_text) );
			$this->exitLogTexts = array( 0 => array(JLog::ERROR => 'Invalid: ' . $filepath . ': ' . JText::_($err_text)) );
			$this->exitSuccess  = false;

			return $this->terminate($file_id, $exitMessages);
		}
		
		// Get the extension to record it in the DB
		$ext = strtolower(flexicontent_upload::getExt($filename));

		//echo "\n". $file['tmp_name'] ." => ". $filepath ."\n";
		$move_success = $chunks ?
			rename($file['tmp_name'], $filepath) :
			JFile::upload($file['tmp_name'], $filepath, false, false,
				// - Valid extensions are checked by our helper function
				// - also we allow all extensions and php inside content, FLEXIcontent will never execute "include" files evening when doing "in-browser viewing"
				array('null_byte'=>true, 'forbidden_extensions'=>array('_fake_ext_'), 'php_tag_in_content'=>true, 'shorttag_in_content'=>true, 'shorttag_extensions'=>array(), 'fobidden_ext_in_content'=>false, 'php_ext_content_extensions'=>array() )
			);

		// Check of upload failed
		if (!$move_success)
		{
			$this->exitHttpHead = array( 0 => array('status' => '409 Conflict') );
			$this->exitMessages = array( 0 => array('error' => 'FLEXI_UNABLE_TO_UPLOAD_FILE') );
			$this->exitLogTexts = array( 0 => array(JLog::ERROR => JText::_('FLEXI_UNABLE_TO_UPLOAD_FILE') . ': ' . $filepath) );
			$this->exitSuccess  = false;

			return $this->terminate($file_id, $exitMessages);
		}


		// *****************
		// Upload Successful
		// *****************

		// a. Database mode
		if ($file_mode == 'db_mode')
		{
			$db 	= JFactory::getDBO();
			$user	= JFactory::getUser();

			$path = $secure ? COM_FLEXICONTENT_FILEPATH.DS : COM_FLEXICONTENT_MEDIAPATH.DS;  // JPATH_ROOT . DS . <media_path | file_path> . DS
			$filepath = $path . $filename;
			$filesize = file_exists($filepath) ? filesize($filepath) : 0;

			$obj = new stdClass();
			$obj->filename    = $filename;
			$obj->filename_original = $filename_original;
			$obj->altname     = $filetitle ? $filetitle : $filename_original;

			$obj->url         = 0;
			$obj->secure      = $secure;
			$obj->ext         = $ext;

			$obj->description = $filedesc;
			$obj->language    = strlen($filelang) ? $filelang : '*';
			$obj->access      = strlen($fileaccess) ? $fileaccess : 1;

			$obj->hits        = 0;
			$obj->size        = $filesize;
			$obj->uploaded    = JFactory::getDate('now')->toSql();
			$obj->uploaded_by = $user->get('id');

			// Insert file record in DB
			$db->insertObject('#__flexicontent_files', $obj);

			// Get id of new file record
			$file_id = (int) $db->insertid();
		}

		// b. Custom Folder mode
		else
		{
			$file_id = 0;
		}

		// Add information about uploaded file data into the session
		$filter_item = $app->getUserStateFromRequest( $this->option . '.fileselement.item_id', 'item_id', '', 'int' );
		if ($filter_item)
		{
			$context = 'fileselement.item_'.$filter_item.'.uploaded_files.';

			$_file_ids = $session->get($context.'ids', array());
			$_file_ids[] = $file_id;
			$session->set($context.'ids', $_file_ids);

			$_file_names = $session->get($context.'names', array());
			$_file_names[] = $filename;
			$session->set($context.'names', $_file_names);
		}
		// TODO remove this and use the above ...
		$app->setUserState('newfilename', $filename);

		// Terminate with proper messaging
		$this->exitHttpHead = array( 0 => array('status' => '201 Created') );
		$this->exitMessages = array( 0 => array('message' => 'FLEXI_UPLOAD_COMPLETE') );
		$this->exitLogTexts = array();
		$this->exitSuccess  = true;

		return $this->terminate($file_id, $exitMessages);
	}


	/**
	 * Upload a file by url
	 *
	 * @since 1.0
	 */
	function addurl($Fobj = null, & $exitMessages = null)
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		$app = JFactory::getApplication();
		$session = JFactory::getSession();

		// Force interactive run mode, if given parameters
		$this->runMode = $Fobj ? 'interactive' : $this->runMode;
		$file_id = 0;

		$filename = $this->input->get('file-url-data', null, 'string');
		$filename = flexicontent_html::dataFilter($filename, 4000, 'URL', 0);  // Validate file URL
		$altname  = $this->input->get('file-url-title', null, 'string');

		$filedesc   = flexicontent_html::dataFilter($this->input->get('file-url-desc', '', 'string'), 32000, 'STRING', 0);  // Limit number of characters
		$filelang   = $this->input->get('file-url-lang', '*', 'string');
		$fileaccess = $this->input->get('file-url-access', 1, 'int');
		$fileaccess = flexicontent_html::dataFilter($fileaccess, 11, 'ACCESSLEVEL', 0);  // Validate access level exists (set to public otherwise)

		$ext      = $this->input->get('file-url-ext', null, 'alnum');
		$filesize = $this->input->get('file-url-size', 0, 'int');
		$size_unit= $this->input->get('size_unit', 'KBs', 'cmd');

		jimport('joomla.utilities.date');

		// check if the form fields are not empty
		if (!$filename || !$altname)
		{
			$this->exitHttpHead = array( 0 => array('status' => '400 Bad Request') );
			$this->exitMessages = array( 0 => array('error' => 'FLEXI_WARNFILEURLFORM') );
			$this->exitLogTexts = array();
			$this->exitSuccess  = false;

			return $this->terminate($file_id, $exitMessages);
		}

		if (empty($filesize))
		{
			$filesize = $this->get_file_size_from_url($filename);
			if ($filesize < 0) $filesize = 0;
		}

		else
		{
			$arr_sizes = array('KBs'=>1024, 'MBs'=>(1024*1024), 'GBs'=>(1024*1024*1024));
			$size_unit = (int) @ $arr_sizes[$size_unit];
			if ( $size_unit )
				$filesize = ((int)$filesize) * $size_unit;
			else
				$filesize = 0;
		}
		
		// we verifiy the url prefix and add http if any
		if (!preg_match("#^http|^https|^ftp#i", $filename)) { $filename	= 'http://'.$filename; }
		
		$db 	= JFactory::getDBO();
		$user	= JFactory::getUser();
		
		$obj = new stdClass();
		$obj->filename    = $filename;
		$obj->altname     = $altname;

		$obj->url         = 1;
		$obj->secure      = 1;
		$obj->ext         = $ext;

		$obj->description = $filedesc;
		$obj->language    = strlen($filelang) ? $filelang : '*';
		$obj->access      = strlen($fileaccess) ? $fileaccess : 1;

		$obj->hits        = 0;
		$obj->size        = $filesize;
		$obj->uploaded    = JFactory::getDate('now')->toSql();
		$obj->uploaded_by = $user->get('id');

		$db->insertObject('#__flexicontent_files', $obj);

		// Get id of new file record
		$file_id = (int) $db->insertid();

		$filter_item = $app->getUserStateFromRequest( $this->option . '.fileselement.item_id', 'item_id', '', 'int' );

		// Add information about added (URL) file data into the session
		if ($filter_item)
		{
			$context = 'fileselement.item_'.$filter_item.'.added_urls.';

			$_file_ids = $session->get($context.'ids', array());
			$_file_ids[] = $file_id;
			$session->set($context.'ids', $_file_ids);

			$_file_names = $session->get($context.'names', array());
			$_file_names[] = $filename;
			$session->set($context.'names', $_file_names);
		}
		// TODO remove this and use the above ...
		$app->setUserState('newfilename', $filename);

		// Terminate with proper messaging
		$this->exitHttpHead = array( 0 => array('status' => '201 Created') );
		$this->exitMessages = array( 0 => array('message' => 'FLEXI_FILE_ADD_SUCCESS') );
		$this->exitLogTexts = array();
		$this->exitSuccess  = true;

		return $this->terminate($file_id, $exitMessages);
	}


	/**
	 * Upload a file from a server directory
	 *
	 * @since 1.0
	 */
	function addlocal($Fobj = null, & $exitMessages = null)
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		static $imported_files = array();
		$file_ids = array();

		$app    = JFactory::getApplication();
		$db 		= JFactory::getDBO();
		$user		= JFactory::getUser();
		$params = JComponentHelper::getParams('com_flexicontent');
		
		$is_importcsv = $this->task == 'importcsv';

		// Get file properties
		$secure  = $Fobj ? $Fobj->secure : $this->input->get('secure', 1, 'int');
		$secure  = $secure ? 1 : 0;

		$filedesc   = flexicontent_html::dataFilter($this->input->get('file-desc', '', 'string'), 32000, 'STRING', 0);  // Limit number of characters
		$filelang   = $this->input->get('file-lang', '*', 'string');
		$fileaccess = $this->input->get('file-access', 1, 'int');
		$fileaccess = flexicontent_html::dataFilter($fileaccess, 11, 'ACCESSLEVEL', 0);  // Validate access level exists (set to public otherwise)

		// Get folder path and filename regexp
		$filesdir = $Fobj ? $Fobj->file_dir_path  : $this->input->get('file-dir-path', '', 'string');
		$regexp   = $Fobj ? $Fobj->file_filter_re : $this->input->get('file-filter-re', '.', 'string');

		// Delete after adding flag
		$keep = $Fobj ? $Fobj->keep : $this->input->get('keep', 1, 'int');

		// Get desired extensions from request
		$filter_ext = $this->input->get('file-filter-ext', '', 'string');
		$filter_ext	= $filter_ext ? explode(',', $filter_ext) : array();
		foreach($filter_ext as $_i => $_ext) $filter_ext[$_i] = strtolower($_ext);

		// Get extensions allowed by configuration, and intersect them with desired extensions
		$allowed_exts = preg_split("/[\s]*,[\s]*/", strtolower($params->get('upload_extensions', 'bmp,csv,doc,docx,gif,ico,jpg,jpeg,odg,odp,ods,odt,pdf,png,ppt,pptx,swf,txt,xcf,xls,xlsx,zip,ics')));
		$allowed_exts = $filter_ext ? array_intersect($filter_ext, $allowed_exts) : $allowed_exts;
		$allowed_exts = array_flip($allowed_exts);

		jimport('joomla.utilities.date');
		jimport('joomla.filesystem.file');
		jimport('joomla.filesystem.folder');

		// Get files
		$filesdir = JPath::clean(JPATH_SITE . $filesdir . DS);
		$filenames = JFolder::files($filesdir, $regexp);

		// Create the folder if it does not exists
		$destpath = $secure ? COM_FLEXICONTENT_FILEPATH.DS : COM_FLEXICONTENT_MEDIAPATH.DS;
		if (!JFolder::exists($destpath))
		{ 
			if (!JFolder::create($destpath))
			{
				JError::raiseWarning(100, JText::_('Error. Unable to create folders'));
				return;
			} 
		}
		
		
		// check if the form fields are not empty
		if (!$filesdir)
		{
			JError::raiseNotice(1, JText::_( 'FLEXI_WARN_NO_FILE_DIR' ));

			if ($this->return)
				$app->redirect($this->return ."&". JSession::getFormToken() ."=1");
			else
				return null;
		}
		
		$added = array();
		$excluded = array();
		if ($filenames)
		{
			for ($n=0; $n<count($filenames); $n++)
			{
				$ext = strtolower(flexicontent_upload::getExt($filenames[$n]));
				if ( !isset($allowed_exts[$ext]) )
				{
					$excluded[] = $filenames[$n];
					continue;
				}
				
				$source 		= $filesdir . $filenames[$n];
				$filename		= flexicontent_upload::sanitize($destpath, $filenames[$n]);
				$destination 	= $destpath . $filename;
				
				// Check for file already added by import task and do not re-add the file
				if ( $is_importcsv && isset($imported_files[$source]) )
				{
					$file_ids[$filename] = $imported_files[$source];
					continue;
				}
				
				// Copy or move the file
				$success = $keep  ?  JFile::copy($source, $destination)  :  JFile::move($source, $destination) ;
				if ($success)
				{
					$filesize = filesize($destination);
					
					$obj = new stdClass();
					$obj->filename    = $filename;
					$obj->altname     = $filename;

					$obj->url         = 0;
					$obj->secure      = $secure;
					$obj->ext         = $ext;

					$obj->description = $filedesc;
					$obj->language    = strlen($filelang) ? $filelang : '*';
					$obj->access      = strlen($fileaccess) ? $fileaccess : 1;

					$obj->hits        = 0;
					$obj->size        = $filesize;
					$obj->uploaded    = JFactory::getDate('now')->toSql();
					$obj->uploaded_by = $user->get('id');

					// Add the record to the DB
					$db->insertObject('#__flexicontent_files', $obj);
					$file_ids[$filename] = $db->insertid();
					
					// Add file ID to files imported by import task
					if ( $is_importcsv )  $imported_files[$source] = $file_ids[$filename];
					
					$added[] = $filenames[$n];
				}
			}

			if (count($added))
			{
				$app->enqueueMessage(JText::sprintf( 'FLEXI_FILES_COPIED_SUCCESS', count($added)), 'message');
			}
			if (count($excluded))
			{
				$app->enqueueMessage(JText::sprintf( 'FLEXI_FILES_EXCLUDED_WARNING', count($excluded) ) .' : '.implode(', ', $excluded), 'warning');
			}
		}

		else
		{
			$this->exitHttpHead = array( 0 => array('status' => '400 Bad Request') );
			$this->exitMessages = array( 0 => array('error' => 'FLEXI_WARN_NO_FILES_IN_DIR') );
			$this->exitLogTexts = array();
			$this->exitSuccess  = false;

			return $this->terminate($file_ids, $exitMessages);
		}

		// Terminate with proper messaging
		$this->exitHttpHead = array( 0 => array('status' => '201 Created') );
		$this->exitMessages = array( 0 => array('message' => 'FLEXI_FILE_ADD_SUCCESS') );
		$this->exitLogTexts = array();
		$this->exitSuccess  = true;

		return $this->terminate($file_ids, $exitMessages);
	}


	/**
	 * Logic for editing a file
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function edit()
	{	
		require_once (JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'models'.DS.'file.php');

		$app    = JFactory::getApplication();
		$user		= JFactory::getUser();
		$session  = JFactory::getSession();
		$document = JFactory::getDocument();

		$this->input->set('view', 'file');
		$this->input->set('hidemainmenu', 1);

		// Get/Create the view
		$viewType   = $document->getType();
		$viewName   = $this->input->get('view', $this->default_view, 'cmd');
		$viewLayout = $this->input->get('layout', 'default', 'string');
		$view = $this->getView($viewName, $viewType, '', array('base_path' => $this->basePath, 'layout' => $viewLayout));

		// Get/Create the model
		$model	= $this->getModel('file');
		$file		= $model->getFile();

		// Push the model into the view (as default), later we will call the view display method instead of calling parent's display task, because it will create a 2nd model instance !!
		$view->setModel($model, true);
		$view->document = $document;

		// calculate access
		$canedit = $user->authorise('flexicontent.editfile', 'com_flexicontent');
		$caneditown = $user->authorise('flexicontent.editownfile', 'com_flexicontent') && $file->uploaded_by == $user->get('id');
		$is_authorised = $canedit || $caneditown;
		
		// check access
		if ( !$is_authorised )
		{
			$app->setHeader('status', '403 Forbidden', true);
			$this->setRedirect($this->returnURL, JText::_('FLEXI_ALERTNOTAUTH_TASK'), 'error');
			return;
		}
		
		// Check if record is checked out by other editor
		if ( $model->isCheckedOut( $user->get('id') ) )
		{
			$app->setHeader('status', '400 Bad Request', true);
			$this->setRedirect($this->returnURL, JText::_('FLEXI_EDITED_BY_ANOTHER_ADMIN'), 'warning');
			return;
		}
		
		// Checkout the record and proceed to edit form
		if ( !$model->checkout() )
		{
			$app->setHeader('status', '400 Bad Request', true);
			$this->setRedirect($this->returnURL, $model->getError(), 'error');
			return;
		}

		$view->display();
	}
	
	/**
	 * Logic to delete files
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function remove()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		
		//require_once (JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'models'.DS.'file.php');
		$app    = JFactory::getApplication();
		$user   = JFactory::getUser();
		$db     = JFactory::getDBO();

		$fieldid    = $this->input->get('fieldid', 0, 'int');
		$u_item_id  = $this->input->get('u_item_id', 0, 'cmd');
		$file_mode  = $this->input->get('folder_mode', 0, 'int') ? 'folder_mode' : 'db_mode';

		if ($file_mode == 'folder_mode')
		{
			$filename = rawurldecode( $this->input->get('filename', '', 'cmd') );
			// Default 'CMD' filtering is maybe too aggressive, but allowing UTF8 will not work in all filesystems, so we do not allow
			//$filename_original = iconv(mb_detect_encoding($filename, mb_detect_order(), true), "UTF-8", $filename);

			$db->setQuery("SELECT * FROM #__flexicontent_fields WHERE id='".$fieldid."'");
			$field = $db->loadObject();
			$field->parameters = new JRegistry($field->attribs);
			$field->item_id = $u_item_id;
			
			$result = FLEXIUtilities::call_FC_Field_Func($field->field_type, 'removeOriginalFile', array( &$field, $filename ) );
			
			if ( !$result ) {
				JError::raiseWarning(100, JText::_( 'FLEXI_UNABLE_TO_CLEANUP_ORIGINAL_FILE' ) .": ". $path);
				$msg = '';
			} else {
				$msg = JText::_( 'FLEXI_FILES_DELETED' );
			}

			// TODO revise, this after implementing multi-file deletion for fileselement 'folder' mode
			$app->setUserState('delfilename', $filename);
			$this->setRedirect( $_SERVER['HTTP_REFERER'], $msg );
			return;
		}
		
		// calculate access
		$candelete = $user->authorise('flexicontent.deletefile', 'com_flexicontent');
		$candeleteown = $user->authorise('flexicontent.deleteownfile', 'com_flexicontent');
		$is_authorised = $candelete || $candeleteown;
		
		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_('FLEXI_ALERTNOTAUTH_TASK') );
			$this->setRedirect( $this->returnURL, '');
			return;
		}

		$cid = $this->input->get('cid', array(), 'array');
		JArrayHelper::toInteger($cid, array());

		if (!is_array( $cid ) || count( $cid ) < 1) {
			$msg = '';
			JError::raiseWarning(500, JText::_( 'FLEXI_SELECT_ITEM_DELETE' ) );
		}
		
		else {
			$msg = '';
			
			$db->setQuery( 'SELECT * FROM #__flexicontent_files WHERE id IN ('.implode(',', $cid).')' );
			$files = $db->loadObjectList('id');
			$cid = array_keys($files);
			
			$model = $this->getModel('filemanager');
			$deletable = $model->getDeletable($cid);
			
			if (count($cid) != count($deletable))
			{
				$_del = array_flip($deletable);
				$inuse_files = array();
				foreach ($files as $_id => $file) if ( !isset($_del[$_id]) )  $inuse_files[] = $file->filename_original ? $file->filename_original : $file->filename;
				$app->enqueueMessage(JText::_( 'FLEXI_CANNOT_REMOVE_FILES_IN_USE' ) .': '. implode(', ', $inuse_files), 'warning');
				$cid = $deletable;
			}
			
			$allowed_files = array();
			$denied_files = array();
			foreach($cid as $_id) {
				if ( !isset($files[$_id]) ) continue;
				$filename = $files[$_id]->filename_original ? $files[$_id]->filename_original : $files[$_id]->filename;
				if ($candelete || $files[$_id]->uploaded_by == $user->get('id'))
					$allowed_files[$_id] = $filename;
				else
					$denied_files[$_id] = $filename;
			}
			if ( count($denied_files) ) {
				$app->enqueueMessage( ' You are not allowed to delete files: '. implode(', ', $denied_files), 'warning');
			}
			$allowed_cid = array_keys($allowed_files);
			
			if (count($allowed_cid) && !$model->delete($allowed_cid)) {
				$msg = JText::_( 'FLEXI_OPERATION_FAILED' ).' : '.$model->getError();
				if (FLEXI_J16GE) throw new Exception($msg, 500); else JError::raiseError(500, $msg);
			}
			
			if (count($allowed_cid)) $msg .= count($allowed_cid).' '.JText::_( 'FLEXI_FILES_DELETED' );
			
			$cache = JFactory::getCache('com_flexicontent');
			$cache->clean();
		}
		
		$this->setRedirect( $this->returnURL, $msg );
	}


	/**
	 * Logic for saving altered file data
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function save()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		
		require_once (JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'models'.DS.'file.php');
		$app    = JFactory::getApplication();
		$user		= JFactory::getUser();
		$model	= $this->getModel('file');
		$file		= $model->getFile();

		$data = $this->input->post->getArray();  // Default filtering will remove HTML
		$data['description'] = flexicontent_html::dataFilter($data['description'], 32000, 'STRING', 0);  // Limit description to 32000 characters
		$data['hits'] = (int) $data['hits'];

		// calculate access
		$canedit = $user->authorise('flexicontent.editfile', 'com_flexicontent');
		$caneditown = $user->authorise('flexicontent.editownfile', 'com_flexicontent') && $file->uploaded_by == $user->get('id');
		$is_authorised = $canedit || $caneditown;

		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_('FLEXI_ALERTNOTAUTH_TASK') );
			$this->setRedirect( 'index.php?option=com_flexicontent&view=filemanager', '');
			return;
		}

		$data['secure'] = $data['secure'] ? 1 : 0;   // only allow 1 or 0
		$data['url'] = $data['url'] ? 1 : 0;   // only allow 1 or 0

		// CASE local file
		if (!$data['url'])
		{
			$path = $data['secure'] ? COM_FLEXICONTENT_FILEPATH.DS : COM_FLEXICONTENT_MEDIAPATH.DS;  // JPATH_ROOT . DS . <media_path | file_path> . DS
			$file_path = JPath::clean($path . $data['filename']);

			// Get file size from filesystem (local file)
			$data['size'] = file_exists($file_path) ? filesize($file_path) : 0;
		}

		// CASE file URL
		else
		{
		  // Validate file URL
			$data['filename_original'] = flexicontent_html::dataFilter($data['filename_original'], 4000, 'URL', 0);  // Clean bad text/html

			if ( empty($data['size']) )
			{
				$data['size'] = $this->get_file_size_from_url($data['filename_original']);
				if ($data['size'] < 0 || empty($data['size']))
				{
					$data['size'] = 0;
				}
			}
			else
			{
				// Get file size from submitted field (file URL), set to zero if no size unit specified
				$arr_sizes = array('KBs'=>1024, 'MBs'=>(1024*1024), 'GBs'=>(1024*1024*1024));
				$size_unit = (int) @ $arr_sizes[$data['size_unit']];
				$data['size'] = ((int)$data['size']) * $size_unit;
			}
		}

		// Validate access level exists (set to public otherwise)
		$data['access'] = flexicontent_html::dataFilter($data['access'], 11, 'ACCESSLEVEL', 0);

		if ($model->store($data))
		{
			switch ($this->task)
			{
				case 'apply' :
					$edit_task = "task=filemanager.edit";
					$link = 'index.php?option=com_flexicontent&'.$edit_task.'&cid[]='.(int) $model->get('id');
					break;

				default :
					$link = 'index.php?option=com_flexicontent&view=filemanager';
					break;
			}
			$msg = JText::_( 'FLEXI_FILE_SAVED' );
			$model->checkin();
			
			$cache = JFactory::getCache('com_flexicontent');
			$cache->clean();
		}
		else {
			$msg = JText::_( 'FLEXI_ERROR_SAVING_FILENAME' ).' : '.$model->getError();
			if (FLEXI_J16GE) throw new Exception($msg, 500); else JError::raiseError(500, $msg);
		}

		$this->setRedirect($link, $msg);
	}


	/**
	 * logic for cancel an action
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function cancel()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		// DO not check access for checkin the item, method checkin() will do it
		$id = $this->input->get->post->get('id', 0, 'int');
		$this->input->set('cid', $id);

		$this->checkin();
	}


	/**
	 * Check in a record
	 *
	 * @since	1.5
	 */
	function checkin()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		// Get/Create the model
		$model	= $this->getModel('file');
		$file		= $model->getFile();
		$user   = JFactory::getUser();

		// calculate access
		$canedit = $user->authorise('flexicontent.editfile', 'com_flexicontent');
		$caneditown = $user->authorise('flexicontent.editownfile', 'com_flexicontent') && $file->uploaded_by == $user->get('id');
		$is_authorised = $canedit || $caneditown;

		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_('FLEXI_ALERTNOTAUTH_TASK') );
			$this->setRedirect( $this->returnURL, '');
			return;
		}

		$tbl = 'flexicontent_files';
		$redirect_url = 'index.php?option=com_flexicontent&view=filemanager';
		flexicontent_db::checkin($tbl, $redirect_url, $this);
	}


	/**
	 * Logic to publish a file, this WRAPPER for changeState method
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function publish()
	{
		$this->changeState(1);   // Security checks are done by the called method
	}


	/**
	 * Logic to unpublish a file, this WRAPPER for changeState method
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function unpublish()
	{
		$this->changeState(0);   // Security checks are done by the called method
	}


	/**
	 * Logic to change publication state of files
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function changeState($state)
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		
		//require_once (JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'models'.DS.'file.php');
		$app   = JFactory::getApplication();
		$user  = JFactory::getUser();
		$db    = JFactory::getDBO();
		
		// calculate access
		$canpublish = $user->authorise('flexicontent.publishfile', 'com_flexicontent');
		$canpublishown = $user->authorise('flexicontent.publishownfile', 'com_flexicontent');
		$is_authorised = $canpublish || $canpublishown;
		
		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_('FLEXI_ALERTNOTAUTH_TASK') );
			$this->setRedirect( $this->returnURL, '');
			return;
		}

		$cid = $this->input->get('cid', array(), 'array');
		JArrayHelper::toInteger($cid, array());

		if (!is_array( $cid ) || count( $cid ) < 1) {
			$msg = '';
			JError::raiseWarning(500, JText::_( $state ? 'FLEXI_SELECT_ITEM_PUBLISH' : 'FLEXI_SELECT_ITEM_UNPUBLISH' ) );
		} else {
			
			$db->setQuery( 'SELECT * FROM #__flexicontent_files WHERE id IN ('.implode(',', $cid).')' );
			$files = $db->loadObjectList('id');
			$cid = array_keys($files);
			
			$model = $this->getModel('filemanager');
			
			$msg = '';
			$allowed_files = array();
			$denied_files = array();
			foreach($cid as $_id) {
				if ( !isset($files[$_id]) ) continue;
				$filename = $files[$_id]->filename_original ? $files[$_id]->filename_original : $files[$_id]->filename;
				if ($canpublish || $files[$_id]->uploaded_by == $user->get('id'))
					$allowed_files[$_id] = $filename;
				else
					$denied_files[$_id] = $filename;
			}
			if ( count($denied_files) ) {
				$app->enqueueMessage(' You are not allowed to change state of files: '. implode(', ', $denied_files), 'warning');
			}
			$allowed_cid = array_keys($allowed_files);
			
			if (count($allowed_cid) && !$model->publish($allowed_cid, $state)) {
				$msg = JText::_( 'FLEXI_OPERATION_FAILED' ).' : '.$model->getError();
				if (FLEXI_J16GE) throw new Exception($msg, 500); else JError::raiseError(500, $msg);
			}
			
			if (count($allowed_cid)) $msg .= JText::_( $state ? 'FLEXI_PUBLISHED' : 'FLEXI_UNPUBLISHED' ) . ': '. implode(', ', $allowed_files);
			
			$cache = JFactory::getCache('com_flexicontent');
			$cache->clean();
		}
		
		$this->setRedirect( $this->returnURL, $msg);
	}
	
	
	/**
	 * Logic to set the access level of the Fields
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function access()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		
		$user  = JFactory::getUser();
		$model = $this->getModel('filemanager');
		$cid = $this->input->get('cid', array(0), 'array');
		JArrayHelper::toInteger($cid, array(0));
		
		$file_id = (int)$cid[0];
		$row = JTable::getInstance('flexicontent_files', '');
		$row->load($file_id);
		
		// calculate access
		$perms = FlexicontentHelperPerm::getPerm();
		$is_authorised = $perms->CanFiles && ($perms->CanViewAllFiles || $user->id == $row->uploaded_by);
		
		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_('FLEXI_ALERTNOTAUTH_TASK') );
			$this->setRedirect( $this->returnURL, '');
			return;
		}
		
		$accesses = $this->input->get('access', array(0), 'array');
		JArrayHelper::toInteger($accesses);
		$access = $accesses[$file_id];
		
		if(!$model->saveaccess( $file_id, $access )) {
			$msg = JText::_( 'FLEXI_OPERATION_FAILED' );
			JError::raiseWarning( 500, $model->getError() );
		} else {
			$msg = '';
			$cache = JFactory::getCache('com_flexicontent');
			$cache->clean();
		}
		
		$this->setRedirect($this->returnURL, $msg);
	}



	// **************
	// Helper methods
	// **************


	/*
	 * Restructure a FILES array for easier usage
	 */
	function refactorFilesArray(&$f)
	{
		if ($this->input->get('task', '', 'cmd') == __FUNCTION__) die(__FUNCTION__ . ' : direct call not allowed');

		if ( empty($f['name']) || !is_array($f['name']) )  return $f; // nothing more to do
		
		$level0_keys = array_keys($f);
		$level1_keys = array_keys($f['name']);
		
		// Swap indexLevel_N with indexLeveL_N+1, until there are no more inner arrays
		foreach ($level0_keys  as  $i)  // level0_keys are: name, type, tmp_name, error, size
		{
			foreach ($level1_keys  as  $k1)  // level1_keys are: the indexes of ... file['name']
			{
				$r1[$k1][$i] = $f[$i][$k1];
				if ( !is_array($r1[$k1][$i]) ) continue;
				
				foreach(array_keys($r1[$k1][$i])  as  $k2)
				{
					$r2[$k1][$k2][$i] = $r1[$k1][$i][$k2];
					if ( !is_array($r2[$k1][$k2][$i]) ) continue;
					
					foreach(array_keys($r2[$k1][$k2][$i])  as  $k3)
					{
						$r3[$k1][$k2][$k3][$i] = $r2[$k1][$k2][$i][$k3];
					}
				}
			}
		}
		
		if (isset($r3))
			return $r3;
		else if (isset($r2))
			return $r2;
		else
			return $r1;
	}


	/*
	 * Terminate
	 * - either returning file id(s)
	 * - or setting HTTP header and doing a JSON response with data or error message
	 * - or setting HTTP header and enqueuing an error/success message and doing a redirect
	 */
	function terminate($exitData = null, & $exitMessages = null)
	{
		if ($this->input->get('task', '', 'cmd') == __FUNCTION__) die(__FUNCTION__ . ' : direct call not allowed');

		// CASE 1: interactive mode
		if ($this->runMode=='interactive')
		{
			// Return messages to the caller
			$exitMessages = array();
			foreach	($this->exitMessages as $msg)
			{
				$exitMessages[] = array(key($msg) => JText::_(reset($msg)));
			}

			return $exitData;
		}


		// Standalone modes, set HTTP headers, also get value of 'status' header
		$app = JFactory::getApplication();
		$httpStatus = $this->exitSuccess ? '200 OK' : '400 Bad Request';

		foreach	($this->exitHttpHead as $header)
		{
			if (key($header) == 'status')
			{
				$httpStatus = key($header);
			}
			$app->setHeader(key($header), reset($header), true);
		}


		// CASE 2: standalone mode with JSON response:  Set HTTP headers, and create a jsonrpc response 
		if ($this->format == 'json')
		{
			if ( !empty($this->exitLogTexts) )
			{
				$log_filename = 'filemanager_upload_'.($user->id).'.php';
				jimport('joomla.log.log');
				JLog::addLogger(
					array(
						'text_file' => $log_filename,  // Sets the format of each line
            'text_entry_format' => '{DATETIME} {PRIORITY} {MESSAGE}'  // Sets the format of each line
					),
					JLog::ALL,  // Sets messages of all log levels to be sent to the file
					array('com_flexicontent.filemanager')  // category of logged messages
				);
				foreach	($this->exitLogTexts as $msg)
				{
					JLog::add(reset($msg), key($msg), 'com_flexicontent.filemanager');
				}
			}

			$msg_text_all = '';
			foreach	($this->exitMessages as $msg)
			{
				$msg_text_all .= ' <br/> ' . JText::_(reset($msg), true);
			}

			if ($this->exitSuccess)
				jexit('{"jsonrpc" : "2.0", "result" : "'.$msg_text_all.'", "id" : "id"}');
			else
				jexit('{"jsonrpc" : "2.0", "error" : {"code": '.$httpStatus.', "message": "'.$msg_text_all.'"}, "id" : "id"}');
		}


		// CASE 3: standalone mode with HTML response:  Set HTTP headers, enqueue messages and optionally redirect
		foreach	($this->exitMessages as $msg)
		{
			$app->enqueueMessage(JText::_(reset($msg)), key($msg));
		}

		// Redirect or return 
		if ($this->returnURL)
			$app->redirect($this->returnURL . ($httpStatus == '403 Forbidden' ? '' : '&'. JSession::getFormToken() . '=1'));
		else
			return ! $this->exitSuccess ? false : null;
	}


	/**
	 * Returns the size of a file without downloading it, or -1 if the file size could not be determined.
	 *
	 * @param $url - The location of the remote file to download. Cannot be null or empty.
	 *
	 * @return The size of the file referenced by $url,
	 * or -1 if the size could not be determined
	 * or -999 if there was an error
	 */
	function get_file_size_from_url($url)
	{
		if ($this->input->get('task', '', 'cmd') == __FUNCTION__) die(__FUNCTION__ . ' : direct call not allowed');

		try {
			$headers = get_headers($url, 1);

			// Follow the Location headers until the actual file URL is known
			while (isset($headers['Location']))
			{
				$url = $headers['Location'];
				$headers = get_headers($url, 1);
			}
		}
		catch (RuntimeException $e) {
			return -999;  // indicate a fatal error
		}

		// Get file size
		$filesize = isset($headers["Content-Length"]) ? $headers["Content-Length"] : -1;  // indicate that the size could not be determined
		return $filesize;
	}


	/**
	 * Set credentials for using FTP layer for file handling
	 */
	function ftpValidate()
	{
		if ($this->input->get('task', '', 'cmd') == __FUNCTION__) die(__FUNCTION__ . ' : direct call not allowed');

		// Set FTP credentials, if given
		jimport('joomla.client.helper');
		JClientHelper::setCredentialsFromRequest('ftp');
	}
}