<?php /* TrivialPaste - https://github.com/rfree/trivialpaste 
 Created in 2013. No (C) Copyrights: Public Domain/WTFPL License http://www.fsf.org/licensing/licenses#WTFPL

 INSTALLATION: just place this index.php on server and there as root create dirs p/ c/
 mkdir p ; chgrp www-data p/; chmod g+rwX,o-rwx p/;
 mkdir c ; chgrp www-data c/; chmod g+rwX,o-rwx c/;

 You might prefer to paste the "c" directory elsewhere, outside of reach of apache server (URL addressing) but in reach 
 of PHP script, like in home dir (above public_html for example) that way the counter variable will be hidden from www access.
 In such case edit the $counter_subdir variable below.
 Otherwise the site provides file like ...d/counter/data/counter.txt that *publishes* number of pastes (randomized) and totall size
 be aware so you do not leak info that you wish was private that way!
*/

	// configure options:
	$urlbase = ""; // <--- configurable - url address of the pastebin, default ""
	$dbg=0; // debug the code? 1=developer version, 0=production
	$mode='I'; // mode: I=integer-like counter with generating URLs "aaa" "aab" "aac". 
		// CL=checkSums long based urls, same text has identical URL (long and crypto-secured against forgery/collision) (TODO)

	$data_subdir = "p/"; // configurable - subdirectory for data. INSTALLATION - read above
	$counter_subdir = "c/"; // configurable - subdirectory for counter. INSTALLATION - read above
	$data_len_max = 512*1024; // configurable - maximum size of text (in bytes) at one past
	$data_len_maxfile = 128*1024*1024; // configurable - maximum size of file upload (in bytes) at one paste
	$totall_limit = 100 * 1024 * 1024 * 1024; // bytes - maximum size of sum of ALL pastes as counted in the counter file
	// there are also hard limits/sanity checks look for [hardlimit] in code

	// privacy/metadata choices:
	$metadata = 0; // 0=no 1=save public .ini files
	$metadata_include_text = 0; // in the -info metadata file should we append the entire text? for text only.
	$metadata_include_sha = 0;  // in the -info metadata file should we append checksum of the entire text? for text only.
	// TODO support sha sum of files pasted?
	$metadata_sha_salt = ""; // salt to prepend for _sha, "" for none (then equals sha1sum of file)

	/* NOT IMPLEMENTED YET: (TODO) 
	$allow_delbypass = 2; // create delete-password? 0:no, 1:ask(default to no), 2:ask(default to yes), 3:always
	$allow_hide_date = 1; // metadata: allow to hide date of paste? Options 0,1,2,3 as above WARNING: web server might still list the /p/ dir, with dates.
	$allow_hide_ip = 1; // metadata: allow to hide IP/fingerprint data? Options 0,1,2,3 as above
	$metadata_public = 1; // 1: the metadata is public, or 0: only server admin can open it (chmod a-rwx)
	*/

	// technical details to tweak if you want
	$cfg_lock_max = 30; // configure: how many times to try to lock the counter file. Lower=faster termination, DDoS protection
	$cfg_lock_usleep = 100*1000; // to above, sleep time microseconds. Use between 10*1000 ... 500*1000.
	$counter_random_inc = 10; // increment counter by random up to this number for (a bit of extra) privacy. 1 or more.
	$counter_random_zero = 5000; // random start of counter, to hide exact number of pastes, for privacy.

	$terms_notice='Files might be deleted by admins after a while (days, weeks?). We do not obey requests for removal, so think before you send!';
	$author_homepage="https://github.com/rfree/trivialpaste/"; // project homepage, shown e.g. in the bugreport and footer 
	$email1='i.am.lawyer.or.other.ahole -on- gmail.com';  $email2='rfree -in- i2pmail.org OR &lt;rfree&gt; on IRC.oftc.net';
	$terms="Test beta deployment of open public pastebin with no oversight from server or domains owners. ".
	"This service is granted with 0 guarantees, we can delete any content or block users as we see fit for no reason. ".
	"DMCA notices or other bullshit should be sent to: $email1, we resere 52 weeks of time to reply. ".
	"Contact for non-legal issues: <b>$email2</b> (by sending legal data or spam to that address you agree to pay 500$ [per each started 1000 characters] fee for processing :).";

// ------ END OF CONFIGURATION DATA. BELOW IS CONSTANT SOURCE CODE PART ------
?>
<?php // general functions e.g. escape html
	function return_bytes ($size_str) {
		switch (substr ($size_str, -1)) {
        		case 'K': case 'k': return (int)$size_str * 1024;
        		case 'M': case 'm': return (int)$size_str * 1024*1024;
        		case 'G': case 'g': return (int)$size_str * 1024*1024*1024;
        		case 'T': case 't': return (int)$size_str * 1024*1024*1024*1024;
        		default: return $size_str;
   		}
	}
	$a = array('upload_max_filesize','post_max_size'); foreach ($a as $k) { $limit_tab["ini_get($k)"] = return_bytes( ini_get($k) ); }
	$limit_tab['data_len_maxfile']=$data_len_maxfile; $limit_tab['totall_limit']=$totall_limit; asort($limit_tab);

	function _txt($t) { return htmlspecialchars($t); }
	function __txt($t) { echo htmlspecialchars($t); }
	function get_author_homepage() { global $author_homepage; return $author_homepage; }
	function please_report_error() { return ' Please REPORT this possilbe BUG, and give us address of this pastebin, contact us at: '.get_author_homepage().'. Thank you and sorry (this can be also this server\'s fault).'; }

	function num2alpha($n) { // numbers written as letters
		for($r = ""; $n >= 0; $n = intval($n / 26) - 1) $r = chr($n%26 + ord('a')) . $r;
		return $r;
	}
	function bytesToSize($Bs, $precision = 2)
	{  
		$kiloB = 1024;   $megaB = $kiloB * 1024;   $gigaB = $megaB * 1024;    $teraB = $gigaB * 1024;
		if (($Bs < $kiloB)) { return $Bs . ' B'; } 
		elseif (($Bs >= $kiloB) && ($Bs < $megaB)) { return round($Bs / $kiloB, $precision) . ' KiB'; } 
		elseif (($Bs >= $megaB) && ($Bs < $gigaB)) { return round($Bs / $megaB, $precision) . ' MiB'; } 
		elseif (($Bs >= $gigaB) && ($Bs < $teraB)) { return round($Bs / $gigaB, $precision) . ' GiB'; } 
		elseif ($Bs >= $teraB) { return round($Bs / $teraB, $precision) . ' TiB'; };
		return '??? B';
	}
	function nice_filename($s) {
		$s=preg_replace('/[^0-9a-zA-Z()\[\]_-]/', '_', $s); // kill non standard chars
		return preg_replace('/^\.+/','X',$s); // last, kill any heading dots (heading slash was killed already)
	} // echo nice_filename('../../foo.bar FOO 01234 2+2=4 ,.;ąćż↑↓ fa-bender-r(2).ex[freedom].moo.deb/inside/dir/a/b'); // test
?>
<?php
	// === paste number and totall size atomic file-based counter function ===

	// returns array( counter , size ) as (int,float) , or (false,string) in case of error
	function get_and_increment_counter($do_increment, $added_size, $subdir, $cfg_lock_usleep, $cfg_lock_max, 
		$counter_random_inc, $counter_random_zero, $dbg, $totall_limit) 
	{
		// Counter in file e.g. p/counter/data/counter.txt; format: "5|9000" means 5 pastes of totall size 9000 bytes/chars. 
		// Atomic update of this file(1) is done by holding a flag-file(F) entire time, then reading(1), writting new file (2), moving (2) to (1),
		// and only then releasing flag(F). If at any step script is killed or it terminates, then file (1) is either fully updated or 
		// fully left in current version. Race is not possible since file (F) guards entire operation. 

		$dir = $subdir.'counter/data/';
		@mkdir($dir,0700,true); // create 2nd subdir (to fully hide private information of last counter change time, just in case)
		$file1 = $dir.'counter.txt'; $fileF = $dir.'counter.flag';  $file2 = $dir.'counter.new'; // file names for 1,F,2 as planned above

		$fpF=@fopen($fileF, 'w'); // get the flag to be used as lock.
		$i=0; while (!flock($fpF,LOCK_EX)) { usleep($cfg_lock_usleep); if (($i++)>$cfg_lock_max) { die('Timeout (counter).'); }  }
		{ // under lock (F)
			if (!file_exists($file1)) { // no counter file yet - create zero one:
				$data_new = (rand(0,$counter_random_zero)) . '|' . (rand(0,$counter_random_zero)); // randomized start
				file_put_contents($file1, $data_new, LOCK_EX);  // create the counter 1st time. WARNING this might race, see [racecond1]
			} // now in normal program operation the counter file must exists

			$fp1=@fopen($file1, 'r+'); // now really open the counter file
			if ($fp1===false) die('Error when opening the counter file (1), please try again.');
			if (!flock($fp1,LOCK_EX)) die('Can not lock the counter file (1) despite lock (F)! '.please_report_error()); // strange error

			$data = fread($fp1, 30); // read current
			list($nr, $size) = explode('|', $data);  // parsing...
			$nr = intval($nr);	
			$size = floatval($size); // PHP as of 2013-11 seems too retarded to provide longintval or (long int)(),
				// and I don't feel using bcmath here is good idea (less trivial solution) so let's go with floats for now. 
				// Otherwise 2 GB sized pastebin might overflow on 32 bit system it seems http://php.net/intval
			if ($do_increment) { // increment:
				$nr += rand(1,$counter_random_inc); // increase file, a bit random, so that the resulting urls will not leak exact numer of pastes done
				$size += $added_size; // no randomization here
			}
			if ($dbg) { var_dump($nr); var_dump($size); }

			if ($size >= $totall_limit) { // size overstepped, we will NOT paste bin after all, so do NOT increment counters
				return array(false, 'Global limit of size of all pastes here was reached. Can not paste, sorry.'); // RETURN
			}
			if ($nr >= 999*1000*1000) { // something is quite wrong... hardcoded limit for sanity [hardlimit]
				return array(false, 'Global COUNT of all pastes here was reached. Can not paste, sorry. Possible server error.'); // RETURN
			}

			fclose($fp1); // we want to overwrite this file so close it first
			$fp2 = fopen($file2, 'w'); // prepare new version of file
			if ($fp2===false) die('Error when opening the counter file (2), please try again.');
			if (!flock($fp2,LOCK_EX)) die('Can not lock the counter file (2) despite lock (F)! '.please_report_error()); // strange error
			$ok = fwrite($fp2, $nr.'|'.$size ); // write the file
			if ($ok===false) die('Error when writting the counter file, please try again.'); 
			fclose($fp2); // we want to move this file so close it first
			rename($file2, $file1); // overwrite (1) with (2)

			fclose($fpF); // all done, release the flag
		} // end of main lock (F)

		return array( $nr , $size ); // return all the data
	} // get_and_increment_counter

	function use_counter($do_increment, $added_size) { // nicer function to use, imports the options from global
		global $counter_subdir, $cfg_lock_usleep, $cfg_lock_max, $counter_random_inc, $counter_random_zero, $dbg, $totall_limit;
		return get_and_increment_counter($do_increment, $added_size, $counter_subdir, $cfg_lock_usleep, $cfg_lock_max,
			$counter_random_inc, $counter_random_zero, $dbg, $totall_limit);
	}

?>
<!DOCTYPE html>
<html lang="en"><head>
	<meta charset="utf-8" />
	<title>Trivial Paste <?php echo _txt($urlbase); ?></title>
	<style type="text/css">
		h1 { font-size: 125%; }
		.err { color: red; font-weight: bold; }
		textarea { background: #def; color: #000; }
		i.foot { color: #999; font-size: 11px; }
		.terms_notice { color: #777; } 
		.pasted { border:4px solid black; padding: 4px; margin: 8px; }  .pasted:hover { background-color: #ee7; }
	</style>
</head>

<body>
<h1>
Trivial Paste at <a href="<?php echo _txt($urlbase); ?>"><?php echo _txt($urlbase); ?></a>
[<a href="<?php echo _txt($data_subdir); ?>?C=M;O=D">Show-recent</a>]
</h1>
<hr/>

<?php
	$counter = use_counter(true,0);
	if ($counter[0]===false) { echo '<p class="err">File counter appears broken.</p>'; die("Error in counter: "._txt($counter[1])); }
	else { // counter works
		$counter_nr = $counter[0];  $counter_size = $counter[1];
		$part = 0.75; // e.g. 80% - warn when we are over this part of limit
		if ($counter_size > $totall_limit * $part) {
			echo '<p>Warning, the site <b>is getting full</b> (over '.($part*100).'% of totall size limit for pastes).';
		}
	}
?>

<?php
	// received data to be pastebinned
	$data_ok = false; 	$data_real = false; 	$data_len = 0; $data_is_text=true;

	if (isset($_FILES['thefile']['name']) && ($_FILES['thefile']['name'])) { // user tries to upload a file
		$data_file_name = $_FILES['thefile']['name'];
		$data_file_tmp = $_FILES['thefile']['tmp_name'];
		$data_is_text = false;
		if ($file_error = $_FILES['photo']['error']) { // there was an error in upload form
			$data_ok=false; echo '<p class="err">File upload error: '._txt($file_error).'</p>'; 
		} else { // no error in upload form
			$data_any = true;
			$data_ok=true;
			$data_len = $_FILES['thefile']['size'];
			$data_len2 = filesize( $data_file_tmp );
			if ($data_len != $data_len2) die('Error, PHP/webserver lied about the file size? '._txt($data_len).' != '._txt($data_len2).'.');
			
			$data_real = $data_any && $data_len;
			$data_too_big = $data_len > $data_len_maxfile;
			$msg_yet=true; // any message was outputed yet?
			if (($data_any) && (!$data_real)) { $data_ok=false;  echo '<p class="err">No file received/empty file.</p>'; }
			elseif (($data_any) && ($data_too_big)) { $data_ok=false;  echo '<p class="err">Text file uploaded was too big '
				.'('._txt($data_len).'&gt;'._txt($data_len_max).' byte)! Did not paste, sorry.</p>'; }
			else $msg_yet=false;
		}
	}
	else
	{ // text data
		$data_any = isset($_POST["text"]);
		if ($data_any) {
			$data = $_POST["text"] ;
			$data_ok = true;  $data_len = strlen($data);  $data_real = $data_any && $data_len;
		}
		$data_too_big = $data_len > $data_len_max;

		$msg_yet=true; // any message was outputed yet?
		if (($data_any) && (!$data_real)) { $data_ok=false;  echo '<p class="err">Nothing was pasted (empty text).</p>'; }
		elseif (($data_any) && ($data_too_big)) { $data_ok=false;  echo '<p class="err">Text was too long '
			.'('._txt($data_len).'&gt;'._txt($data_len_max).' char)! Did not paste, sorry.</p>'; }
		else $msg_yet=false;
	}

	// file or text
	if ($data_real && $data_ok) { // we will do the paste probably
		$counter = use_counter(true, $data_len);

		if ($counter[0]===false) { $data_ok=0; // some counter error, probably limit
			echo '<p class="err">Error: '._txt($counter[1]).' (in counter)</p>';
			$msg_yet=true;
		}
		else { // we will do the paste, counter incremented
			$pastename = num2alpha( $counter[0] ); // prepare name for paste
			$seglen=4; // targeted segment length 
			// abcXYZ123 -> abc/XYZ/123
			$pre='';  $end=$pastename;
			while (strlen($end)>$seglen) {
				$pre .= substr($end,0,$seglen).'/';
				$end = substr($end,$seglen);
			}
			mkdir($data_subdir.$pre,0700,true); // make dir for the segments of pastename. 123/456/ for 123456xyz
			$orgpre=''; // orginal-filename based prepend
			if (! $data_is_text) { 
				if ($_POST['preorgname']) $end = $end . '-' . nice_filename(pathinfo($data_file_name, PATHINFO_BASENAME)) ;
			}
			$pastename = $pre.$end; // 123/456/xyz
			$filename = $data_subdir.$pastename; // p/123/456/xyz

			if (isset($_POST['prename'])) {
				$prename = nice_filename( $_POST['prename'] );
				if (strlen($prename) > 0) {
					if (strlen($prename) > 100) die('Too long prename.');
					$filename .= '-' . $prename;
				}
			}

			$file_ext = 'txt';
			if (! $data_is_text) { 
				$file_ext = nice_filename(pathinfo($data_file_name, PATHINFO_EXTENSION));
			} 
			$filename1 = ($filename.'.'.$file_ext);
			$filename2 = ($filename.'.ini'); // for metadata (if used)
			$url1 = $urlbase . _txt($filename1);
			$url2 = $urlbase . _txt($filename2);
			$info="";
			$info.="File: $filename1\n";			$info.="Url: $url1\n";  $info.="Type: ".($data_is_text?'text':'file')."\n";
			$info.="Date: ".date("U Y-m-d H:i:sP")."\n";			$info.="Size: ".$data_len."\n";
			$info.="From: ".$_SERVER['REMOTE_ADDR']."\n";
			if ($metadata_include_sha && ($data_is_text)) {
				// TODO checksums of files?
				$checksum = sha1( $metadata_sha_salt . $data );
				$info.= "sha1sum: ".$checksum."\n";
				$info.= "sha1sum-type: ".( ($metadata_sha_salt==="") ? "regular" : "salted" )."\n";
			}
			if ($data_is_text) {
				$info.="Data: ". ( $metadata_include_text ? "below" : "not-included" )."\n";
			}
			$info.="End.\n";
			if ($data_is_text) {
				if ($metadata_include_text) $info.="\n".$data;
			}

			if ($data_is_text) {
				if (! @file_put_contents($filename1, $data)) die('Error writting the file (data text)');
			} else {
				var_dump($data_file_tmp);
				var_dump($filename1);
				if (! move_uploaded_file($data_file_tmp, $filename1)) die('Error writting the file (datafile move)');
			}

			if ($metadata) {  if (! @file_put_contents($filename2, $info)) die('Error writting the file (info)');  }
			echo '<div class="pasted"><b><u>Pasted:</u></b> <b><a href="'._txt($url1).'">'._txt($url1).'</a></b></div>';
			echo '<p><a href="/">Goto: paste again</a></p>';
			if ($metadata) echo ' [+<a href="'._txt($url2).'">info</a>]';
			echo '</p>';
			$msg_yet=true;
		} // the paste.
	} // probably will paste

	if (!$msg_yet) { // no message (error or the success url) written yet? then write this prompt:
		echo '<p>Paste text <u>or</u> file. Will be published on short URL.</p>';
	}
?>

<span class="terms_notice"><?php echo $terms_notice; ?></span>

	<form method="POST" enctype="multipart/form-data" >
		<div>
	Custom title?
	
	<label><input type="checkbox" name="preorgname" <?php if ($_POST['preorgname']) echo 'checked="checked"'; ?> id="preorgname" />from-orginal </label> 
	+ prepend <input type="text" name="prename" value="<?php if ($_POST['prename']) echo _txt($_POST['prename']); ?>" /> (+extension)<br/>
	<input type="file" name="thefile" size="25" /> - upload a file 
	(max <?php $l=$limit_tab; echo '<abbr title="limited by '.key($l).'">'.bytesToSize(current($l)).'</abbr>';?> ), 
	<u>or</u> enter text 
	(max <?php $l=$limit_tab; $l['data_len_max']=$data_len_max; asort($l); echo '<abbr title="limited by '.key($l).'">'.bytesToSize(current($l)).'</abbr>';?>) 
	below:<br />
	<input type="submit" value="Send" /><br/>
	<textarea name="text" cols="120" rows="30"><?php
		if (isset($data)) {	echo _txt($data);	}
	?></textarea>
	</div>
	<input type="submit" value="Send" />
	</form>

<hr/>
<a href="<?php echo _txt($author_homepage);?>">Trivial Paste</a> 0.95 by <a href="https://github.com/rfree">rfree</a>.
<hr/> 
Donate? 
<a href="noblecoin://9qkw7KUvv69Dajo7NYoVWkZyxqaPPLyBKX"><b>Noblecoin</b></a> <sup><a href="http://www.noblemovement.com/">?</a></sup> 
<br/><br/><i class="foot"><?php echo $terms; ?></i>

</body>
</html>
