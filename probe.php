<?php
/**
*
* @author TerraFrost < terrafrost@phpbb.com >
* @author jasmineaura < jasmine.aura@yahoo.com >
*
* @package phpBB3
* @version $Id: probe.php 6 2008-09-08 07:12:00GMT $
* @copyright (c) 2006 TerraFrost
* @copyright (c) 2008 jasmineaura
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/


/**
* @ignore
*/
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

if ( !isset($_GET['extra']) || !preg_match('/^[A-Za-z0-9,]*$/',trim($_GET['extra'])) )
{
	// since we're not user-facing, we don't care about debug messages
	die();
}

// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup();

// Basic parameter data
$extra	= request_var('extra', '');
$mode = request_var('mode', '');
$user_agent = request_var('user_agent', '');
$vendor = request_var('vendor', '');
$version = request_var('version', '');
$orig_ip = request_var('ip', '');
$xml_ip = request_var('xml_ip', '');
$lan_ip = request_var('local', '');
$url = request_var('url', '');

list($sid,$key) = explode(',',trim($extra));

$config['server_port'] = trim($config['server_port']);

$server_name = trim($config['server_name']);
$server_protocol = ($config['cookie_secure']) ? 'https://' : 'http://';
$server_port = ($config['server_port'] != 80) ? ':' .$config['server_port'] : '';
$path_name = '/' . preg_replace('/^\/?(.*?)\/?$/', '\1', trim($config['script_path']));
$path_name.= ($path_name != '') ? '/' : '';

$server_url = $server_protocol . $server_name . $server_port . $path_name;

// according to <http://www.cl.cam.ac.uk/~mgk25/unicode.html>, "the [Universal Character Set] characters U+0000 to U+007F are identical to those in 
// US-ASCII (ISO 646 IRV) and the range U+0000 to U+00FF is identical to ISO 8859-1 (Latin-1)", where "ISO-8859-1 is (according to the standards at least)
// the default encoding of documents delivered via HTTP with a MIME type beginning with "text/"" <ref: http://en.wikipedia.org/wiki/ISO_8859-1#ISO-8859-1>
// (ie. the charset with which chr(0x80 - 0xFF) are most likely to be interpreted with).  since <http://tools.ietf.org/html/rfc2781#section-2> defines each character
// whose Universal Character Set value is equal to and lower than U+FFFF to be a "single 16-bit integer with a value equal to that of the character number",
// adding a chr(0x00) before each character should be sufficient to convert any string to UTF-16 (assuming the byte order mark is U+FEFF).
function iso_8859_1_to_utf16($str)
{
	// the first two characters represent the byte order mark
	return chr(0xFE).chr(0xFF).chr(0).chunk_split($str, 1, chr(0));
}

// according to <http://en.wikipedia.org/wiki/Base64#UTF-7>, "[UTF-7] is used to encode UTF-16 as ASCII characters for use in 7-bit transports such as SMTP".
// <http://betterexplained.com/articles/unicode/> provides more information.  in a departure from the method described there, everything, regardless of whether or
// not it's within the allowed U+0000 - U+007F range is encoded to base64.
function iso_8859_1_to_utf7($str)
{
	return '+'.preg_replace('#=+$#','',base64_encode(substr(iso_8859_1_to_utf16($str),2))).'-';
}

// $ip_address, in the following function, corresponds to the "real IP address".  $info corresponds to the "fake IP address".
// in admin_speculative.php, the first column shows the "fake IP address" and the third column shows the "real IP address".
// the reason we do it in this way is because when you're looking at the IP address of a post, you're going to see the 
// "fake IP address".
function insert_ip($ip_address,$mode,$info,$secondary_info = '')
{
	global $db, $user, $sid, $key, $config;

	// session_begin() in session.php looks at $config['ip_check'] to see which bits of an IP address to check and so to shall we.
	// see "Select ip validation" in includes/acp/acp_board.php for more info
	// per this, if you're looking through some log files trying to see what a particular use did, do a search for the first two or
	// three parts of an IP address (eg. if 128.128.128.4 did something, search for 128.128.128. to see what other things they
	// might have done since they could technically still be logged in with 128.128.128.4 and 128.128.128.199)
	if ( $config['ip_check'] == 3 && (ip2long($info) & 0xFFFFFF00) == (ip2long($ip_address) & 0xFFFFFF00)) // The Default
	{
		return;
	}
	else if ( $config['ip_check'] == 2 && (ip2long($info) & 0xFFFF0000) == (ip2long($ip_address) & 0xFFFF0000))
	{
		return;
	}
	else if ( $config['ip_check'] == 0 ) // No IP Validation - so we return and log nothing...
	{
		return;
	}

	// in java, atleast, there's a possibility that the main IP we're recording and the "masked" IP address are the same.
	// the reason this function would be called, in those cases, is to log $lan_ip.   $lan_ip, however,
	// isn't reliable enough to block people over (assuming any blocking is taking place).  As such, although we log it,
	// we don't update phpbb_sessions.
	if ( $mode != JAVA_INTERNAL )
	{
		// session_speculative_test will eventually be used to determine whether or not this session ought to be
		// banned.  this check is performed by performing a bitwise and against $config['ip_block'].  if
		// the bits that represent the varrious modes 'and' with any of the bits in the bitwise representation of
		// session_speculative_test, a block is done.  to guarantee that each bit is unique to a specific mode,
		// powers of two are used to represent the modes (see constants.php).
		$sql = 'UPDATE ' . SESSIONS_TABLE . " 
			SET session_speculative_test = session_speculative_test | $mode 
			WHERE session_id = '$sid'
				AND session_speculative_key = '$key'";

		if ( !($result = $db->sql_query($sql)) )
		{
			die();
		}

		// if neither the session_id or the session_speculative_key are valid (as would be revealed by $db->sql_numrows being 0),
		// we assume the information is not trustworthy and quit.
		if ( !$db->sql_affectedrows($result) )
		{
			die();
		}

		// ban, if appropriate
		if ( $config['ip_ban'] && ($mode & $config['ip_block']) )
		{
			$sql = 'SELECT * FROM ' . BANLIST_TABLE . " 
				WHERE ban_ip = '$ip_address'";

			if ( !($result = $db->sql_query($sql)) )
			{
				trigger_error('Unable to obtain banlist information from banlist table.');
			}

			if ( !$row = $db->sql_fetchrow($result) )
			{
				$sql = 'INSERT INTO ' . BANLIST_TABLE . " (ban_ip) 
					VALUES ('$ip_address')";

				if ( !$db->sql_query($sql) )
				{
					trigger_error('Unable to to insert ban_userip info into banlist table.');
				}

				$sql = 'DELETE FROM ' . SESSIONS_TABLE . " 
					WHERE session_ip = '$ip_address'";

				if ( !$db->sql_query($sql) )
				{
					trigger_error('Unable to delete banned sessions from sessions table.');
				}
			}
		}
	}

	$ip_address = encode_ip($ip_address);

	$sql = 'SELECT * FROM ' . SPECULATIVE_TABLE." 
		WHERE ip_address = '$ip_address' 
			AND method = $mode  
			AND real_ip = '$info'";

	if ( !($result = $db->sql_query($sql)) )
	{
		die();
	}

	if ( !$row = $db->sql_fetchrow($result) )
	{
		$secondary_info = ( !empty($secondary_info) ) ? "'$secondary_info'" : 'NULL';

		$sql = 'INSERT INTO ' . SPECULATIVE_TABLE . " (ip_address, method, discovered, real_ip, info) 
			VALUES ('$ip_address', $mode, ".time().", '$info', $secondary_info)";

		if ( !$db->sql_query($sql) )
		{
			die();
		}
	}
}

// this pass concerns itself with x_forwarded_for, which may be able to identify transparent http proxies.
// $user->ip represents our current "spoofed" address and $_SERVER['HTTP_X_FORWARDED_FOR'] represents our possibly "real"
// address
if ( !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] != $user->ip )
{
	$x_forwarded_for = str_replace("\'","''",htmlspecialchars($_SERVER['HTTP_X_FORWARDED_FOR']));

	insert_ip($user->ip,X_FORWARDED_FOR,$x_forwarded_for);
}

switch ($mode):
	case 'flash':
		$info = $user_agent .'<>'. $version;

		// $orig_ip represents our old "spoofed" address and $xml_ip represents our current "real" address.
		// if they're different, we've probably managed to break out of the HTTP proxy, so we log it.
		if ( $orig_ip != $xml_ip )
		{
			insert_ip($orig_ip,FLASH,$xml_ip,$info);
		}
		
		exit;
	case 'java':
		$info = $user_agent .'<>'. $vendor .'<>'. $version;

		// here, we're not trying to get the "real" IP address - we're trying to get the internal LAN IP address.
		if ( !empty($lan_ip) && $lan_ip != $user->ip )
		{
			insert_ip($user->ip,JAVA_INTERNAL,$lan_ip,$info);
		}

		// $orig_ip represents our old "spoofed" address and $user->ip represents our current "real" address.
		// if they're different, we've probably managed to break out of the HTTP proxy, so we log it.
		if ( $orig_ip != $user->ip )
		{
			insert_ip($orig_ip,JAVA,$user->ip,$info);
		}

		exit;
	case 'xss':
		header('Content-Type: text/html; charset=ISO-8859-1');

		$schemes = array('http','https'); // we don't want to save stuff like javascript:alert('test')

		$xss_info = $xss_glue = '';
		// we capture the url in the hopes that it'll reveal the location of the cgi proxy.  having the
		// location gives us proof that we can give to anyone (ie. it shows you how to make a post
		// from that very same ip address)
		if ( !empty($_SERVER['HTTP_REFERER']) )
		{
			$parsed = parse_url($_SERVER['HTTP_REFERER']);
			// if one of the referers IP addresses are equal to the server, we assume they're the same.
			if ( !in_array($_SERVER['SERVER_ADDR'],gethostbynamel($parsed['host'])) && in_array($parsed['scheme'], $schemes) )
			{
				$xss_info = str_replace("\'","''",htmlspecialchars($_SERVER['HTTP_REFERER']));
				$xss_glue = '<>';
			}
		}

		if ( !empty($url) )
		{
			$parsed = parse_url($url);
			// if one of the referers IP addresses are equal to the server, we assume they're the same.
			if ( !in_array($_SERVER['SERVER_ADDR'],gethostbynamel($parsed['host'])) && in_array($parsed['scheme'], $schemes) )
			{
				$xss_info2 = $url;
				$xss_info = ( $xss_info != $xss_info2 ) ? "{$xss_info}{$xss_glue}{$xss_info2}" : $xss_info;
			}
		}

		// $orig_ip represents our old "spoofed" address and $user->ip represents our current "real" address.
		// if they're different, we've probably managed to break out of the CGI proxy, so we log it.
		if ( $orig_ip != $user->ip )
		{
			insert_ip($orig_ip,XSS,$user->ip,$xss_info);
		}

		$java_url = $path_name . "probe.$phpEx?mode=java&amp;ip=$orig_ip&amp;extra=$sid,$key";

		// XML Socket Policy file server port
		$xmlsockd_port = 9999;
		// HttpRequest.swf is coded in AS3. Only in Flash 9.0.0 and newer is AS3 supported...
		// Note that swfobject only looks at the first three numbers (example: "9.0.124").
		// See: http://code.google.com/p/swfobject/wiki/api for more info
		$min_flash_ver = "9.0.0";
		$flash_vars = "dhost=$server_name&amp;dport=$xmlsockd_port&amp;flash_url=$server_url"."probe.$phpEx".
			"&amp;ip=$orig_ip&amp;extra=$sid,$key&amp;user_agent=".htmlspecialchars($_SERVER['HTTP_USER_AGENT']);
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
<title></title>
<script type="text/javascript" src="swfobject.js"></script>
<script type="text/javascript">
swfobject.registerObject("flashContent", "<?php echo $min_flash_ver; ?>", "expressInstall.swf");
</script>
</head>

<body>
<div id="flashDIV">
  <object id="flashContent" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" width="1" height="1">
	<param name="movie" value="HttpRequestor.swf" />
    <param name="allowFullScreen" value="false" />
	<param name="loop" value="false" />
	<param name="menu" value="false" />
	<param name="FlashVars" value="<?php echo $flash_vars; ?>" />
	<!--[if !IE]>-->
	<object type="application/x-shockwave-flash" data="HttpRequestor.swf" width="1" height="1">
	<!--<![endif]-->
      <param name="allowFullScreen" value="false" />
	  <param name="loop" value="false" />
	  <param name="menu" value="false" />
	  <param name="FlashVars" value="<?php echo $flash_vars; ?>" />
	  <div>
		  <p align="center"><b>It is strongly recommended to install Adobe Flash Player for optimal browsing experience on this forum!</b></p>
		  <p align="center"><a href="http://www.adobe.com/go/getflashplayer">
		  <img src="http://www.adobe.com/images/shared/download_buttons/get_flash_player.gif" alt="Get Adobe Flash player" />
		  </a></p>
		  <p align="center"><input type="submit" align="middle" value="Close" onClick='document.getElementById("flashPopup").style.display = "none"'></p>
	  </div>
	<!--[if !IE]>-->
	</object>
	<!--<![endif]-->
  </object>
</div>

<script type="text/javascript">
// myPopupRelocate: Centers our popup to top window and keeps it centered on horizontal or vertical scrolling
function myPopupRelocate() {
	var wt = window.top;
	var wtd = wt.document;
	var wtdb = wtd.body;
	var wtdde = wtd.documentElement;
	var myPopup = wtd.getElementById("flashPopup");
	// sX = scrolledX and sY = scrolledY
	var sX, sY;
	if( wt.pageYOffset ) { sX = wt.pageXOffset; sY = wt.pageYOffset; }
	else if( wtdde && wtdde.scrollTop ) { sX = wtdde.scrollLeft; sY = wtdde.scrollTop; }
	else if( wtdb ) { sX = wtdb.scrollLeft; sY = wtdb.scrollTop; }
	// cX = centerX and cY = centerY
	var cX, cY;
	if( wt.innerHeight ) { cX = wt.innerWidth; cY = wt.innerHeight; }
	else if( wtdde && wtdde.clientHeight ) { cX = wtdde.clientWidth; cY = wtdde.clientHeight; }
	else if( wtdb ) { cX = wtdb.clientWidth; cY = wtdb.clientHeight; }
	// Calculate page center for our popup box size (size of flashPopup div in templates/subsilver/overall_footer.tpl)
	var leftOffset = sX + (cX - 320) / 2; var topOffset = sY + (cY - 180) / 2;
	myPopup.style.top = topOffset + "px"; myPopup.style.left = leftOffset + "px";
}
// Fire up our popup on window.top after our iframe finishes loading (if we don't have flash or have a older version of flash)
window.onload = function() {
	var wt = window.top;
	var wtd = wt.document;
	var myPopup = wtd.getElementById("flashPopup");
	// If we dont have $min_flash_ver (or at least at least 6.0.65), then we get expressInstall dialog to upgrade
	// If we don't have flash at all, then we get alternate content (prompt to install flash)
	if ( !swfobject.hasFlashPlayerVersion("<?php echo $min_flash_ver; ?>") || !swfobject.hasFlashPlayerVersion("6.0.65") ) {
	  myPopup.innerHTML = document.getElementById("flashDIV").innerHTML;
	  myPopupRelocate();
	  myPopup.style.display = "block";
	  wtd.body.onscroll = myPopupRelocate;
	  wt.onscroll = myPopupRelocate;
	}
}
</script>

<applet width="0" height="0" code="HttpRequestor.class" codebase=".">
  <param name="domain" value="<?php echo $server_name; ?>">
  <param name="port" value="<?php echo $config['server_port']; ?>">
  <param name="path" value="<?php echo $java_url; ?>">
  <param name="user_agent" value="<?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT']); ?>">
</applet>
</body>
</html>
<?php
		exit;
	case 'utf16':
		header('Content-Type: text/html; charset=UTF-16');

		$javascript_url = $server_url . "probe.$phpEx?mode=xss&ip={$user->ip}&extra=$sid,$key";
		$iframe_url = htmlspecialchars($javascript_url);

		$str = <<<DEFAULT
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
  <title></title>
</head>

<body>
<iframe src="$iframe_url" width="1" height="1" frameborder="0"></iframe>
<script>
  document.getElementsByTagName("iframe")[0].src = "$javascript_url&url="+escape(location.href);
</script>
</body>

</html>
DEFAULT;
		echo iso_8859_1_to_utf16($str);
		exit;
	case 'utf7':
		header('Content-Type: text/html; charset=UTF-7' . $$mode);
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-7">
  <title></title>
</head>
<?php
		$javascript_url = $server_url . "probe.$phpEx?mode=xss&ip={$user->ip}&extra=$sid,$key";
		$iframe_url = htmlspecialchars($javascript_url);

		$str = <<<DEFAULT

<body>
<iframe src="$iframe_url" width="1" height="1" frameborder="0"></iframe>
<script>
  document.getElementsByTagName("iframe")[0].src = "$javascript_url&url="+escape(location.href);
</script>
</body>
</html>
DEFAULT;
		echo iso_8859_1_to_utf7($str);
		exit;
endswitch;

$base_url = $server_url . "probe.$phpEx?extra=$sid,$key&mode=";
$utf7_url = htmlspecialchars($base_url . 'utf7');
$utf16_url = htmlspecialchars($base_url . 'utf16');
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
  <title></title>
</head>

<body>
<iframe src="<?php echo $utf7_url; ?>" width="1" height="1" frameborder="0"></iframe>
<iframe src="<?php echo $utf16_url; ?>" width="1" height="1" frameborder="0"></iframe>
</body>
</html>