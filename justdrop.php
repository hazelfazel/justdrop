<?php
date_default_timezone_set('Europe/Berlin');
srand( (double) microtime() * 3981243 );

// Specify a greeting and title for the upload page
$GREETING = 'Select a file and press Upload.';
$TITLE = 'Just Drop - Sharing files made easy';

// You shall specify the URL explicitly, this is just for debugging
$URL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
//$URL = "https://YOURDOMAIN/justdrop.php";

// Please specify your own session key
$SESSION_KEY  = hash('sha256', 'qwertzuiefvbi7253jgsdfopasdfghjklyxcvbnm');

// Enable if you'd like to use the original filename
// You should not, if used by external strangers
$USE_ORIGINAL_FILENAME = true;

// If you would like to receive an email notification on
// successful upload setup Recipients here
$MAIL_NOTIFICATION = false;
$MAIL_FROM = 'upload-script@your-domain.com';
$MAIL_TO = 'your-email-address@your-domain.com';
$MAIL_SUBJECT = '📥 File uploaded';
$MAIL_CONTENT_HEADER = "Hi there!\r\n\r\nA new file was uploaded. You'll find it at: ";
$MAIL_CONTENT_FOOTER = "Greetings 🦄";

// maximal file size we would like to support for upload
$MAXFILESIZE = 1024*1024*1500; // max. 1.500 Gig

// if kill bit is set (>0) then delete script after upload
$KILL_BIT = 0;

// number of uploads for this script; -1 = infinite
$REMAINING_UPLOADS = -1;

// password required, if password is set. MUST be SHA256ed with $SESSION_KEY.
// Example: loremipsum . $SESSION_KEY
$PASSWORD = '821b53f0a05efb34b5e53fa843428e1a6f5dcea02a31a3f94da1e0a3702499a7';

// link valid until: YYYY-MM-DD
$VAILD_UNTIL = '';

/*
		Do not change anything below here if you do not know what you are doing
*/

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$GENERATOR = 'JustDrop';
$VERSION_NO = '0.5.83';
$VERSION_TITLE = 'Shizzle';

$DEBUG = false;
if ($DEBUG === true) {
	$GREETING = $GREETING . ' [DEBUG]';
}

// set up chunk size. Do not use the maximum allowed size
// as we need some additionals bytes for base64 and control messages
// $CHUNK_SIZE = file_upload_max_size() / 3;
$CHUNK_SIZE = 1024*512; // 512KBs

// allowed requests
$REQUESTS = array(			'chunked_upload_init',
							'chunked_upload_data',
							'chunked_upload_close',
						);

// bad filename chars we'd like to filter out
$BADCHARS = array(chr(0), chr(1), chr(2), chr(3), chr(4), chr(5), chr(6), chr(7), chr(8), chr(9), chr(10),
    chr(11), chr(12), chr(13), chr(14), chr(15), chr(16), chr(17), chr(18), chr(19), chr(20),
    chr(21), chr(22), chr(23), chr(24), chr(25), chr(26), chr(27), chr(28), chr(29), chr(30),
    chr(31), chr(127), '..', '~', '|', '°', '^', '\\', '/', '\'', ':', '*', '?', '"', '<', '>');

// harmful file extensions we'd like to filter out
$HARMFUL_EXTENSIONS = '/\.(php|htm|html|com|exe|dll|sys|scr|ocx|cmd|bat|asp|jsp|cgi|sh|js|vbs|vbe|jse|java|asp|wsc|sct|wsf|wsh|msc|pls|py|hlp|hta|ps|cs|wsc|sct|py|pl|sql|reg)$/';
$EXTENSION_MIMICS_TYPICAL_DOCUMENT_TYPE = '/\.(zip|tar|cab|msi|7z|rar|inf|ini|doc|dot|docx|docm|rtf|odt|psw|pwd|html|htm|hta|url|lnk|xls|xlt|xltx|xltm|xlsx|xlsm|dbf|dif|ppt|pptx|pdf|pdfx|png|jpg|jpeg|jpeg2000|tif|tiff|gif|bmp|mp3|mov|wma|csv|eml|txt)\.[a-zA-Z0-9]{2,8}$/';

function sendMailNotification($mailTo, $mailFrom, $mailSubject, $mailContent) {
	$header = 'From: ' . $mailFrom . "\r\n" .
	'To: ' . $mailTo . "\r\n" .
	'Reply-To: ' . $mailTo . "\r\n" .
	'X-Mailer: PHP/' . phpversion() . "\r\n" .
	"MIME-Version: 1.0\r\n" .
	"Content-Type: text/plain; charset=utf-8\r\n"; //iso-8859-1\r\n";

	try {
		mail($mailTo, $mailSubject, $mailContent, $header);
	} catch (exception $e) {
		;
	}
}

function couldBeHarmful($fileName) {
	global $HARMFUL_EXTENSIONS;
	global $EXTENSION_MIMICS_TYPICAL_DOCUMENT_TYPE;
	global $EXTENSION_TYPICAL_DOCUMENTS;

	$isSuspicious = false;

	// check for suspicious file extensions
	if (preg_match($HARMFUL_EXTENSIONS, $fileName) === 1) $isSuspicious = true;
	if (preg_match($EXTENSION_MIMICS_TYPICAL_DOCUMENT_TYPE, $fileName) === 1) $isSuspicious = true;
	
	return $isSuspicious;
}

function sanitizeFilename($fileName) {
	global $BADCHARS;
	global $BADEXTENSIONS;

	// Remove anything which isn't a word, whitespace, number
	// or any of the following caracters -_~,;[]().
	$fileName = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $fileName);
	// Remove any runs of periods (thanks falstro!)
	$fileName = mb_ereg_replace("([\.]{2,})", '', $fileName);

	// remove bad characters
	$fileName = trim(str_replace($BADCHARS, "", $fileName));

	// check for fileName is just .
	if ($fileName === '.') $fileName = '';
	
	$pos = strpos($fileName, strtolower(basename(__FILE__)));
	if ($pos !== false) {
		$fileName = $fileName . '_';
	} else {
		if (couldBeHarmful($fileName)) {
			$fileName = $fileName . '_';
		}
	}
	
	return $fileName;
}

function isUploadlinkValid() {
	global $VAILD_UNTIL;
	global $REMAINING_UPLOADS;

	if ($REMAINING_UPLOADS == 0) return false;

	if ($VAILD_UNTIL !== '') {
		$date = date('Y-m-d', time());
		return $date <= $VAILD_UNTIL;
	}
	
	return true;
}

function isUUID($str) {
	// a valid uuid *MUST* excatly be 36 characters long
	if (strlen($str) == 36) {
		return (bool) preg_match('/^[a-f0-9]{8}-([a-f0-9]{4}-){3}[a-f0-9]{12}$/', $str);
	} else return false;
}

// checks for typical hash values (md5, sha1, sha256).
function isHash($str) {
	$hashlengths = Array(32, 40, 64);
	if (in_array(strlen($str), $hashlengths)) {
		return (bool) preg_match('/^[a-f0-9]+$/', $str);
	} else return false;
}

function isInt($str) {
	// we do not accept integers with more than 32 digits
	if (strlen($str) <= 32) {
		return (bool) preg_match('/^[0-9]+$/', $str);
	} else return false;
}

// basic base64 check for incoming data
function isBase64($str) {
	return (bool) preg_match('/^[a-zA-Z0-9\/+]*={0,2}$/', $str);
}

// pre-sets the session timeout
function genSessionTimeoutBoundery() {
	return time() + 5*60;
}

// build a authentication cookie used to lock a session for a user
function genSessionAuthCookie($time, $id, $key) {
	return sha256sum($time . $id . $key);
}

// macro for sha256 hash values
function sha256sum($str) {
	return hash('sha256', $str);
}

function genUUID($includeTime = true) {
	$elemtents = '0123456789abcdef';
	$uuid = '';
	$i = 0;

	if ( $includeTime==true ) {
		$i = 8;
		$uuidTime = dechex(time());
		while (strlen($uuidTime) < 8) {
			$uuidTime = '0' . $uuidTime;
		}
		$uuid = $uuidTime . '-';
	}

	for (; $i < 32; $i++) {
		$uuid = $uuid . $elemtents[mt_rand(0, 15)];
		if ($i == 7) $uuid = $uuid . '-';
		if ($i == 11) $uuid = $uuid . '-';
		if ($i == 15) $uuid = $uuid . '-';
		if ($i == 19) $uuid = $uuid . '-';
	}

	return $uuid;
}

// returns a file size limit in bytes based on the PHP upload_max_filesize
// and post_max_size
function file_upload_max_size() {
	static $max_size = -1;

	if ($max_size < 0) {
		// start with post_max_size.
		$post_max_size = parse_size(ini_get('post_max_size'));
		if ($post_max_size > 0) {
			$max_size = $post_max_size;
		}

		// if upload_max_size is less, then reduce. Except if upload_max_size is
		// zero, which indicates no limit.
		$upload_max = parse_size(ini_get('upload_max_filesize'));
		if ($upload_max > 0 && $upload_max < $max_size) {
			$max_size = $upload_max;
		}
	}
	return $max_size;
}

function parse_size($size) {
	// Remove the non-unit characters from the size.
	$unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
	// Remove the non-numeric characters from the size.
	$size = preg_replace('/[^0-9\.]/', '', $size);
	if ($unit) {
		// Find the position of the unit in the ordered string which is the power
		// of magnitude to multiply a kilobyte by.
		return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
	}
	else {
		return round($size);
	}
}

$request = '';
$session_id = '';
$session_cookie = '';
$session_time = '';
$session_password = '';

if (!isUploadlinkValid()) {
	echo "Link expired.";
	if ($DEBUG === false) sleep(3);
	return ;
}

if ( ($PASSWORD !== '') && isset($_POST['request']) ) {
	global $SESSION_KEY;
	
	if (isset($_POST['password'])) {
		$tempHash = hash('sha256', $_POST['password'] . $SESSION_KEY);
		if ($tempHash !== $PASSWORD) {
			if ($DEBUG === false) {
				echo json_encode(array('request' => $request, 'status' => 'error_fatal_wrong_password_provided'));
				sleep(3);
			} else {
				echo json_encode(array('request' => $request, 'status' => 'error_fatal_wrong_password_provided: ' . $tempHash));
			}
			return ;
		}
	} else {
		echo json_encode(array('request' => $request, 'status' => 'error_fatal_password_required'));
		if ($DEBUG === false) sleep(3);
		return ;
	}
}

if (isset($_POST['request'])) {
	//header('Content-type: application/json; charset=utf-8');

	$request = trim($_POST['request']);
	if (!in_array($request, $REQUESTS)) {
		echo json_encode(array('request' => $request, 'status' => 'error_fatal_unknown_request'));
		if ($DEBUG === false) sleep(3);
		return ;
	}

	if ( isset($_POST['session_id']) && isset($_POST['session_cookie']) && isset($_POST['session_time']) ) {
		$session_id = $_POST['session_id'];
		$session_cookie = $_POST['session_cookie'];
		$session_time = $_POST['session_time'];
		$temp_auth = genSessionAuthCookie($session_time, $session_id, $SESSION_KEY);
		if ( ($session_cookie === $temp_auth) && ($session_time > time()) ) {
			$session_time = genSessionTimeoutBoundery();
			$session_cookie = genSessionAuthCookie($session_time, $session_id, $SESSION_KEY);
		} else {
			echo json_encode(array('request' => $request, 'status' => 'error_fatal_authentication_failure', 'session_id' => $session_id));
			if ($DEBUG === false) sleep(3);
			return ;
		}
		if (!isUploadlinkValid()) {
			echo json_encode(array('request' => $request, 'status' => 'error_fatal_upload_expired', 'session_id' => $session_id));
			if ($DEBUG === false) sleep(3);
			return ;
		}
	} else {
		// our session starts with chunked_upload_init, this
		// call generates an UUID and session cookie
		if ($request === "chunked_upload_init") {
			$session_id = genUUID();
			$session_time = genSessionTimeoutBoundery();
			$session_cookie = genSessionAuthCookie($session_time, $session_id, $SESSION_KEY);
		} else {
			if ($DEBUG === false) sleep(3);
			echo json_encode(array('request' => $request, 'status' => 'error_fatal_authentication_failure'));
			return;
		}
	}

	switch ($request) {
		case "chunked_upload_init":
				handler_chunked_upload_init();
				break;
		case "chunked_upload_data":
				handler_chunked_upload_data();
				break;
		case "chunked_upload_close":
				handler_chunked_upload_close();
				break;
		default:
				echo json_encode(array('request' => $request, 'status' => 'error_fatal_handler_not_implemented'));
	}

	return;
}

function handler_chunked_upload_init() {
	global $session_id;
	global $session_time;
	global $session_cookie;
	global $request;

	if (!isset($_POST['filename'])) {
		echo json_encode(array('request' => $request, 'status' => 'error_fatal_parameter_missing', 'session_id' => $session_id));
		return;
	}

	if (file_exists($session_id . '_filename')) {
		echo json_encode(array('request' => $request, 'status' => 'error_fatal_session_file_already_created', 'session_id' => $session_id));
		return;
	}

	$f = fopen($session_id . '_filename', 'wb+');
	if ($f) {
		$fileName = sanitizeFilename($_POST['filename']);
		if ($fileName === '') {
			$fileName = $session_id;
		}
		fwrite($f, $fileName);
		fclose($f);
	}

	global $HARMFUL_EXECUTABLE_EXTENSIONS;
	global $FILE_INFO;
	global $FILE_SAMPLE;
	global $CHUNK_SIZE;
	global $BADCHARS;

	$f = fopen($session_id . '_file', 'wb+');
	if ($f) {
		fclose($f);
		echo json_encode(array('request' => $request, 'status' => 'success', 'session_id' => $session_id, 'session_cookie' => $session_cookie, 'session_time' => $session_time, 'chunk_size' => $CHUNK_SIZE));
	} else {
		echo json_encode(array('request' => $request, 'status' => 'error_initiating_info_file', 'session_id' => $session_id));
	}
}

function handler_chunked_upload_data() {
	global $session_id;
	global $session_time;
	global $session_cookie;
	global $request;

	if (!isset($_POST['chunked_data'])) {
		echo json_encode(array('request' => $request, 'status' => 'error_fatal_parameter_missing', 'session_id' => $session_id));
		return;
	}

	global $MAXFILESIZE;
	global $CHUNK_SIZE;

	if ((strlen($_POST['chunked_data']) <= $CHUNK_SIZE) && (isBase64($_POST['chunked_data']))) {
		if (filesize($session_id . '_file') <= $MAXFILESIZE) {
			$buffer = base64_decode($_POST['chunked_data']);

			/* for failure checks
			if (rand(0, 7) >= 4) {
				$f = fopen($session_id . '_file', 'ab');
				sleep(1);
			} else {
				$f = false;
			}
			sleep(1);*/ $f = fopen($session_id . '_file', 'ab');
			if ($f) {
				fwrite($f, $buffer);
				fclose($f);
				echo json_encode(array('request' => $request, 'status' => 'success', 'session_id' => $session_id, 'session_cookie' => $session_cookie, 'session_time' => $session_time));
			} else {
				echo json_encode(array('request' => $request, 'status' => 'error_writing_chunk_retry', 'session_id' => $session_id, 'session_cookie' => $session_cookie, 'session_time' => $session_time));
			}
		} else {
			//deleteSample($session_id);
			echo json_encode(array('request' => $request, 'status' => 'error_fatal_file_too_large', 'session_id' => $session_id));
		}
	} else {
		deleteSample($session_id);
		echo json_encode(array('request' => $request, 'status' => 'error_fatal_invalid_data', 'session_id' => $session_id));
	}
}

function handler_chunked_upload_close() {
	global $session_id;
	global $session_time;
	global $session_cookie;
	global $request;

	global $REMAINING_UPLOADS;
	global $KILL_BIT;
	global $USE_ORIGINAL_FILENAME;
	global $MAIL_NOTIFICATION;
	
	// decrement download counter if it was set
	if ($REMAINING_UPLOADS > 0) {
		$REMAINING_UPLOADS--;
		// save php file with new download counter
		$thisScript = file_get_contents(__FILE__);
		$searchfor = '/\$REMAINING_UPLOADS(.*)=(.*)[0-9]+;/';
		$replace = '$REMAINING_UPLOADS = ' . $REMAINING_UPLOADS . ';';
		$thisScript= preg_replace($searchfor, $replace, $thisScript, 1);
		$fp = fopen(__FILE__, 'w+');
		if ($fp) {
			fwrite($fp, $thisScript);
			fclose($fp);
		}
	}
	
	try {
		if ($USE_ORIGINAL_FILENAME) {
			if (file_exists($session_id . '_filename')) {
					$fileName = file_get_contents($session_id . '_filename');
					unlink($session_id . '_filename');
					rename($session_id . '_file', $fileName);
			}
		} else {
			// remove connection to the session, generate a new GUID for the file
			$fileName = genUUID(false);
			while (file_exists($fileName)) {
				$fileName = genUUID(false);
			}
			rename($session_id . '_filename', $fileName . '_filename');
			rename($session_id . '_file', $fileName . '_file');		
		}
	} catch (exception $e) {
		;
	}
	
	if ($MAIL_NOTIFICATION) {
		global $MAIL_FROM;
		global $MAIL_TO;
		global $MAIL_SUBJECT;
		global $MAIL_CONTENT_HEADER;
		global $MAIL_CONTENT_FOOTER;
		global $URL;
		
		$mailBody = $MAIL_CONTENT_HEADER . "\r\n\r\n";
		$mailBody = $mailBody . dirname($URL) . '/' . $fileName . "\r\n\r\n";
		$mailBody = $mailBody . $MAIL_CONTENT_FOOTER . "\r\n\r\n";
		sendMailNotification($MAIL_FROM, $MAIL_TO, $MAIL_SUBJECT, $mailBody);
	}
	
	
	echo json_encode(array('request' => $request, 'status' => 'success', 'session_id' => $session_id, 'session_cookie' => $session_cookie, 'session_time' => $session_time));

	// if killbit was set, delete the script
	if ( ($KILL_BIT >= 1) && ($REMAINING_UPLOADS <= 0) ) {
		unlink(__FILE__);
	}
}

// just some delay to defeat crawlers
if ($DEBUG === false) sleep(3);
?><!doctype html>
<html lang="en-US">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $TITLE ?></title>
<meta name="generator" content="<?php echo $GENERATOR . "-" . $VERSION_TITLE ?>" />
<style type="text/css">
.fileContainer {
overflow: hidden;
position: relative;
border: 2px dashed #dadada;
float: left;
padding: 1em;
padding-right: 3em;
}

.fileContainer [type=file] {
cursor: pointer;
display: inline-block;
font-size: 999px;
filter: alpha(opacity=0);
min-height: 100%;
min-width: 100%;
opacity: 0;
position: absolute;
right: 0;
top: 0;
}

.fileContainerFileName {
width: 220px;
border: 1px solid #a0a0a0;
display: inline-block;
padding: .5em;
border-right: 0;
overflow: hidden;
white-space: nowrap;
text-overflow: ellipsis;
}

.fileContainerButton {
padding: 0.5em;
border: 1px solid #a0a0a0;
float: right;
display: inline-block;
overflow: hidden;
position: absolute;
}
.fileContainerDragOver {
background-color: #F5F5F5;
border: 2px solid #808080;
}
.button {
color: #fff;
background-color: #c00b0b;
display: inline-block;
clear: both;
box-sizing: border-box;
vertical-align: middle;
cursor: pointer;
text-transform: none;
overflow: visible;
max-width: 450px;
min-width: 200px;
border: none;
border-radius: 5px;
text-align: center!important;
cursor: pointer;
padding: 0.5em;
margin-top: 0.1em;
margin-left: 0.1em;
margin-right: 0.1em;
margin-bottom: 0.5em;
}
input, textarea {
font-size: 1em;
font-weight: normal;
display: inline-block;
box-sizing: border-box;
padding: 5px;
background-color: #fff;
border: 1px solid #ccc;
border-radius: 5px;
}
</style>
</head>

<body style="font-size: 1.5em;">
<center>
<p><a href="<?php echo $URL ?>" title="logo" alt="Header Logo"><img src="data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+CjxzdmcKICAgeG1sbnM6ZGM9Imh0dHA6Ly9wdXJsLm9yZy9kYy9lbGVtZW50cy8xLjEvIgogICB4bWxuczpjYz0iaHR0cDovL2NyZWF0aXZlY29tbW9ucy5vcmcvbnMjIgogICB4bWxuczpyZGY9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkvMDIvMjItcmRmLXN5bnRheC1ucyMiCiAgIHhtbG5zOnN2Zz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciCiAgIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIKICAgeG1sbnM6c29kaXBvZGk9Imh0dHA6Ly9zb2RpcG9kaS5zb3VyY2Vmb3JnZS5uZXQvRFREL3NvZGlwb2RpLTAuZHRkIgogICB4bWxuczppbmtzY2FwZT0iaHR0cDovL3d3dy5pbmtzY2FwZS5vcmcvbmFtZXNwYWNlcy9pbmtzY2FwZSIKICAgc29kaXBvZGk6ZG9jbmFtZT0ianVzdGRyb3Auc3ZnIgogICBpbmtzY2FwZTp2ZXJzaW9uPSIxLjAgKDQwMzVhNGZiNDksIDIwMjAtMDUtMDEpIgogICBpZD0ic3ZnODM3MSIKICAgdmVyc2lvbj0iMS4xIgogICB2aWV3Qm94PSIwIDAgNjkuODI5NTY3IDEwLjkzNjAxIgogICBoZWlnaHQ9IjEwLjkzNjAxbW0iCiAgIHdpZHRoPSI2OS44Mjk1NjdtbSI+CiAgPGRlZnMKICAgICBpZD0iZGVmczgzNjUiIC8+CiAgPHNvZGlwb2RpOm5hbWVkdmlldwogICAgIGlua3NjYXBlOndpbmRvdy1tYXhpbWl6ZWQ9IjAiCiAgICAgaW5rc2NhcGU6d2luZG93LXk9IjAiCiAgICAgaW5rc2NhcGU6d2luZG93LXg9IjI2IgogICAgIGlua3NjYXBlOndpbmRvdy1oZWlnaHQ9IjEwNDAiCiAgICAgaW5rc2NhcGU6d2luZG93LXdpZHRoPSIxMjc0IgogICAgIGZpdC1tYXJnaW4tYm90dG9tPSIwIgogICAgIGZpdC1tYXJnaW4tcmlnaHQ9IjAiCiAgICAgZml0LW1hcmdpbi1sZWZ0PSIwIgogICAgIGZpdC1tYXJnaW4tdG9wPSIwIgogICAgIHNob3dncmlkPSJmYWxzZSIKICAgICBpbmtzY2FwZTpkb2N1bWVudC1yb3RhdGlvbj0iMCIKICAgICBpbmtzY2FwZTpjdXJyZW50LWxheWVyPSJsYXllcjEiCiAgICAgaW5rc2NhcGU6ZG9jdW1lbnQtdW5pdHM9Im1tIgogICAgIGlua3NjYXBlOmN5PSIyMDEuNzYxMTEiCiAgICAgaW5rc2NhcGU6Y3g9IjE2Ni43MzIzNCIKICAgICBpbmtzY2FwZTp6b29tPSIxLjQiCiAgICAgaW5rc2NhcGU6cGFnZXNoYWRvdz0iMiIKICAgICBpbmtzY2FwZTpwYWdlb3BhY2l0eT0iMC4wIgogICAgIGJvcmRlcm9wYWNpdHk9IjEuMCIKICAgICBib3JkZXJjb2xvcj0iIzY2NjY2NiIKICAgICBwYWdlY29sb3I9IiNmZmZmZmYiCiAgICAgaWQ9ImJhc2UiIC8+CiAgPG1ldGFkYXRhCiAgICAgaWQ9Im1ldGFkYXRhODM2OCI+CiAgICA8cmRmOlJERj4KICAgICAgPGNjOldvcmsKICAgICAgICAgcmRmOmFib3V0PSIiPgogICAgICAgIDxkYzpmb3JtYXQ+aW1hZ2Uvc3ZnK3htbDwvZGM6Zm9ybWF0PgogICAgICAgIDxkYzp0eXBlCiAgICAgICAgICAgcmRmOnJlc291cmNlPSJodHRwOi8vcHVybC5vcmcvZGMvZGNtaXR5cGUvU3RpbGxJbWFnZSIgLz4KICAgICAgICA8ZGM6dGl0bGU+PC9kYzp0aXRsZT4KICAgICAgPC9jYzpXb3JrPgogICAgPC9yZGY6UkRGPgogIDwvbWV0YWRhdGE+CiAgPGcKICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgtOS42MjYwNjU0LC0yMi4yMjU1MTQpIgogICAgIGlkPSJsYXllcjEiCiAgICAgaW5rc2NhcGU6Z3JvdXBtb2RlPSJsYXllciIKICAgICBpbmtzY2FwZTpsYWJlbD0iTGF5ZXIgMSI+CiAgICA8ZwogICAgICAgaWQ9InN1cmZhY2U1MDY0MTciCiAgICAgICB0cmFuc2Zvcm09Im1hdHJpeCgwLjIyNTc3Nzc4LDAsMCwwLjIyNTc3Nzc4LC0xNS41MDk1ODQsLTEyLjU3NDYzKSI+CiAgICAgIDxnCiAgICAgICAgIGlkPSJnODEwOSIKICAgICAgICAgc3R5bGU9ImZpbGw6IzE3NmVlNjtmaWxsLW9wYWNpdHk6MSI+CiAgICAgICAgPGcKICAgICAgICAgICBpZD0idXNlODEwNyIKICAgICAgICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxMTEuMzUsMjAyLjM1MDAxKSI+CiAgICAgICAgICA8cGF0aAogICAgICAgICAgICAgc3R5bGU9InN0cm9rZTpub25lIgogICAgICAgICAgICAgZD0ibSAxLjEyNSwtNDAuMDMxMjUgYyAwLjMzOTg0NCwtMC41MTk1MzEgMS44NjcxODgsLTEuMjUgNC41NzgxMjUsLTIuMTg3NSAyLjcxNDg0NCwtMC45MzM1OTQgNS43MTg3NSwtMS44NTE1NjMgOSwtMi43NSAzLjI4OTA2MywtMC44OTQ1MzEgNi40MjU3ODEsLTEuNjYwMTU2IDkuNDA2MjUsLTIuMjk2ODc1IDIuOTc2NTYzLC0wLjY0NDUzMSA0LjkyMTg3NSwtMC45Mjk2ODggNS44MjgxMjUsLTAuODU5Mzc1IDEuNzc3MzQ0LDAuMTg3NSAyLjk0NTMxMywxLjQ5NjA5NCAzLjUsMy45MjE4NzUgMC41NTA3ODEsMi40MTc5NjkgMC42NjQwNjMsNC4yNzczNDQgMC4zNDM3NSw1LjU3ODEyNSAtMC4zMTI1LDEuMTU2MjUgLTEuMzUxNTYyLDEuOTQxNDA2IC0zLjEwOTM3NSwyLjM0Mzc1IC0xLjc2MTcxOSwwLjQwNjI1IC0zLjEyODkwNiwwLjc4NTE1NiAtNC4wOTM3NSwxLjEyNSAtMC41LDAuMTY3OTY5IC0wLjA1ODU5LDEuMjIyNjU2IDEuMzI4MTI1LDMuMTU2MjUgMS4zODI4MTMsMS45Mzc1IDIuNzM0Mzc1LDQuMzgyODEyIDQuMDQ2ODc1LDcuMzI4MTI1IDEuMzA4NTk0LDIuOTQ5MjE5IDIuMTAxNTYzLDYuMjE0ODQ0IDIuMzc1LDkuNzk2ODc1IDAuMjY5NTMxLDMuNTg1OTM3IC0wLjk0MTQwNiw3LjExMzI4MSAtMy42MjUsMTAuNTc4MTI1IC0xLjAzMTI1LDEuMzEyNSAtMi43OTI5NjksMi4zMTY0MDYgLTUuMjgxMjUsMyAtMi40ODA0NjksMC42Nzk2ODcgLTUuMTA1NDY5LDEuMDQyOTY5IC03Ljg3NSwxLjA5Mzc1IEMgMTQuNzczNDM4LC0wLjE2MDE1NiAxMi4xNDQ1MzEsLTAuNDQxNDA2IDkuNjU2MjUsLTEuMDQ2ODc1IDcuMTc1NzgxLC0xLjY0ODQzOCA1LjQzMzU5NCwtMi41OTM3NSA0LjQzNzUsLTMuODc1IDIuNDE0MDYzLC02LjQ0NTMxMyAxLjExNzE4OCwtOC45Mzc1IDAuNTQ2ODc1LC0xMS4zNDM3NSAtMC4wMjM0Mzc1LC0xMy43NDYwOTQgLTAuMTY0MDYzLC0xNi4zMTI1IDAuMTI1LC0xOS4wMzEyNSBjIDAuMDU4NTk0LC0wLjUyNzM0NCAwLjU5Mzc1LC0xLjAzNTE1NiAxLjU5Mzc1LC0xLjUxNTYyNSAwLjk5NjA5NCwtMC40NzY1NjMgMi4xMDE1NjMsLTAuNzg5MDYzIDMuMzEyNSwtMC45Mzc1IDEuMjA3MDMxLC0wLjE0NDUzMSAyLjMzMjAzMSwtMC4wNjY0MSAzLjM3NSwwLjIzNDM3NSAxLjA1MDc4MSwwLjI5Mjk2OSAxLjY0ODQzOCwwLjk0MTQwNiAxLjc5Njg3NSwxLjkzNzUgMC4xMjEwOTQsMS4wMTE3MTkgMC40Mzc1LDIuMTI4OTA2IDAuOTM3NSwzLjM0Mzc1IDAuNTA3ODEzLDEuMjE4NzUgMS4xNDg0MzgsMi4yNTM5MDYgMS45MjE4NzUsMy4wOTM3NSAwLjc3NzM0NCwwLjg0Mzc1IDEuNjY0MDYzLDEuMzYzMjgxIDIuNjU2MjUsMS41NDY4NzUgMC45ODgyODEsMC4xNzk2ODcgMi4wNDY4NzUsLTAuMjU3ODEzIDMuMTcxODc1LC0xLjMxMjUgMS4xMTMyODEsLTEuMDI3MzQ0IDEuNTUwNzgxLC0yLjYyNSAxLjMxMjUsLTQuNzgxMjUgLTAuMjMwNDY5LC0yLjE2NDA2MyAtMC43NTc4MTIsLTQuMzE2NDA2IC0xLjU3ODEyNSwtNi40NTMxMjUgLTAuODI0MjE5LC0yLjEzMjgxMyAtMS43NDIxODcsLTMuOTYwOTM4IC0yLjc1LC01LjQ4NDM3NSAtMSwtMS41MTk1MzEgLTEuNzE0ODQ0LC0yLjE3OTY4OCAtMi4xNDA2MjUsLTEuOTg0Mzc1IC0wLjQzNzUsMC4yMTg3NSAtMS4yNTc4MTIsMC40NTcwMzEgLTIuNDUzMTI1LDAuNzAzMTI1IC0xLjE4NzUsMC4yNDIxODcgLTIuNDU3MDMxLDAuNDI5Njg3IC0zLjc5Njg3NSwwLjU2MjUgLTEuMzM1OTM3LDAuMTI1IC0yLjU4NTkzNywwLjE3MTg3NSAtMy43NSwwLjE0MDYyNSAtMS4xNTYyNSwtMC4wMzkwNiAtMS45MjU3ODEsLTAuMjQyMTg4IC0yLjI5Njg3NSwtMC42MDkzNzUgLTAuNzUsLTAuNjgzNTk0IC0xLjE2Nzk2OSwtMi4xNzk2ODggLTEuMjUsLTQuNDg0Mzc1IC0wLjA3NDIxOSwtMi4zMDA3ODEgMC4yMzgyODEsLTMuOTY4NzUgMC45Mzc1LC01IHogbSAwLDAiCiAgICAgICAgICAgICBpZD0icGF0aDgyMDYiIC8+CiAgICAgICAgPC9nPgogICAgICA8L2c+CiAgICAgIDxnCiAgICAgICAgIGlkPSJnODExMyIKICAgICAgICAgc3R5bGU9ImZpbGw6IzE3NmVlNjtmaWxsLW9wYWNpdHk6MSI+CiAgICAgICAgPGcKICAgICAgICAgICBpZD0idXNlODExMSIKICAgICAgICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxNDUuMTAwMDEsMjAyLjM1MDAxKSI+CiAgICAgICAgICA8cGF0aAogICAgICAgICAgICAgc3R5bGU9InN0cm9rZTpub25lIgogICAgICAgICAgICAgZD0ibSA4LjM5MDYyNSwtNDcuMDkzNzUgYyAxLjM3MTA5NCwtMC40MzM1OTQgMy4xOTE0MDYsLTAuMzQ3NjU2IDUuNDUzMTI1LDAuMjY1NjI1IDIuMjU3ODEzLDAuNjA1NDY5IDMuNjcxODc1LDIuMTA1NDY5IDQuMjM0Mzc1LDQuNSAwLjI1NzgxMywxLjE5OTIxOSAwLjE3OTY4OCwyLjYwMTU2MiAtMC4yMzQzNzUsNC4yMDMxMjUgLTAuNDA2MjUsMS42MDU0NjkgLTAuOTE3OTY5LDMuMjQyMTg3IC0xLjUzMTI1LDQuOTA2MjUgLTAuNjA1NDY5LDEuNjU2MjUgLTEuMTkxNDA2LDMuMjc3MzQ0IC0xLjc1LDQuODU5Mzc1IC0wLjU1NDY4NywxLjU3NDIxOSAtMC44MjgxMjUsMi45MzM1OTQgLTAuODI4MTI1LDQuMDc4MTI1IC0wLjAxMTcyLDIuMzEyNSAwLjQyNTc4MSw0LjA1NDY4NyAxLjMxMjUsNS4yMTg3NSAwLjg5NDUzMSwxLjE1NjI1IDEuODU5Mzc1LDIuMTEzMjgxIDIuODkwNjI1LDIuODU5Mzc1IDIuMDcwMzEzLDEuNTkzNzUgNC4xMzI4MTMsMS41IDYuMTg3NSwtMC4yODEyNSAxLjAyNzM0NCwtMC44NzEwOTQgMiwtMi4wMjM0MzggMi45MDYyNSwtMy40NTMxMjUgMC45MDIzNDQsLTEuNDI1NzgxIDEuNDI1NzgxLC0zLjI1NzgxMyAxLjU2MjUsLTUuNSAwLjE0NDUzMSwtMi4yMTQ4NDQgLTAuNDc2NTYyLC00Ljk3NjU2MyAtMS44NTkzNzUsLTguMjgxMjUgLTEuMzg2NzE5LC0zLjMwMDc4MSAtMS42Nzk2ODcsLTYuMDc4MTI1IC0wLjg3NSwtOC4zMjgxMjUgMC43ODkwNjMsLTIuMjM4MjgxIDIuMjM0Mzc1LC0zLjk5MjE4OCA0LjMyODEyNSwtNS4yNjU2MjUgMi4wODk4NDQsLTEuMjY5NTMxIDQuMTY0MDYzLC0xLjE5OTIxOSA2LjIxODc1LDAuMjAzMTI1IDIuMDI3MzQ0LDEuNDE3OTY5IDMuNjI4OTA2LDMuODMyMDMxIDQuNzk2ODc1LDcuMjM0Mzc1IDEuMTc1NzgxLDMuMzk4NDM3IDEuOTg4MjgxLDYuNTcwMzEyIDIuNDM3NSw5LjUxNTYyNSAwLjQzMzU5NCwyLjk5MjE4NyAwLjU4NTkzOCw2LjA5NzY1NiAwLjQ1MzEyNSw5LjMxMjUgLTAuMTI1LDMuMjE4NzUgLTAuNjY3OTY5LDYuMDk3NjU2IC0xLjYyNSw4LjYyNSAtMC45NDkyMTksMi41MzEyNSAtMi4yNDYwOTQsNC43NDYwOTQgLTMuODkwNjI1LDYuNjQwNjI1IC0xLjYzNjcxOSwxLjg5ODQzNyAtMy44MDg1OTQsMy4zMzk4NDQgLTYuNTE1NjI1LDQuMzI4MTI1IC0yLjY5OTIxOSwwLjk5MjE4NyAtNi4wMTk1MzEsMS41IC05Ljk1MzEyNSwxLjUzMTI1IEMgMTguMTcxODc1LDAuMTA1NDY5IDE0LjgwMDc4MSwtMC4zOTg0MzggMTIsLTEuNDUzMTI1IDkuMTg3NSwtMi41MDM5MDYgNi45MTQwNjMsLTQgNS4xODc1LC01LjkzNzUgMy40Njg3NSwtNy44NzEwOTQgMi4xMjEwOTQsLTEwLjI4MTI1IDEuMTU2MjUsLTEzLjE1NjI1IGMgLTAuOTQ5MjE5LC0yLjg1MTU2MyAtMS4yOTI5NjksLTYuNDYwOTM4IC0xLjA0Njg3NSwtMTAuODI4MTI1IDAuMjU3ODEzLC00LjM3MTA5NCAwLjYwOTM3NSwtNy41NTA3ODEgMS4wNDY4NzUsLTkuNTMxMjUgMC4xOTUzMTMsLTAuOTg4MjgxIDAuNTU0Njg4LC0yLjIxMDkzOCAxLjA3ODEyNSwtMy42NzE4NzUgMC41MjczNDQsLTEuNDU3MDMxIDEuMTMyODEzLC0yLjg3ODkwNiAxLjgxMjUsLTQuMjY1NjI1IDAuNjc1NzgxLC0xLjM5NDUzMSAxLjM5NDUzMSwtMi42Mjg5MDYgMi4xNTYyNSwtMy43MDMxMjUgMC43NTc4MTMsLTEuMDcwMzEzIDEuNDg4MjgxLC0xLjcxODc1IDIuMTg3NSwtMS45Mzc1IHogbSAwLDAiCiAgICAgICAgICAgICBpZD0icGF0aDgyMTAiIC8+CiAgICAgICAgPC9nPgogICAgICA8L2c+CiAgICAgIDxnCiAgICAgICAgIGlkPSJnODExNyIKICAgICAgICAgc3R5bGU9ImZpbGw6IzE3NmVlNjtmaWxsLW9wYWNpdHk6MSI+CiAgICAgICAgPGcKICAgICAgICAgICBpZD0idXNlODExNSIKICAgICAgICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxODguOTUsMjAyLjM1MDAxKSI+CiAgICAgICAgICA8cGF0aAogICAgICAgICAgICAgc3R5bGU9InN0cm9rZTpub25lIgogICAgICAgICAgICAgZD0ibSAyNC41MTU2MjUsLTQ3LjQzNzUgYyAyLjg2MzI4MSwwLjE1NjI1IDQuOTUzMTI1LDEuNjgzNTk0IDYuMjY1NjI1LDQuNTc4MTI1IDEuMzIwMzEzLDIuODg2NzE5IDEuMjU3ODEzLDUuMTc1NzgxIC0wLjE4NzUsNi44NTkzNzUgLTAuNzQyMTg3LDAuODQzNzUgLTEuODc4OTA2LDEuMjc3MzQ0IC0zLjQwNjI1LDEuMjk2ODc1IC0xLjUzMTI1LDAuMDIzNDQgLTMuMTUyMzQ0LC0wLjA3ODEzIC00Ljg1OTM3NSwtMC4yOTY4NzUgLTEuNjk5MjE5LC0wLjIyNjU2MyAtMy4zMjQyMTksLTAuNDI5Njg4IC00Ljg3NSwtMC42MDkzNzUgLTEuNTQyOTY5LC0wLjE4MzU5NCAtMi42OTUzMTIsLTAuMDU4NTkgLTMuNDUzMTI1LDAuMzc1IC0xLjUzMTI1LDAuODk4NDM3IC0yLjA5Mzc1LDEuOTE3OTY5IC0xLjY4NzUsMy4wNjI1IDAuNDE0MDYzLDEuMTM2NzE5IDEuMTcxODc1LDEuOTQxNDA2IDIuMjY1NjI1LDIuNDA2MjUgMC44OTQ1MzEsMC4zODY3MTkgMi4wNzgxMjUsMC4zOTg0MzcgMy41NDY4NzUsMC4wMzEyNSAxLjQ3NjU2MywtMC4zNjMyODEgMy4wNjI1LC0wLjY5NTMxMyA0Ljc1LC0xIDEuNjgzNTk0LC0wLjMwODU5NCAzLjM4MjgxMywtMC4zODI4MTMgNS4wOTM3NSwtMC4yMTg3NSAxLjcxNDg0NCwwLjE2Nzk2OSAzLjI2NTYyNSwwLjk3MjY1NiA0LjY0MDYyNSwyLjQwNjI1IDIuMDM5MDYzLDIuMTU2MjUgMy4wMjczNDQsNS4xNjQwNjIgMi45Njg3NSw5LjAxNTYyNSAtMC4wNjI1LDMuODQzNzUgLTAuMzgyODEyLDYuOTY0ODQ0IC0wLjk1MzEyNSw5LjM1OTM3NSAtMC41OTM3NSwyLjM4NjcxOSAtMS45NzY1NjIsNC4zNDc2NTYgLTQuMTQwNjI1LDUuODc1IC0yLjE2Nzk2OSwxLjUzMTI1IC00LjYwMTU2MiwyLjY2NDA2MiAtNy4yOTY4NzUsMy4zOTA2MjUgQyAyMC41LC0wLjE3NTc4MSAxNy44MDQ2ODgsMC4xNjc5NjkgMTUuMTA5Mzc1LDAuMTQwNjI1IDEyLjQxMDE1NiwwLjEwOTM3NSAxMC4yNTc4MTMsLTAuMjY1NjI1IDguNjU2MjUsLTAuOTg0Mzc1IDYuNTA3ODEzLC0xLjk0MTQwNiA0LjY0NDUzMSwtMy40ODgyODEgMy4wNjI1LC01LjYyNSAxLjQ3NjU2MywtNy43Njk1MzEgMC41NTA3ODEsLTkuODk0NTMxIDAuMjgxMjUsLTEyIGMgLTAuMjUsLTIuMTEzMjgxIDAuMzc1LC0zLjc1NzgxMyAxLjg3NSwtNC45Mzc1IDEuNDk2MDk0LC0xLjE3NTc4MSAyLjk5MjE4OCwtMS43NjU2MjUgNC40ODQzNzUsLTEuNzY1NjI1IDEuNDY0ODQ0LDAgMi4zOTQ1MzEsMC45MzM1OTQgMi43ODEyNSwyLjc5Njg3NSAwLjM5NDUzMSwxLjg1NTQ2OSAxLjI1LDMuMTYwMTU2IDIuNTYyNSwzLjkwNjI1IDEuMjg5MDYzLDAuNzUgMi41OTM3NSwxLjA4OTg0NCAzLjkwNjI1LDEuMDE1NjI1IDEuMzA4NTk0LC0wLjA4MjAzIDIuNTA3ODEzLC0wLjI2NTYyNSAzLjU5Mzc1LC0wLjU0Njg3NSAxLjcyNjU2MywtMC40NTcwMzEgMi45MTAxNTYsLTEuNjI4OTA2IDMuNTQ2ODc1LC0zLjUxNTYyNSAwLjYzMjgxMywtMS44OTQ1MzEgMC4yNjk1MzEsLTMuMzQ3NjU2IC0xLjA5Mzc1LC00LjM1OTM3NSAtMS40ODA0NjksLTEuMDU4NTk0IC0zLjMxNjQwNiwtMS4zMjAzMTMgLTUuNSwtMC43ODEyNSAtMi4xODc1LDAuNTMxMjUgLTQuMzYzMjgxLDAuODQ3NjU2IC02LjUxNTYyNSwwLjkzNzUgLTIuMTQ4NDM3LDAuMDg1OTQgLTQuMDg1OTM3LC0wLjU2NjQwNiAtNS44MTI1LC0xLjk1MzEyNSAtMS43MTg3NSwtMS4zOTQ1MzEgLTIuODQ3NjU2LC00LjU3MDMxMyAtMy4zNzUsLTkuNTMxMjUgLTAuNDA2MjUsLTMuNjIxMDk0IDAuMzMyMDMxLC02LjU0Njg3NSAyLjIxODc1LC04Ljc2NTYyNSAxLjg5NDUzMSwtMi4yMjY1NjMgNC4yMDMxMjUsLTMuOTQxNDA2IDYuOTIxODc1LC01LjE0MDYyNSAyLjcxNDg0NCwtMS4xOTUzMTMgNS40NzY1NjMsLTEuOTc2NTYzIDguMjgxMjUsLTIuMzQzNzUgMi44MDA3ODEsLTAuMzYzMjgxIDQuOTIxODc1LC0wLjUxMTcxOSA2LjM1OTM3NSwtMC40NTMxMjUgeiBtIDAsMCIKICAgICAgICAgICAgIGlkPSJwYXRoODIxNCIgLz4KICAgICAgICA8L2c+CiAgICAgIDwvZz4KICAgICAgPGcKICAgICAgICAgaWQ9Imc4MTIxIgogICAgICAgICBzdHlsZT0iZmlsbDojMTc2ZWU2O2ZpbGwtb3BhY2l0eToxIj4KICAgICAgICA8ZwogICAgICAgICAgIGlkPSJ1c2U4MTE5IgogICAgICAgICAgIHRyYW5zZm9ybT0idHJhbnNsYXRlKDIyMy44OTk5OSwyMDIuMzUwMDEpIj4KICAgICAgICAgIDxwYXRoCiAgICAgICAgICAgICBzdHlsZT0ic3Ryb2tlOm5vbmUiCiAgICAgICAgICAgICBkPSJtIDAuODI4MTI1LC0zNS45NTMxMjUgYyAwLjM3MTA5NCwtMC41NTg1OTQgMS4wNzgxMjUsLTAuODc4OTA2IDIuMTA5Mzc1LC0wLjk1MzEyNSAxLjAyNzM0NCwtMC4wODIwMyAyLjEyNSwtMC4xMjg5MDYgMy4yODEyNSwtMC4xNDA2MjUgMS4xNjQwNjMsLTAuMDE5NTMgMi4yNjU2MjUsLTAuMTAxNTYzIDMuMjk2ODc1LC0wLjI1IDEuMDI3MzQ0LC0wLjE1MjM0NCAxLjczNDM3NSwtMC41OTM3NSAyLjEwOTM3NSwtMS4zMTI1IDAuNzQ2MDk0LC0xLjQwMjM0NCAwLjY3OTY4OCwtMi44MjAzMTMgLTAuMjAzMTI1LC00LjI1IC0wLjg3NSwtMS40MjU3ODEgLTAuOTQ5MjE5LC0yLjUyMzQzOCAtMC4yMTg3NSwtMy4yOTY4NzUgMC4zNjMyODEsLTAuMzcxMDk0IDEuMTcxODc1LC0wLjcwNzAzMSAyLjQyMTg3NSwtMSAxLjI1NzgxMywtMC4zMDA3ODEgMi42MTMyODEsLTAuNTIzNDM4IDQuMDYyNSwtMC42NzE4NzUgMS40NDUzMTMsLTAuMTQ0NTMxIDIuODA0Njg4LC0wLjE5MTQwNiA0LjA3ODEyNSwtMC4xNDA2MjUgMS4yNjk1MzEsMC4wNDI5NyAyLjEwOTM3NSwwLjIxMDkzNyAyLjUxNTYyNSwwLjUgMC43NzczNDQsMC42MTcxODcgMS4wOTc2NTYsMS43NDYwOTQgMC45NTMxMjUsMy4zOTA2MjUgLTAuMTM2NzE5LDEuNjM2NzE5IDAuMTc5Njg4LDMuMDcwMzEyIDAuOTUzMTI1LDQuMjk2ODc1IDAuMzcxMDk0LDAuNjI1IDEuMTA5Mzc1LDAuNzI2NTYyIDIuMjAzMTI1LDAuMjk2ODc1IDEuMTAxNTYzLC0wLjQyNTc4MSAyLjMwMDc4MSwtMC45NTMxMjUgMy41OTM3NSwtMS41NzgxMjUgMS4yODkwNjMsLTAuNjIxMDk0IDIuNTUwNzgxLC0xLjEyODkwNiAzLjc4MTI1LC0xLjUxNTYyNSAxLjIzODI4MSwtMC4zOTQ1MzEgMi4xODc1LC0wLjI0NjA5NCAyLjg0Mzc1LDAuNDM3NSAxLjMwODU5NCwxLjQwNjI1IDIuMDc4MTI1LDMuNjQ0NTMxIDIuMjk2ODc1LDYuNzAzMTI1IDAuMjI2NTYzLDMuMDYyNSAwLjE1MjM0NCw1LjQxNDA2MiAtMC4yMTg3NSw3LjA0Njg3NSAtMC4xODc1LDAuODM1OTM3IC0wLjk2NDg0NCwxLjI5Mjk2OSAtMi4zMjgxMjUsMS4zNzUgLTEuMzY3MTg3LDAuMDc0MjIgLTIuODcxMDk0LDAuMTA5Mzc1IC00LjUxNTYyNSwwLjEwOTM3NSAtMS42MzY3MTksMCAtMy4xOTE0MDYsMC4xMzI4MTIgLTQuNjU2MjUsMC4zOTA2MjUgLTEuNDY4NzUsMC4yNSAtMi40MDIzNDQsMC45NjQ4NDQgLTIuNzk2ODc1LDIuMTQwNjI1IC0wLjQwNjI1LDEuMTY3OTY5IC0wLjI2MTcxOSwyLjg5NDUzMSAwLjQzNzUsNS4xNzE4NzUgMC42OTUzMTMsMi4yNzM0MzcgMS40NTMxMjUsNC41ODk4NDQgMi4yNjU2MjUsNi45NTMxMjUgMC44MDg1OTQsMi4zNjcxODcgMS40Mzc1LDQuNTI3MzQ0IDEuODc1LDYuNDg0Mzc1IDAuNDQ1MzEzLDEuOTQ5MjE5IDAuMjE0ODQ0LDMuMTc1NzgxIC0wLjY4NzUsMy42NzE4NzUgLTEuODI0MjE5LDEuMDMxMjUgLTQuNTY2NDA2LDEuNjA1NDY5IC04LjIxODc1LDEuNzE4NzUgLTMuNjU2MjUsMC4xMDU0NjkgLTYuNDAyMzQ0LC0wLjIxODc1IC04LjIzNDM3NSwtMC45Njg3NSAtMC45MTc5NjksLTAuMzcxMDk0IC0xLjI4OTA2MiwtMS41ODIwMzEgLTEuMTA5Mzc1LC0zLjYyNSAwLjE3NTc4MSwtMi4wNTA3ODEgMC40Njg3NSwtNC4yOTY4NzUgMC44NzUsLTYuNzM0Mzc1IDAuNDAyMzQ0LC0yLjQ0NTMxMyAwLjcwNzAzMSwtNC43ODUxNTYgMC45MDYyNSwtNy4wMTU2MjUgMC4yMDcwMzEsLTIuMjM4MjgxIC0wLjEyODkwNiwtMy43NDIxODggLTEsLTQuNTE1NjI1IC0wLjg4NjcxOSwtMC43NDYwOTQgLTEuODQ3NjU2LC0xLjA0Mjk2OSAtMi44NzUsLTAuODkwNjI1IC0xLjAzMTI1LDAuMTQ4NDM3IC0yLjA1MDc4MSwwLjQxMDE1NiAtMy4wNDY4NzUsMC43ODEyNSAtMSwwLjM3NSAtMS45Mjk2ODcsMC42OTkyMTkgLTIuNzgxMjUsMC45Njg3NSAtMC44NTU0NjksMC4yNzM0MzcgLTEuNTM5MDYyLDAuMTUyMzQ0IC0yLjA0Njg3NSwtMC4zNTkzNzUgLTAuNTMxMjUsLTAuNDk2MDk0IC0xLjAwMzkwNiwtMS4zNjMyODEgLTEuNDA2MjUsLTIuNTkzNzUgLTAuNDA2MjUsLTEuMjI2NTYzIC0wLjcxNDg0NCwtMi41MzEyNSAtMC45MjE4NzUsLTMuOTA2MjUgLTAuMTk5MjE5LC0xLjM4MjgxMyAtMC4yODEyNSwtMi43MDcwMzEgLTAuMjUsLTMuOTY4NzUgMC4wMzkwNjMsLTEuMjU3ODEzIDAuMjU3ODEzLC0yLjE3NTc4MSAwLjY1NjI1LC0yLjc1IHogbSAwLDAiCiAgICAgICAgICAgICBpZD0icGF0aDgyMTgiIC8+CiAgICAgICAgPC9nPgogICAgICA8L2c+CiAgICAgIDxnCiAgICAgICAgIGlkPSJnODEyNSIKICAgICAgICAgc3R5bGU9ImZpbGw6IzE3NmVlNjtmaWxsLW9wYWNpdHk6MSI+CiAgICAgICAgPGcKICAgICAgICAgICBpZD0idXNlODEyMyIKICAgICAgICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgyNjQuNDUwMDEsMjAyLjM1MDAxKSI+CiAgICAgICAgICA8cGF0aAogICAgICAgICAgICAgc3R5bGU9InN0cm9rZTpub25lIgogICAgICAgICAgICAgZD0ibSAxMy45MDYyNSwtMzYuODI4MTI1IGMgMi4xNjQwNjMsMCAzLjUzOTA2MywtMC4wMDM5IDQuMTI1LC0wLjAxNTYzIDAuODUxNTYzLC0wLjAxOTUzIDEuNTMxMjUsMC4wMjczNCAyLjAzMTI1LDAuMTQwNjI1IDAuNDY0ODQ0LDAuMDkzNzUgMC45Mjk2ODgsMC4zMDQ2ODcgMS4zOTA2MjUsMC42MjUgMC41MjczNDQsMC40MDYyNSAxLjI2NTYyNSwwLjkyNTc4MSAyLjIwMzEyNSwxLjU0Njg3NSAtMC44OTg0MzcsLTIuMTk1MzEzIC0xLjU4NTkzNywtMy42ODc1IC0yLjA2MjUsLTQuNDY4NzUgLTAuNSwtMC44MDA3ODEgLTAuODg2NzE5LC0yLjM2MzI4MSAtMS4xNTYyNSwtNC42ODc1IC0wLjE1NjI1LC0wLjg5NDUzMSAwLjQ5MjE4OCwtMS43MjI2NTYgMS45NTMxMjUsLTIuNDg0Mzc1IDEuMzgyODEzLC0wLjc2OTUzMSAzLjAzMTI1LC0xLjMyODEyNSA0LjkzNzUsLTEuNjcxODc1IDEuODM5ODQ0LC0wLjMwODU5NCAzLjY2NDA2MywtMC4zMTY0MDYgNS40Njg3NSwtMC4wMTU2MyAxLjcyNjU2MywwLjI4MTI1IDIuODc1LDEuMDU0Njg3IDMuNDM3NSwyLjMxMjUgMC45ODgyODEsMi4zOTg0MzcgMS44Nzg5MDYsNS40MTAxNTYgMi42NzE4NzUsOS4wMzEyNSAwLjc3NzM0NCwzLjczMDQ2OSAxLjI1NzgxMyw3LjU4MjAzMSAxLjQzNzUsMTEuNTQ2ODc1IDAuMTUyMzQ0LDQuMDMxMjUgLTAuMTA1NDY5LDguMDQyOTY5IC0wLjc4MTI1LDEyLjAzMTI1IC0wLjY4NzUsMy45Njg3NSAtMS45OTIxODcsNy40NzI2NTYgLTMuOTA2MjUsMTAuNSBDIDMzLjg3NSwtMS4zNzUgMzEuMTkxNDA2LC0wLjY1MjM0NCAyNy42MDkzNzUsLTAuMjgxMjUgMjMuOTg0Mzc1LDAuMDU4NTkzOCAyMC4zNTU0NjksMCAxNi43MzQzNzUsLTAuNDY4NzUgMTMuMDY2NDA2LC0wLjk1NzAzMSA5LjcxNDg0NCwtMS45NzI2NTYgNi42ODc1LC0zLjUxNTYyNSBjIC0zLC0xLjUxOTUzMSAtNC44NTU0NjksLTMuNjc1NzgxIC01LjU2MjUsLTYuNDY4NzUgLTAuNTQyOTY5LC0yLjA4OTg0NCAtMC44MjQyMTksLTQuNjg3NSAtMC44NDM3NSwtNy43ODEyNSAtMC4wNDI5NjksLTMuMDE5NTMxIDAuMzc1LC01Ljk2ODc1IDEuMjUsLTguODQzNzUgMC44Mzk4NDQsLTIuODA4NTk0IDIuMjUsLTUuMjE4NzUgNC4yMTg3NSwtNy4yMTg3NSAxLjk2NDg0NCwtMS45OTYwOTQgNC42ODc1LC0zIDguMTU2MjUsLTMgeiBtIDYuNDIxODc1LDEzLjc4MTI1IGMgLTMuMTg3NSwwLjMwNDY4NyAtNC44MjQyMTksMS43MjI2NTYgLTQuOTA2MjUsNC4yNSAtMC4xMDU0NjksMi41NjI1IDAuOTI1NzgxLDQuNDc2NTYyIDMuMDkzNzUsNS43MzQzNzUgMS4xNDQ1MzEsMC43MTA5MzcgMi42Mjg5MDYsMC45MDYyNSA0LjQ1MzEyNSwwLjU5Mzc1IDEuODAwNzgxLC0wLjI2OTUzMSAzLjA2NjQwNiwtMC4zOTQ1MzEgMy43OTY4NzUsLTAuMzc1IDAuNTgyMDMxLDAgMS4wODIwMzEsLTAuNTcwMzEzIDEuNSwtMS43MTg3NSAwLjQyNTc4MSwtMS4xMzI4MTMgMC40OTYwOTQsLTIuMzY3MTg4IDAuMjE4NzUsLTMuNzAzMTI1IC0wLjI1LC0xLjI3NzM0NCAtMS4wMDM5MDYsLTIuNDY4NzUgLTIuMjUsLTMuNTYyNSAtMS4yNDIxODcsLTEuMDI3MzQ0IC0zLjIxMDkzNywtMS40MzM1OTQgLTUuOTA2MjUsLTEuMjE4NzUgeiBtIDAsMCIKICAgICAgICAgICAgIGlkPSJwYXRoODIyMiIgLz4KICAgICAgICA8L2c+CiAgICAgIDwvZz4KICAgICAgPGcKICAgICAgICAgaWQ9Imc4MTI5IgogICAgICAgICBzdHlsZT0iZmlsbDojMTc2ZWU2O2ZpbGwtb3BhY2l0eToxIj4KICAgICAgICA8ZwogICAgICAgICAgIGlkPSJ1c2U4MTI3IgogICAgICAgICAgIHRyYW5zZm9ybT0idHJhbnNsYXRlKDMwNS4zNTAwMSwyMDIuMzUwMDEpIj4KICAgICAgICAgIDxwYXRoCiAgICAgICAgICAgICBzdHlsZT0ic3Ryb2tlOm5vbmUiCiAgICAgICAgICAgICBkPSJtIDUuNzUsLTQzLjc4MTI1IGMgMi4wMjczNDQsLTEuNzM4MjgxIDQuNzg1MTU2LC0yLjc5Njg3NSA4LjI2NTYyNSwtMy4xNzE4NzUgMy40ODgyODEsLTAuMzcxMDk0IDYuODk4NDM4LC0wLjE0NDUzMSAxMC4yMzQzNzUsMC42ODc1IDMuMzMyMDMxLDAuODI0MjE5IDYuMjEwOTM4LDIuMjI2NTYyIDguNjQwNjI1LDQuMjAzMTI1IDIuNDMzNTk0LDEuOTgwNDY5IDMuNjIxMDk0LDQuNDU3MDMxIDMuNTYyNSw3LjQyMTg3NSAtMC4wODU5NCwzLjM1NTQ2OSAtMC42MDU0NjksNS44MDA3ODEgLTEuNTYyNSw3LjMyODEyNSAtMC45NDkyMTksMS41MjM0MzcgLTEuOTQ5MjE5LDIuNjA1NDY5IC0zLDMuMjUgLTEuMDU0Njg3LDAuNjQ4NDM3IC0xLjk3MjY1NiwxLjA4MjAzMSAtMi43NSwxLjI5Njg3NSAtMC43ODEyNSwwLjIxODc1IC0xLjAzMTI1LDAuNjk5MjE5IC0wLjc1LDEuNDM3NSAwLjI1NzgxMywwLjc1IDAuODMyMDMxLDEuMzc4OTA2IDEuNzE4NzUsMS44NzUgMC44OTQ1MzEsMC41IDEuODMyMDMxLDEuMDExNzE5IDIuODEyNSwxLjUzMTI1IDAuOTc2NTYzLDAuNTExNzE5IDEuODYzMjgxLDEuMDg5ODQ0IDIuNjU2MjUsMS43MzQzNzUgMC44MDA3ODEsMC42NDg0MzcgMS4yNSwxLjQ5NjA5NCAxLjM0Mzc1LDIuNTQ2ODc1IDAuMDU4NTksMS4wNDI5NjkgLTAuMjM4MjgxLDIuMzQ3NjU2IC0wLjg5MDYyNSwzLjkwNjI1IC0wLjY1NjI1LDEuNTYyNSAtMS41MzUxNTYsMy4wNjY0MDYgLTIuNjI1LDQuNSAtMS4wODU5MzcsMS40Mjk2ODcgLTIuMzA4NTk0LDIuNjIxMDk0IC0zLjY3MTg3NSwzLjU3ODEyNSAtMS4zNjcxODcsMC45NjA5MzcgLTIuNzE0ODQ0LDEuMzU1NDY5IC00LjA0Njg3NSwxLjE4NzUgLTEuMzQzNzUsLTAuMTUyMzQ0IC0yLjMwNDY4NywtMS4wMTk1MzEgLTIuODc1LC0yLjU5Mzc1IC0wLjU2MjUsLTEuNTgyMDMxIC0xLjA2NjQwNiwtMy4yODUxNTYgLTEuNSwtNS4xMDkzNzUgLTAuNDI5Njg3LC0xLjgzMjAzMSAtMC45MzM1OTQsLTMuNDkyMTg4IC0xLjUxNTYyNSwtNC45ODQzNzUgLTAuNTc0MjE5LC0xLjQ5NjA5NCAtMS41MzUxNTYsLTIuMjUgLTIuODc1LC0yLjI1IC0xLjM1NTQ2OSwwLjAyMzQ0IC0yLjA1ODU5NCwwLjU1NDY4NyAtMi4xMDkzNzUsMS41OTM3NSAtMC4wNDI5NywxLjAzMTI1IDAuMDc4MTMsMi4yMzA0NjkgMC4zNTkzNzUsMy41OTM3NSAwLjI4OTA2MywxLjM1NTQ2OSAwLjUwNzgxMywyLjcxMDkzNyAwLjY1NjI1LDQuMDYyNSAwLjE1MjM0NCwxLjM1NTQ2OSAtMC4yNDYwOTQsMi4zNTE1NjIgLTEuMjAzMTI1LDIuOTg0Mzc1IC0wLjk2ODc1LDAuNjU2MjUgLTIuMTIxMDk0LDEuMzMyMDMxIC0zLjQ1MzEyNSwyLjAxNTYyNSAtMS4zMjQyMTksMC42ODc1IC0yLjY0ODQzNywxLjEwNTQ2ODcgLTMuOTY4NzUsMS4yNSBDIDUuODkwNjI1LDAuMjQ2MDk0IDQuNjY3OTY5LDAgMy41NDY4NzUsLTAuNjU2MjUgMi40MjE4NzUsLTEuMzA4NTk0IDEuNTgyMDMxLC0yLjY3MTg3NSAxLjAzMTI1LC00LjczNDM3NSAwLjQ4ODI4MSwtNi43OTI5NjkgMC4xODM1OTQsLTkuNzg1MTU2IDAuMTI1LC0xMy43MDMxMjUgMC4wNzAzMTI1LC0xNy42MTcxODggMC4yNSwtMjEuNjI1IDAuNjU2MjUsLTI1LjcxODc1IDEuMDU4NTk0LC0yOS44MDg1OTQgMS42OTE0MDYsLTMzLjU3MDMxMyAyLjU0Njg3NSwtMzcgMy4zOTg0MzgsLTQwLjQyNTc4MSA0LjQ2ODc1LC00Mi42ODc1IDUuNzUsLTQzLjc4MTI1IFogbSAxMS4zNTkzNzUsNi4yOTY4NzUgYyAtMS4xNjc5NjksMC4xNzk2ODcgLTIuMjUzOTA2LDEuMzAwNzgxIC0zLjI1LDMuMzU5Mzc1IC0xLDIuMDYyNSAtMS4wMzEyNSwzLjUyMzQzNyAtMC4wOTM3NSw0LjM3NSAxLjI3NzM0NCwxLjIxMDkzNyAyLjY3MTg3NSwxLjkxMDE1NiA0LjE3MTg3NSwyLjA5Mzc1IDEuNDk2MDk0LDAuMTg3NSAyLjg0Mzc1LDAuMDQyOTcgNC4wMzEyNSwtMC40Mzc1IDEuMTk1MzEzLC0wLjQ4ODI4MSAyLjEwMTU2MywtMS4yMzQzNzUgMi43MTg3NSwtMi4yMzQzNzUgMC42MjEwOTQsLTAuOTk2MDk0IDAuNjk5MjE5LC0yLjA5Mzc1IDAuMjM0Mzc1LC0zLjI4MTI1IC0wLjQyOTY4NywtMC45OTYwOTQgLTEuNTcwMzEyLC0xLjkyOTY4OCAtMy40MjE4NzUsLTIuNzk2ODc1IC0xLjg0Mzc1LC0wLjg3MTA5NCAtMy4zMDg1OTQsLTEuMjMwNDY5IC00LjM5MDYyNSwtMS4wNzgxMjUgeiBtIDAsMCIKICAgICAgICAgICAgIGlkPSJwYXRoODIyNiIgLz4KICAgICAgICA8L2c+CiAgICAgIDwvZz4KICAgICAgPGcKICAgICAgICAgaWQ9Imc4MTMzIgogICAgICAgICBzdHlsZT0iZmlsbDojMTc2ZWU2O2ZpbGwtb3BhY2l0eToxIj4KICAgICAgICA8ZwogICAgICAgICAgIGlkPSJ1c2U4MTMxIgogICAgICAgICAgIHRyYW5zZm9ybT0idHJhbnNsYXRlKDM0MS41LDIwMi4zNTAwMSkiPgogICAgICAgICAgPHBhdGgKICAgICAgICAgICAgIHN0eWxlPSJzdHJva2U6bm9uZSIKICAgICAgICAgICAgIGQ9Im0gOC4zNzUsLTQ0Ljg1OTM3NSBjIDIuNzc3MzQ0LC0xLjE4MzU5NCA1LjcwMzEyNSwtMi4wMDc4MTMgOC43NjU2MjUsLTIuNDY4NzUgMy4wNTg1OTQsLTAuNDY0ODQ0IDYsLTAuNDAyMzQ0IDguODEyNSwwLjE4NzUgMi44MjAzMTMsMC41OTM3NSA1LjM4MjgxMywxLjgwNDY4NyA3LjY4NzUsMy42MjUgMi4zMDg1OTQsMS44MjQyMTkgNC4wOTc2NTYsNC40MjU3ODEgNS4zNTkzNzUsNy43OTY4NzUgMC44Mzk4NDQsMi4zMTI1IDEuNDI1NzgxLDQuOTc2NTYyIDEuNzUsNy45ODQzNzUgMC4zMjAzMTMsMyAwLjIzODI4MSw2LjAxOTUzMSAtMC4yNSw5LjA0Njg3NSAtMC40ODA0NjksMy4wMzEyNSAtMS40MzM1OTQsNS45MjU3ODEgLTIuODU5Mzc1LDguNjcxODc1IC0xLjQxNzk2OSwyLjc0MjE4NyAtMy40NDkyMTksNC45ODgyODEgLTYuMDkzNzUsNi43MzQzNzUgLTIuMzQzNzUsMS41NjI1IC01LjEyODkwNiwyLjU4MjAzMSAtOC4zNDM3NSwzLjA0Njg3NSBDIDE5Ljk4NDM3NSwwLjIyMjY1NiAxNi44Mzk4NDQsMC4wODk4NDM4IDEzLjc4MTI1LC0wLjYyNSAxMC43MTg3NSwtMS4zNTE1NjMgOC4wMDM5MDYsLTIuNzE4NzUgNS42NDA2MjUsLTQuNzE4NzUgMy4yODUxNTYsLTYuNzE0ODQ0IDEuODA4NTk0LC05LjQwNjI1IDEuMjE4NzUsLTEyLjc4MTI1IDAuODEyNSwtMTUuMDM5MDYzIDAuNDY0ODQ0LC0xNy43NzM0MzggMC4xODc1LC0yMC45ODQzNzUgYyAtMC4yNjk1MzEzLC0zLjIxNDg0NCAtMC4yMzgyODEzLC02LjQwNjI1IDAuMDkzNzUsLTkuNTYyNSAwLjMzOTg0NCwtMy4xNjQwNjMgMS4xMjUsLTYuMDYyNSAyLjM0Mzc1LC04LjY4NzUgMS4yMTQ4NDQsLTIuNjIxMDk0IDMuMTMyODEzLC00LjUgNS43NSwtNS42MjUgeiBNIDE1LjY1NjI1LC0zMS44NzUgYyAtMi4xMDU0NjksMC45MDYyNSAtMy4xOTUzMTIsMi44Mzk4NDQgLTMuMjY1NjI1LDUuNzk2ODc1IC0wLjA3NDIyLDIuOTYwOTM3IDAuMDU0NjksNS4zNTU0NjkgMC4zOTA2MjUsNy4xODc1IDAuNDY0ODQ0LDIuNzMwNDY5IDIuMTU2MjUsNC4zNzEwOTQgNS4wNjI1LDQuOTIxODc1IDIuOTAyMzQ0LDAuNTQyOTY5IDUuMzAwNzgxLDAuMTgzNTk0IDcuMTg3NSwtMS4wNzgxMjUgMi4xNDQ1MzEsLTEuNDI1NzgxIDMuMzU5Mzc1LC0zLjUgMy42NDA2MjUsLTYuMjE4NzUgMC4yNzczNDQsLTIuNzI2NTYzIDAuMDc0MjIsLTUuMDMxMjUgLTAuNjA5Mzc1LC02LjkwNjI1IC0xLjAyMzQzNywtMi43MTQ4NDQgLTIuNzg1MTU2LC00LjI1MzkwNiAtNS4yODEyNSwtNC42MDkzNzUgLTIuNSwtMC4zNjMyODEgLTQuODc4OTA2LC0wLjA1ODU5IC03LjEyNSwwLjkwNjI1IHogbSAwLDAiCiAgICAgICAgICAgICBpZD0icGF0aDgyMzAiIC8+CiAgICAgICAgPC9nPgogICAgICA8L2c+CiAgICAgIDxnCiAgICAgICAgIGlkPSJnODEzNyIKICAgICAgICAgc3R5bGU9ImZpbGw6IzE3NmVlNjtmaWxsLW9wYWNpdHk6MSI+CiAgICAgICAgPGcKICAgICAgICAgICBpZD0idXNlODEzNSIKICAgICAgICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSgzODIuMDQ5OTksMjAyLjM1MDAxKSI+CiAgICAgICAgICA8cGF0aAogICAgICAgICAgICAgc3R5bGU9InN0cm9rZTpub25lIgogICAgICAgICAgICAgZD0iTSAzLjcwMzEyNSwtMS4yMTg3NSBDIDMuMTYwMTU2LC0xLjU4OTg0NCAyLjU1MDc4MSwtMy42OTE0MDYgMS44NzUsLTcuNTE1NjI1IDEuMjA3MDMxLC0xMS4zNDc2NTYgMC42OTUzMTMsLTE1LjYyNSAwLjM0Mzc1LC0yMC4zNDM3NSBjIC0wLjM1MTU2MjUsLTQuNzE0ODQ0IC0wLjQ0NTMxMywtOS4yNTM5MDYgLTAuMjgxMjUsLTEzLjYwOTM3NSAwLjE3NTc4MSwtNC4zNjMyODEgMC44NTkzNzUsLTcuMjY1NjI1IDIuMDQ2ODc1LC04LjcwMzEyNSAyLjM2MzI4MSwtMi44NjMyODEgNS44MDQ2ODgsLTQuNTA3ODEzIDEwLjMyODEyNSwtNC45Mzc1IDQuNTE5NTMxLC0wLjQyNTc4MSA4LjgyMDMxMywtMC4yNzczNDQgMTIuOTA2MjUsMC40Mzc1IDUuOTAyMzQ0LDEuMDQyOTY5IDkuNjU2MjUsMi44ODI4MTIgMTEuMjUsNS41MTU2MjUgMS42MDE1NjMsMi42MzY3MTkgMi4yMjY1NjMsNi42MTMyODEgMS44NzUsMTEuOTIxODc1IEMgMzguMTI1LC0yNC43NSAzNy4wNjY0MDYsLTIxLjE3NTc4MSAzNS4yOTY4NzUsLTE5IGMgLTEuNzYxNzE5LDIuMTc5Njg3IC0zLjc5Mjk2OSwzLjY0NDUzMSAtNi4wOTM3NSw0LjM5MDYyNSAtMi4yOTI5NjksMC43NDIxODcgLTQuNjI4OTA2LDEuMDg5ODQ0IC03LDEuMDQ2ODc1IC0yLjM2NzE4NywtMC4wNTA3OCAtNC4zMDQ2ODcsMC4xNjQwNjIgLTUuODEyNSwwLjY0MDYyNSAtMC42NDg0MzcsMC4yMTA5MzcgLTAuODU5Mzc1LDAuODAwNzgxIC0wLjY0MDYyNSwxLjc2NTYyNSAwLjIxNDg0NCwwLjk2ODc1IDAuNTAzOTA2LDIuMDU4NTk0IDAuODU5Mzc1LDMuMjY1NjI1IDAuMzYzMjgxLDEuMjEwOTM3IDAuNjI1LDIuNDE0MDYyIDAuNzgxMjUsMy42MDkzNzUgMC4xNTIzNDQsMS4xOTkyMTkgLTAuMTMyODEyLDIuMTI4OTA2IC0wLjg1OTM3NSwyLjc4MTI1IC0xLjUsMS4zMTI1IC0zLjc0NjA5NCwxLjg3MTA5NCAtNi43MzQzNzUsMS42ODc1IEMgNi44MTY0MDYsMCA0Ljc4NTE1NiwtMC40Njg3NSAzLjcwMzEyNSwtMS4yMTg3NSBaIE0gMTUuNDY4NzUsLTM1LjI5Njg3NSBjIC0wLjkwNjI1LDEuMDc0MjE5IC0xLjQ5NjA5NCwyLjUwMzkwNiAtMS43NjU2MjUsNC4yODEyNSAtMC4yNzM0MzcsMS43NzM0MzcgMC4yMzQzNzUsMy4yNTc4MTIgMS41MTU2MjUsNC40NTMxMjUgMi4xNjQwNjMsMi4wOTM3NSA0LjM4MjgxMywyLjY3OTY4NyA2LjY1NjI1LDEuNzUgMi4yNzczNDQsLTAuOTI1NzgxIDMuNjkxNDA2LC0yLjgwNDY4OCA0LjIzNDM3NSwtNS42NDA2MjUgMC4zOTQ1MzEsLTIuMDcwMzEzIDAuMTM2NzE5LC0zLjU4NTkzOCAtMC43NjU2MjUsLTQuNTQ2ODc1IC0wLjg5ODQzNywtMC45NjQ4NDQgLTIuMDAzOTA2LC0xLjU2MjUgLTMuMzEyNSwtMS43ODEyNSAtMS4zMTI1LC0wLjIyNjU2MyAtMi42Mjg5MDYsLTAuMTY3OTY5IC0zLjkzNzUsMC4xNzE4NzUgLTEuMzEyNSwwLjM0Mzc1IC0yLjE5MTQwNiwwLjc4NTE1NiAtMi42MjUsMS4zMTI1IHogbSAwLDAiCiAgICAgICAgICAgICBpZD0icGF0aDgyMzQiIC8+CiAgICAgICAgPC9nPgogICAgICA8L2c+CiAgICAgIDxnCiAgICAgICAgIGlkPSJnODE0MSIKICAgICAgICAgc3R5bGU9ImZpbGw6IzE3NmVlNjtmaWxsLW9wYWNpdHk6MSI+CiAgICAgICAgPGcKICAgICAgICAgICBpZD0idXNlODEzOSIKICAgICAgICAgICB0cmFuc2Zvcm09InRyYW5zbGF0ZSg0MjAuMjUsMjAyLjM1MDAxKSI+CiAgICAgICAgICA8cGF0aAogICAgICAgICAgICAgc3R5bGU9InN0cm9rZTpub25lIgogICAgICAgICAgICAgZD0iIgogICAgICAgICAgICAgaWQ9InBhdGg4MjM4IiAvPgogICAgICAgIDwvZz4KICAgICAgPC9nPgogICAgICA8ZwogICAgICAgICBpZD0iZzgxNDUiCiAgICAgICAgIHN0eWxlPSJmaWxsOiMxNzZlZTY7ZmlsbC1vcGFjaXR5OjEiPgogICAgICAgIDxnCiAgICAgICAgICAgaWQ9InVzZTgxNDMiCiAgICAgICAgICAgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoNDQzLjcwMDAxLDIwMi4zNTAwMSkiIC8+CiAgICAgIDwvZz4KICAgICAgPGcKICAgICAgICAgaWQ9Imc4MTQ5IgogICAgICAgICBzdHlsZT0iZmlsbDojMTc2ZWU2O2ZpbGwtb3BhY2l0eToxIj4KICAgICAgICA8ZwogICAgICAgICAgIGlkPSJ1c2U4MTQ3IgogICAgICAgICAgIHRyYW5zZm9ybT0idHJhbnNsYXRlKDQ2Mi4wNDk5OSwyMDIuMzUwMDEpIiAvPgogICAgICA8L2c+CiAgICA8L2c+CiAgPC9nPgo8L3N2Zz4K"></a></p>

<div id="sectionUploader" style="display: block;">
<p><?php echo $GREETING; ?></p>
<div style="position: relative; display: inline-block;">
<form method="post" action="#" enctype="multipart/form-data">
<label class="fileContainer" id="fileSelectBox" ondragover="dragOver(event)" ondragleave="leaveDrop(event)" ondrop="onDrop(event)">
<div class="fileContainerFileName" ondrop="onDrop(event)" id="fileName">Select or drop file</div><span class="fileContainerButton">...</span>
<input name="fileBox" id="fileBox" onchange="fileContainerChangeFile(event)" type="file"/>
</label>
</div>
<?php if ($PASSWORD !== '') {echo '<br> Password:<br><input type="password" id="password" name="password" required>'; } ?>
<br><br>
<span class="button" id="upload">Upload</span><br>
<br>
</form>
</div>

<div id="statusBlock" style="display: none;">
<span id="statusInfoText"></span>&nbsp;<span id="cursor" style="transition: all .25s ease-in-out;">█</span>
</div>
</div>

<!-- Chunked Upload Library BEGIN -->
<script type="text/javascript">
var intervalCursorBlinking = setInterval(blinkingCursor, 380);

var sessionID = null;
var sessionCookie = null;
var sessionTime = null;
var sessionPassword = null;
var isUploaded = false;

window.addEventListener('load', function() {
	document.getElementById('upload').addEventListener('click', submitSample);
	document.getElementById('password').addEventListener('keypress', function (e) {
		if (e.key === 'Enter') {
		 submitSample(e);
		 e.preventDefault();
		}
	});
}, false);

function blinkingCursor() {
	if (document.getElementById('cursor').style.opacity == 0) {
		document.getElementById('cursor').style.opacity = 1;
	} else {
		document.getElementById('cursor').style.opacity = 0;
	}
}

function submitSample(e) {
	console.log('funtion submitSample called');
	document.getElementById('statusBlock').style.display = 'block';
	el = document.getElementById('statusInfoText');
	chunkUpload.init("<?php echo $URL ?>", 10, 10000, 'fileBox', 'password', el);
	chunkUpload.startUpload();
	//document.getElementById('upload').removeEventListener('click', submitSample);
	document.getElementById('upload').disabled = true;
}

function myJsonHandler(json) {
	//console.log(json);

	// preserve session information
	if (json.hasOwnProperty('session_cookie')) {
		sessionCookie = json.session_cookie;
	}
	if (json.hasOwnProperty('session_id')) {
		sessionID = json.session_id;
	}
	if (json.hasOwnProperty('session_time')) {
		sessionTime = json.session_time;
	}
	// log return message
	if (json.hasOwnProperty('status') && json.hasOwnProperty('request')) {
		if (json.status !== 'success') {
			console.log(json);
		}
	}
}

var chunkUpload = (function() {
	var url = '';

	var xhr = null;
	var file = null;

	var sData = '';
	var timeout_maxTries = 0;
	var timeout_cnt = 0;
	var timeout_timeToWait = 1000*30; // 30 seconds should be enough
	var chunkSize = 0;

	var start = 0;
	var stop = 0;

	var remainder = 0;
	var blkcount = 0;

	var cnt = 0;

	var upload_id = -1;
	var upload_state = 0;

	var statusInfoText;

	function responseHandler() {
		var respJSON;

		if ( (this.status == 200) && (this.readyState === 4) ) {
			try {
				respJSON = JSON.parse(this.responseText);
			} catch (err) {
				console.log('Looks like a JSON parsing error.\nReply was:');
				console.log(this.responseText);
				return ;
			}
			if (respJSON.status === 'success') {
				if (upload_state === 1) {
					// chunk upload init was successful, let's start uploading
					upload_state = 2;
					console.log('request: ' + respJSON.request);
					console.log(respJSON.session_id);
					console.log(respJSON.session_cookie);
					console.log(respJSON.session_time);
					upload_id = respJSON.session_id;
					chunkSize = Math.floor(respJSON.chunk_size / 2);
					remainder = file.size % chunkSize;
					blkcount = Math.floor(file.size / chunkSize);
					if (remainder !== 0) {
						blkcount = blkcount + 1;
					}
					stop = chunkSize;
					// preserve session info
					sessionID = respJSON.session_id;
					sessionCookie = respJSON.session_cookie;
					sessionTime = respJSON.session_time;
				} else if (upload_state === 3) {
					// last chunk will be uploaded now
					console.log('last chunk of ' + blkcount + ' will be uploaded now');
					upload_state = -1;
					xhr.onload = responseHandler;
					xhr.open('POST', url, true);
					xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
					xhr.ontimeout = timeoutHandler;
					xhr.timeout = timeout_timeToWait;
					timeout_cnt = timeout_maxTries;
					sData = 'session_id=' + upload_id + '&session_cookie=' + respJSON.session_cookie + '&session_time=' + respJSON.session_time + '&password=' + sessionPassword + '&request=chunked_upload_close';
					// preserve session info
					sessionID = respJSON.session_id;
					sessionCookie = respJSON.session_cookie;
					sessionTime = respJSON.session_time;

					xhr.send(sData);
				}
				if (cnt < blkcount) {
					statusInfoText.textContent = 'Uploading ' + Math.ceil((cnt*100)/blkcount) + '%';

					xhr.onload = responseHandler;
					xhr.open('POST', url, true);
					xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
					xhr.ontimeout = timeoutHandler;
					xhr.timeout = timeout_timeToWait;
					timeout_cnt = timeout_maxTries;

					var reader = new FileReader();
					var blob = file.slice(start, stop);

					reader.readAsDataURL(blob); //readAsBinaryString(blob);
					reader.onload = function (e) {
						sData = e.target.result.match(/,(.*)$/)[1];  //e.target.result;
						sData = 'session_id=' + upload_id + '&session_cookie=' + respJSON.session_cookie + '&session_time=' + respJSON.session_time + '&password=' + sessionPassword + '&request=chunked_upload_data&chunked_data=' + encodeURIComponent(sData);
						// preserve session info
						sessionID = respJSON.session_id;
						sessionCookie = respJSON.session_cookie;
						sessionTime = respJSON.session_time;

						xhr.send(sData);

						start = stop;
						stop = stop + chunkSize;
						cnt++;
						if (cnt >= blkcount) {
							upload_state = 3;
							statusInfoText.textContent = 'Uploading 100%';
						}
						if (cnt == (blkcount - 1) && remainder != 0) {
							stop = start + remainder;
						}
						if (cnt == blkcount) {
							stop = start;
						}
					}
				}
				if (respJSON.request === 'chunked_upload_close') {
					isUploaded = true;
					sData = '';
					console.log('Finished uploading file.');
					
					statusInfoText.textContent = 'Successfully uploaded file.';
				}
			} else {
				console.log('Error occured: ' + respJSON.status);
				statusInfoText.textContent = respJSON.status;
				if (!respJSON.status.startsWith('error_fatal_')) {
					var someTimeout = setTimeout(function() { timeoutHandler(); return ; }, timeout_timeToWait);
				}
			}
		} else {
			try { console.log('Fatal State Error: ' + this.responseText); } catch (err) { ; }
		}
	}

	function timeoutHandler() {
		if (timeout_cnt > 0) {
			timeout_cnt--;
			xhr.onload = responseHandler;
			xhr.open('POST', url, true);
			xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
			xhr.ontimeout = timeoutHandler;
			xhr.timeout = timeout_timeToWait;
			statusInfoText.textContent = 'Retrying to send ' + timeout_cnt + '/' + timeout_maxTries;
			console.log(statusInfoText.textContent);
			xhr.send(sData);
		} else {
			statusInfoText.textContent = 'Fatal timeout error, cancelled submission.';
			console.log(statusInfoText.textContent);
			sData = '';
		}
	}

	return {
		init: function(targetURL, maxTries, waitTimer, fileObjectIdentifier, passwordElement, outputTextContentElement) {
			url = '';
			xhr = null;
			file = null;
			fileID = '';
			sData = '';
			timeout_maxTries = 0;
			timeout_cnt = 0;
			chunkSize = 0;
			start = 0;
			stop = 0;
			remainder = 0;
			blkcount = 0;
			cnt = 0;
			upload_id = -1;
			upload_state = 0;

			url = targetURL;
			timeout_maxTries = maxTries;
			timeout_timeToWait = waitTimer;
			statusInfoText = outputTextContentElement;
			fileID = fileObjectIdentifier;
			aPassword = document.getElementById(passwordElement);
			if (aPassword){
				sessionPassword = aPassword.value;
			}
		},
		startUpload: function() {
			//xhr = new XMLHttpRequest();
			try {
				// Mozilla, Opera, Safari and MS Internet Explorer (>= v7)
				if (window.XMLHttpRequest) {
				xhr = new XMLHttpRequest();
				}
			} catch (err) {
				try {
					// MS Internet Explorer (>= v6)
					xhr  = new ActiveXObject('Microsoft.XMLHTTP');
				} catch (err) {
					try {
						// MS Internet Explorer (>= v5)
						xhr  = new ActiveXObject('Msxml2.XMLHTTP');
					} catch (err) {
						xhr = null;
						statusInfoText.textContent = 'Browser not supported, update to a recent version.';
					}
				}
			}
			
			try {
				document.getElementById('sectionUploader').style.display = 'none';

				if ((droppedFiles===undefined) || (droppedFiles===null)) {
					statusInfoText.textContent = 'Error, no file specified.';
					return;
				}
				//var files = document.getElementById(fileID).files;
				//file = files[0];
				file = droppedFiles[0];
				if (file.size > 0) {
					if (file.size > <?php echo $MAXFILESIZE ?>) {
						statusInfoText.textContent = 'File is too large.';
					} else {
						statusInfoText.textContent = 'Initiating upload';

						sData = 'request=chunked_upload_init&filename=' + file.name;
						sData = sData + '&password=' + sessionPassword;

						xhr.onload = responseHandler;
						// seems that ontimeout is not supported by all browsers
						try {
							xhr.ontimeout = timeoutHandler;
							xhr.timeout = timeout_timeToWait;
							timeout_cnt = timeout_maxTries;
						} catch (err) {
							console.log('Your browser does not support timeouts.');
							; // not supported
						}
						upload_state = 1;

						xhr.open('POST', url, true);
						xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
						xhr.send(sData);
					}
				} else {
					statusInfoText.textContent = 'Nothing to upload, file is empty.';
				}
			} catch (err) {
				console.log('Error while trying to access file.' + file);
				statusInfoText.textContent = 'Error while trying to access file.';
			}
		}
	};
})();
<!-- Chunked Upload Library END -->

<!-- Highlighted File Drop Library BEGIN -->
	var droppedFiles = null;
	
	function fileContainerChangeFile(e) {
		document.getElementById('fileSelectBox').classList.remove( 'fileContainerDragOver' );
		try {
			droppedFiles = document.getElementById('fileBox').files;
			document.getElementById('fileName').textContent = droppedFiles[0].name;
		} catch (error) { ; }
		// you can also use the property from the fileBox field, but this won't work
		// with good old IE.
		try {
			aName = document.getElementById('fileBox').value;
			if (aName !== '') {
				document.getElementById('fileName').textContent = aName;
			}
		} catch (error) {
			;
		}
	}
	
	function onDrop(e) {
		document.getElementById('fileSelectBox').classList.remove( 'fileContainerDragOver' );
		try {
			droppedFiles = e.dataTransfer.files;
			document.getElementById('fileName').textContent = droppedFiles[0].name;
		} catch (error) { ; }
	}

	function dragOver(e) {
		document.getElementById('fileSelectBox').classList.add( 'fileContainerDragOver' );
		e.preventDefault();
		e.stopPropagation();
	}

	function leaveDrop(e) {
		document.getElementById('fileSelectBox').classList.remove( 'fileContainerDragOver' );
	}
</script>
<!-- Highlighted File Drop Library END -->

<hr>
<p>&copy; 2020 by https://github.com/HazelFazel</p>
</center>
</body>
