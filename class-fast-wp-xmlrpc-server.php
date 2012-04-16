<?php

class fast_wp_xmlrpc_server extends wp_xmlrpc_server {

	function fast_wp_xmlrpc_server( ) {
 		self::dont_log_me('>>> fast_wp_xmlrpc_server');
 		parent::__construct(); //For backwards compatibility, if PHP 5 cannot find a __construct() function for a given class, it will search for the old-style constructor function, by the name of the class.
		$this->test_raw_data(); 		
		self::dont_log_me('<<< fast_wp_xmlrpc_server');
	}
	
    function serve($data = false)
    {
    	self::dont_log_me('>>> fast_wp_xmlrpc_server->serve');
		$this->test_raw_data();
        if (!$data) {
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            	header('Content-Type: text/plain'); // merged from WP #9093
                die('XML-RPC server accepts POST requests only.');
            }
        }
        $this->message = new IXR_Message2( );
        if (!$this->message->parse()) {
            $this->error(-32700, 'parse error. not well formed');
        }
        if ($this->message->messageType != 'methodCall') {
            $this->error(-32600, 'server error. invalid xml-rpc. not conforming to spec. Request must be a methodCall');
        }
        $result = $this->call($this->message->methodName, $this->message->params);

        // Is the result an error?
        if (is_a($result, 'IXR_Error')) {
            $this->error($result);
        }

        // Encode the result
        $r = new IXR_Value($result);
        $resultxml = $r->getXml();

        // Create the XML
	    $xml = <<<EOD
<methodResponse>
  <params>
    <param>
      <value>
      $resultxml
      </value>
    </param>
  </params>
</methodResponse>

EOD;
    //  dont_log_me( $xml );
      self::dont_log_me('<<< fast_wp_xmlrpc_server->serve');
      // Send it
      $this->output($xml);
    }


	function mw_newMediaObject($args) {
		global $wpdb;

		$blog_ID     = (int) $args[0];
		$username  = $wpdb->escape($args[1]);
		$password   = $wpdb->escape($args[2]);
		$data        = $args[3];

		$name = sanitize_file_name( $data['name'] );
		$type = $data['type'];
		//$bits = $data['bits'];
		$filePath = $data['bits'];

		//logIO('O', '(MW) Received '.strlen($bits).' bytes');

		if ( !$user = $this->login($username, $password) )
			return $this->error;

		do_action('xmlrpc_call', 'metaWeblog.newMediaObject');

		if ( !current_user_can('upload_files') ) {
			logIO('O', '(MW) User does not have upload_files capability');
			$this->error = new IXR_Error(401, __('You are not allowed to upload files to this site.'));
			return $this->error;
		}

		if ( $upload_err = apply_filters( "pre_upload_error", false ) )
			return new IXR_Error(500, $upload_err);

		if ( !empty($data["overwrite"]) && ($data["overwrite"] == true) ) {
			// Get postmeta info on the object.
			$old_file = $wpdb->get_row("
				SELECT ID
				FROM {$wpdb->posts}
				WHERE post_title = '{$name}'
					AND post_type = 'attachment'
			");

			// Delete previous file.
			wp_delete_attachment($old_file->ID);

			// Make sure the new name is different by pre-pending the
			// previous post id.
			$filename = preg_replace("/^wpid\d+-/", "", $name);
			$name = "wpid{$old_file->ID}-{$filename}";
		}

		//$upload = wp_upload_bits($name, NULL, $bits);
		$upload = $this->xmlrpc_wp_upload_file($name, NULL, $filePath);
		if ( ! empty($upload['error']) ) {
			$errorString = sprintf(__('Could not write file %1$s (%2$s)'), $name, $upload['error']);
			logIO('O', '(MW) ' . $errorString);
			return new IXR_Error(500, $errorString);
		}
		// Construct the attachment array
		// attach to post_id 0
		$post_id = 0;
		$attachment = array(
			'post_title' => $name,
			'post_content' => '',
			'post_type' => 'attachment',
			'post_parent' => $post_id,
			'post_mime_type' => $type,
			'guid' => $upload[ 'url' ]
		);

		// Save the data
		$id = wp_insert_attachment( $attachment, $upload[ 'file' ], $post_id );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

		return apply_filters( 'wp_handle_upload', array( 'file' => $name, 'url' => $upload[ 'url' ], 'type' => $type ), 'upload' );
	}
	
	function xmlrpc_wp_upload_file( $name, $deprecated, $bits, $time = null ) {
			
		if ( !empty( $deprecated ) )
			_deprecated_argument( __FUNCTION__, '2.0' );
	
		if ( empty( $name ) )
			return array( 'error' => __( 'Empty filename' ) );
	
		$wp_filetype = wp_check_filetype( $name );
		if ( !$wp_filetype['ext'] )
			return array( 'error' => __( 'Invalid file type' ) );
	
		$upload = wp_upload_dir( $time );
	
		if ( $upload['error'] !== false )
			return $upload;
	
	/*	$upload_bits_error = apply_filters( 'wp_upload_bits', array( 'name' => $name, 'bits' => $bits, 'time' => $time ) );
		if ( !is_array( $upload_bits_error ) ) {
			$upload[ 'error' ] = $upload_bits_error;
			return $upload;
		}
	*/
		$filename = wp_unique_filename( $upload['path'], $name );
	
		$new_file = $upload['path'] . "/$filename";
		if ( ! wp_mkdir_p( dirname( $new_file ) ) ) {
			$message = sprintf( __( 'Unable to create directory %s. Is its parent directory writable by the server?' ), dirname( $new_file ) );
			return array( 'error' => $message );
		}
	
		$ifp = @ fopen( $new_file, 'wb' );
		if ( ! $ifp )
			return array( 'error' => sprintf( __( 'Could not write file %s' ), $new_file ) );
	
	
			$chunkSize = 24000;
			$src = fopen($bits, 'rb');
			while (!feof($src)) {
				fwrite($ifp, fread($src, $chunkSize));
			}
			fclose($ifp);
			fclose($src);
			
			clearstatcache();
	
		// Set correct file permissions
		$stat = @ stat( dirname( $new_file ) );
		$perms = $stat['mode'] & 0007777;
		$perms = $perms & 0000666;
		@ chmod( $new_file, $perms );
		@unlink($bits);
		clearstatcache();
		// Compute the URL
		$url = $upload['url'] . "/$filename";
	
		return array( 'file' => $new_file, 'url' => $url, 'error' => false );
	}
	
    /* Utility methods */
	function test_raw_data( ) {
		global $HTTP_RAW_POST_DATA;
		if (empty($HTTP_RAW_POST_DATA)) {
			self::dont_log_me('raw data is NOT set. YEAHH!');
		} else {
			// even though phpinfo() shows 'always_populate_raw_post_data' Off, $HTTP_RAW_POST_DATA is defined.
			self::dont_log_me('raw data already set. WTF!');
		}
	}
	
	static function convert($size)
	{
		$unit=array('b','kb','mb','gb','tb','pb');
		return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
	}

	static function dont_log_me($msg) {
		if ( defined( 'FAST_XMLRPC_ENABLE_LOG' ) && constant( 'FAST_XMLRPC_ENABLE_LOG' ) == true ) {
			$fp = fopen(FAST_XMLRPC_PLUGIN_DIR."/log.txt","a+");
			$date = gmdate("Y-m-d H:i:s ");
			$memory_usage = self::convert(memory_get_peak_usage(TRUE));
			fwrite($fp, "\n".$date.$msg.' '.$memory_usage);
			fclose($fp);
		}
		return true;
	}
}

class IXR_Message2 extends IXR_Message  
{

	var $tmpFilePointer = null;
	var $tmpFileName = null;
	
    function IXR_Message2( )
    {
    	$this->message = false;
    }

    function parse()
    {

        $this->_parser = xml_parser_create();
        // Set XML parser to take the case of tags in to account
        xml_parser_set_option($this->_parser, XML_OPTION_CASE_FOLDING, false);
        // Set XML parser callback functions
        xml_set_object($this->_parser, $this);
        xml_set_element_handler($this->_parser, 'tag_open', 'tag_close');
        xml_set_character_data_handler($this->_parser, 'cdata');
        $chunk_size = 262144; // 256Kb, parse in chunks to avoid the RAM usage on very large messages
        $final = false;
        $first_chunk = true;
        $input = fopen('php://input', 'r');
		while ( ! feof( $input ) ) {
			$part = fread( $input, $chunk_size);
			//dont_log_me('Chunk of data: '.$part);
			// first remove the XML declaration
			if ( $first_chunk ) {
				//dont_log_me('First chunk before: '.$part);
		        $header = preg_replace( '/<\?xml.*?\?'.'>/', '', substr( $part, 0, 100 ), 1);
		        $part = substr_replace($part, $header, 0, 100);
		        if (trim($part) == '') {
		            return false;
		        }
		        $first_chunk = false;
		        //dont_log_me('First chunk after: '.$part);
			}
		    if ( ! xml_parse( $this->_parser, $part, $final ) ) {
                return false;
            }
            if ($final) {
                break;
            }    		 
		}
		fclose($input);
        xml_parser_free($this->_parser);

        // Grab the error messages, if any
        if ($this->messageType == 'fault') {
            $this->faultCode = $this->params[0]['faultCode'];
            $this->faultString = $this->params[0]['faultString'];
        }
        return true;
    }


    function tag_open($parser, $tag, $attr)
    {
        $this->_currentTagContents = '';

        //Create the file that should contain the base decoded data
        if ( $tag == 'base64' ) {
	        //close the file pointer and get a new reference to the file
	        if( $this->tmpFilePointer != null )
	        	fclose($this->tmpFilePointer);
	        $this->tmpFileName = null;
	        // tmpfile() ??
	        $this->tmpFileName = constant( 'FAST_XMLRPC_PLUGIN_CACHE_DIR' ) . '/'.mt_rand().'.tmp';
			$this->tmpFilePointer = fopen( $this->tmpFileName, 'wb' );
			stream_filter_append($this->tmpFilePointer,'convert.base64-decode');
        }
        
        $this->currentTag = $tag;
        switch($tag) {
            case 'methodCall':
            case 'methodResponse':
            case 'fault':
                $this->messageType = $tag;
                break;
                /* Deal with stacks of arrays and structs */
            case 'data':    // data is to all intents and puposes more interesting than array
                $this->_arraystructstypes[] = 'array';
                $this->_arraystructs[] = array();
                break;
            case 'struct':
                $this->_arraystructstypes[] = 'struct';
                $this->_arraystructs[] = array();
                break;
        }
    }
    
    function cdata($parser, $cdata)
    {
    	//dont_log_me( $this->currentTag );
	    if ( $this->currentTag == 'base64' ) {
    	    fwrite( $this->tmpFilePointer, $cdata );
	    } else {
	    	$this->_currentTagContents .= $cdata;
	    }
    }
     
    function tag_close($parser, $tag)
    {
        $valueFlag = false;
        switch($tag) {
            case 'int':
            case 'i4':
                $value = (int)trim($this->_currentTagContents);
                $valueFlag = true;
                break;
            case 'double':
                $value = (double)trim($this->_currentTagContents);
                $valueFlag = true;
                break;
            case 'string':
                $value = (string)trim($this->_currentTagContents);
                $valueFlag = true;
                break;
            case 'dateTime.iso8601':
                $value = new IXR_Date(trim($this->_currentTagContents));
                $valueFlag = true;
                break;
            case 'value':
                // "If no type is indicated, the type is string."
                if (trim($this->_currentTagContents) != '') {
                    $value = (string)$this->_currentTagContents;
                    $valueFlag = true;
                }
                break;
            case 'boolean':
                $value = (boolean)trim($this->_currentTagContents);
                $valueFlag = true;
                break;
            case 'base64':
               /* $value = base64_decode($this->_currentTagContents);
                $valueFlag = true;*/
            	fclose( $this->tmpFilePointer );
            	$value = $this->tmpFileName;
            	$valueFlag = true;
            	$this->_currentTagContents = '';
                break;
                /* Deal with stacks of arrays and structs */
            case 'data':
            case 'struct':
                $value = array_pop($this->_arraystructs);
                array_pop($this->_arraystructstypes);
                $valueFlag = true;
                break;
            case 'member':
                array_pop($this->_currentStructName);
                break;
            case 'name':
                $this->_currentStructName[] = trim($this->_currentTagContents);
                break;
            case 'methodName':
                $this->methodName = trim($this->_currentTagContents);
                break;
        }

        if ($valueFlag) {
            if (count($this->_arraystructs) > 0) {
                // Add value to struct or array
                if ($this->_arraystructstypes[count($this->_arraystructstypes)-1] == 'struct') {
                    // Add to struct
                    $this->_arraystructs[count($this->_arraystructs)-1][$this->_currentStructName[count($this->_currentStructName)-1]] = $value;
                } else {
                    // Add to array
                    $this->_arraystructs[count($this->_arraystructs)-1][] = $value;
                }
            } else {
                // Just add as a paramater
                $this->params[] = $value;
            }
        }
        $this->_currentTagContents = '';
    }
}
?>