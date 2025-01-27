<?php
/********************************
Simple PHP File Manager
Copyright John Campbell (jcampbell1)

Liscense: MIT
********************************/

//Disable error report for undefined superglobals
error_reporting( error_reporting() & ~E_NOTICE );

//Security options
$allow_delete = true; // Set to false to disable delete button and delete POST request.
$allow_create_folder = true; // Set to false to disable folder creation
$allow_upload = true; // Set to true to allow upload files
$allow_direct_link = true; // Set to false to only allow downloads and not direct link
// Sometimes you may need to hide sensitive files. Add them here
$files_to_skip = array(
    '.',
    '..',
    'index.php'
);
// If this script is not at the root of your website, you can specify a starting breadcrumb used to get to this page.
// in the format 'display name' => 'relative path'. NOTE: Remember to end with a crumb relative path equal to '#'.
$breadcrumb_base = array('files' => '#');

/* Uncomment section below, if you want a trivial password protection */

/*
$PASSWORD = 'sfm'; 
session_start();
if(!$_SESSION['_sfm_allowed']) {
	// sha1, and random bytes to thwart timing attacks.  Not meant as secure hashing.
	$t = bin2hex(openssl_random_pseudo_bytes(10));	
	if($_POST['p'] && sha1($t.$_POST['p']) === sha1($t.$PASSWORD)) {
		$_SESSION['_sfm_allowed'] = true;
		header('Location: ?');
	}
	echo '<html><body><form action=? method=post>PASSWORD:<input type=password name=p /></form></body></html>'; 
	exit;
}
*/

// must be in UTF-8 or `basename` doesn't work
setlocale(LC_ALL,'en_US.UTF-8');

$tmp = realpath(urldecode($_REQUEST['file']));
if($tmp === false)
	err(404,'File or Directory Not Found');
if(substr($tmp, 0,strlen(__DIR__)) !== __DIR__)
	err(403,"Forbidden");

if(!$_COOKIE['_sfm_xsrf'])
	setcookie('_sfm_xsrf',bin2hex(openssl_random_pseudo_bytes(16)));
if($_POST) {
	if($_COOKIE['_sfm_xsrf'] !== $_POST['xsrf'] || !$_POST['xsrf'])
		err(403,"XSRF Failure");
}

$file = urldecode($_REQUEST['file']) ?: '.';
if($_GET['do'] == 'list') {
	if (is_dir($file)) {
		$directory = $file;
		$result = array();
		$files = array_diff(scandir($directory), $files_to_skip);
	    foreach($files as $entry) if($entry !== basename(__FILE__)) {
    		$i = $directory . '/' . $entry;
	    	$stat = stat($i);
            $size = 0;
	    	if(is_dir($i)){
                $size = directorySize($i);
            } else {
                $size = filesize2($i);
            }
	        $result[] = array(
	        	'mtime' => $stat['mtime'],
	        	'size' => $size,
	        	'name' => basename($i),
	        	'path' => preg_replace('@^\./@', '', $i),
	        	'is_dir' => is_dir($i),
	        	'is_deleteable' => $allow_delete && ((!is_dir($i) && is_writable($directory)) ||
                                                           (is_dir($i) && is_writable($directory) && is_recursively_deleteable($i))),
                'unix_owner' => posix_getpwuid(fileowner($i))['name'] . ':' . posix_getgrgid(filegroup($i))['name'],
	        	'unix_perms' => getUnixPerms($i),
	        	'is_writable' => is_writable($i),
	        );
	    }
	} else {
		err(412,"Not a Directory");
	}
	echo json_encode(array('success' => true, 'is_writable' => is_writable($file), 'results' =>$result));
	exit;
} elseif ($_POST['do'] == 'delete') {
	if($allow_delete) {
		rmrf($file);
	}
	exit;
} elseif ($_POST['do'] == 'mkdir' && $allow_create_folder== true) {
	// don't allow actions outside root. we also filter out slashes to catch args like './../outside'
	$dir = $_POST['name'];
	$dir = str_replace('/', '', $dir);
	if(substr($dir, 0, 2) === '..')
	    exit;
	chdir($file);
	@mkdir($_POST['name']);
	exit;
} elseif ($_POST['do'] == 'upload' && $allow_upload == true) {
	var_dump($_POST);
	var_dump($_FILES);
	var_dump($_FILES['file_data']['tmp_name']);
	var_dump(move_uploaded_file($_FILES['file_data']['tmp_name'], $file.'/'.$_FILES['file_data']['name']));
	exit;
} elseif ($_GET['do'] == 'download') {
	$filename = basename($file);
	header('Content-Type: ' . detectFileMimeType($file));
	header('Content-Length: '. filesize($file));
	header(sprintf('Content-Disposition: attachment; filename=%s',
		strpos('MSIE',$_SERVER['HTTP_REFERER']) ? rawurlencode($filename) : "\"$filename\"" ));
	ob_flush();
	readfile($file);
	exit;
}

function filesize2($path) {
    $sizeInBytes = (string)@filesize($path);
    if(strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN'){
        if($sizeInBytes === false || $sizeInBytes < 0){
            $command = "/bin/ls -l " . escapeshellarg($path);
            $ret = trim(exec($command));
            if(!substr_count($ret, "ls:") && !substr_count($ret, "No such file")){
                $arr = preg_split('/\s+/', $ret);
                $sizeInBytes = $arr[4];
            }
        }
    }
    return $sizeInBytes;
}
function directorySize($path) {
    $size = 0;
    $path = escapeshellarg($path);
    if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'){
        $obj = new COM('scripting.filesystemobject');
        if(is_object($obj)){
            $ref = $obj->getfolder($path);
            $size = $ref->size;
            $obj = null;
        }
    }else{
        $io = popen('/usr/bin/du -sk -B1 ' . $path, 'r');
        $tempSize = fgets($io, 4096);
        $size = substr($tempSize, 0, strpos($tempSize, "\t"));
        pclose($io);
    }
    return $size;
}
// As mime_content_type() fails on Windows
function detectFileMimeType($filename='')
{
    $filename = escapeshellarg($filename);
    $command = "file -b --mime-type -m /usr/share/misc/magic {$filename}";
    $mimeType = shell_exec($command);
    return trim($mimeType);
}
function rmrf($dir) {
	if(is_dir($dir)) {
		$files = array_diff(scandir($dir), array('.','..'));
		foreach ($files as $file)
			rmrf("$dir/$file");
		rmdir($dir);
	} else {
		unlink($dir);
	}
}
function is_recursively_deleteable($d) {
	$stack = array($d);
	while($dir = array_pop($stack)) {
		if(!is_readable($dir) || !is_writable($dir)) 
			return false;
		$files = array_diff(scandir($dir), array('.','..'));
		foreach($files as $file) if(is_dir($file)) {
			$stack[] = "$dir/$file";
		}
	}
	return true;
}

function err($code,$msg) {
	echo json_encode(array('error' => array('code'=>intval($code), 'msg' => $msg)));
	exit;
}

function asBytes($ini_v) {
	$ini_v = trim($ini_v);
	$s = array('g'=> 1<<30, 'm' => 1<<20, 'k' => 1<<10);
	return intval($ini_v) * ($s[strtolower(substr($ini_v,-1))] ?: 1);
}

function getUnixPerms($path) {
    $perms = fileperms($path);

    switch ($perms & 0xF000) {
        case 0xC000: // socket
            $info = 's';
            break;
        case 0xA000: // symbolic link
            $info = 'l';
            break;
        case 0x8000: // regular
            $info = '-';
            break;
        case 0x6000: // block special
            $info = 'b';
            break;
        case 0x4000: // directory
            $info = 'd';
            break;
        case 0x2000: // character special
            $info = 'c';
            break;
        case 0x1000: // FIFO pipe
            $info = 'p';
            break;
        default: // unknown
            $info = 'u';
    }

// Owner
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ?
        (($perms & 0x0800) ? 's' : 'x' ) :
        (($perms & 0x0800) ? 'S' : '-'));

// Group
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ?
        (($perms & 0x0400) ? 's' : 'x' ) :
        (($perms & 0x0400) ? 'S' : '-'));

// World
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ?
        (($perms & 0x0200) ? 't' : 'x' ) :
        (($perms & 0x0200) ? 'T' : '-'));

    return $info;
}

$MAX_UPLOAD_SIZE = min(asBytes(ini_get('post_max_size')), asBytes(ini_get('upload_max_filesize')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Simple PHP File Manager</title>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<meta name="robots" content="noindex, nofollow">
<style>
body {font-family: "lucida grande","Segoe UI",Arial, sans-serif; font-size: 14px;width:1024px;padding:1em;margin:0 auto;}
th {font-weight: normal; color: #1F75CC; background-color: #F0F9FF; padding:.5em 1em .5em .2em; 
	text-align: left;cursor:pointer;user-select: none;}
th .indicator {margin-left: 6px }
thead {border-top: 1px solid #82CFFA; border-bottom: 1px solid #96C4EA;border-left: 1px solid #E7F2FB;
	border-right: 1px solid #E7F2FB; }
#top {height:52px;}
#mkdir {display:inline-block;float:right;padding-top:16px;}
label { display:block; font-size:11px; color:#555;}
#file_drop_target {width:500px; padding:12px 0; border: 4px dashed #ccc;font-size:12px;color:#ccc;
	text-align: center;float:right;margin-right:20px;}
#file_drop_target.drag_over {border: 4px dashed #96C4EA; color: #96C4EA;}
#upload_progress {padding: 4px 0;}
#upload_progress .error {color:#a00;}
#upload_progress > div { padding:3px 0;}
.no_write #mkdir, .no_write #file_drop_target {display: none}
.progress_track {display:inline-block;width:200px;height:10px;border:1px solid #333;margin: 0 4px 0 10px;}
.progress {background-color: #82CFFA;height:10px; }
footer {font-size:11px; color:#bbbbc5; padding:4em 0 0;text-align: left;}
footer a, footer a:visited {color:#bbbbc5;}
#breadcrumb { padding-top:34px; font-size:15px; color:#aaa;display:inline-block;float:left;}
#folder_actions {width: 50%;float:right;}
a, a:visited { color:#00c; text-decoration: none}
a:hover {text-decoration: underline}
.sort_hide{ display:none;}
table {border-collapse: collapse;width:100%;}
thead {max-width: 1024px}
td { padding:.2em 1em .2em .2em; border-bottom:1px solid #def;height:30px; font-size:12px;white-space: nowrap;}
td.first {font-size:14px;white-space: normal;}
td.empty { color:#777; font-style: italic; text-align: center;padding:3em 0;}
.is_dir .download{color: #999}
a.delete {display:inline-block;
	background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAADtSURBVHjajFC7DkFREJy9iXg0t+EHRKJDJSqRuIVaJT7AF+jR+xuNRiJyS8WlRaHWeOU+kBy7eyKhs8lkJrOzZ3OWzMAD15gxYhB+yzAm0ndez+eYMYLngdkIf2vpSYbCfsNkOx07n8kgWa1UpptNII5VR/M56Nyt6Qq33bbhQsHy6aR0WSyEyEmiCG6vR2ffB65X4HCwYC2e9CTjJGGok4/7Hcjl+ImLBWv1uCRDu3peV5eGQ2C5/P1zq4X9dGpXP+LYhmYz4HbDMQgUosWTnmQoKKf0htVKBZvtFsx6S9bm48ktaV3EXwd/CzAAVjt+gHT5me0AAAAASUVORK5CYII=) no-repeat scroll 0 2px;
	color:#d00;	margin-left: 15px;font-size:11px;padding:0 0 0 13px;
}
.name {
	background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAYAAADgdz34AAABAklEQVRIie2UMW6DMBSG/4cYkJClIhauwMgx8CnSC9EjJKcwd2HGYmAwEoMREtClEJxYakmcoWq/yX623veebZmWZcFKWZbXyTHeOeeXfWDN69/uzPP8x1mVUmiaBlLKsxACAC6cc2OPd7zYK1EUYRgGZFkG3/fPAE5fIjcCAJimCXEcGxKnAiICERkSIcQmeVoQhiHatoWUEkopJEkCAB/r+t0lHyVN023c9z201qiq6s2ZYA9jDIwx1HW9xZ4+Ihta69cK9vwLvsX6ivYf4FGIyJj/rg5uqwccd2Ar7OUdOL/kPyKY5/mhZJ53/2asgiAIHhLYMARd16EoCozj6EzwCYrrX5dC9FQIAAAAAElFTkSuQmCC) no-repeat scroll 0 12px;
	padding:15px 0 10px 40px;
}
.is_dir .name {
	background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAADdgAAA3YBfdWCzAAAABl0RVh0U29mdHdhcmUAd3d3Lmlua3NjYXBlLm9yZ5vuPBoAAAI0SURBVFiF7Vctb1RRED1nZu5977VQVBEQBKZ1GCDBEwy+ISgCBsMPwOH4CUXgsKQOAxq5CaKChEBqShNK222327f79n0MgpRQ2qC2twKOGjE352TO3Jl76e44S8iZsgOww+Dhi/V3nePOsQRFv679/qsnV96ehgAeWvBged3vXi+OJewMW/Q+T8YCLr18fPnNqQq4fS0/MWlQdviwVqNpp9Mvs7l8Wn50aRH4zQIAqOruxANZAG4thKmQA8D7j5OFw/iIgLXvo6mR/B36K+LNp71vVd1cTMR8BFmwTesc88/uLQ5FKO4+k4aarbuPnq98mbdo2q70hmU0VREkEeCOtqrbMprmFqM1psoYAsg0U9EBtB0YozUWzWpVZQgBxMm3YPoCiLpxRrPaYrBKRSUL5qn2AgFU0koMVlkMOo6G2SIymQCAGE/AGHRsWbCRKc8VmaBN4wBIwkZkFmxkWZDSFCwyommZSABgCmZBSsuiHahA8kA2iZYzSapAsmgHlgfdVyGLTFg3iZqQhAqZB923GGUgQhYRVElmAUXIGGVgedQ9AJJnAkqyClCEkkfdM1Pt13VHdxDpnof0jgxB+mYqO5PaCSDRIAbgDgdpKjtmwm13irsnq4ATdKeYcNvUZAt0dg5NVwEQFKrJlpn45lwh/LpbWdela4K5QsXEN61tytWr81l5YSY/n4wdQH84qjd2J6vEz+W0BOAGgLlE/AMAPQCv6e4gmWYC/QF3d/7zf8P/An4AWL/T1+B2nyIAAAAASUVORK5CYII=) no-repeat scroll 0 10px;
	padding:15px 0 10px 40px;
}
tr:not(.is_dir) .download {
	background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAB2klEQVR4nJ2ST2sTQRiHn5mdmj92t9XmUJIWJGq9NHrRgxQiCtqbl97FqxgaL34CP0FD8Qv07EHEU0Ew6EXEk6ci8Q9JtcXEkHR3k+zujIdUqMkmiANzmJdnHn7vzCuIWbe291tSkvhz1pr+q1L2bBwrRgvFrcZKKinfP9zI2EoKmm7Azstf3V7fXK2Wc3ujvIqzAhglwRJoS2ImQZMEBjgyoDS4hv8QGHA1WICvp9yelsA7ITBTIkwWhGBZ0Iv+MUF+c/cB8PTHt08snb+AGAACZDj8qIN6bSe/uWsBb2qV24/GBLn8yl0plY9AJ9NKeL5ICyEIQkkiZenF5XwBDAZzWItLIIR6LGfk26VVxzltJ2gFw2a0FmQLZ+bcbo/DPbcd+PrDyRb+GqRipbGlZtX92UvzjmUpEGC0JgpC3M9dL+qGz16XsvcmCgCK2/vPtTNzJ1x2kkZIRBSivh8Z2Q4+VkvZy6O8HHvWyGyITvA1qndNpxfguQNkc2CIzM0xNk5QLedCEZm1VKsf2XrAXMNrA2vVcq4ZJ4DhvCSAeSALXASuLBTW129U6oPrT969AK4Bq0AeWARs4BRgieMUEkgDmeO9ANipzDnH//nFB0KgAxwATaAFeID5DQNatLGdaXOWAAAAAElFTkSuQmCC) no-repeat scroll 0 5px;
	padding:4px 0 4px 20px;
}
tr.is_dir .download {
    background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAB+UlEQVQ4T5WTsYsaQRTGv1l1N1lXCy+GYCUWNoZUsU4nJmAhChZCSGGTLt26awoJumqZIun8E64IoiFiIZJAigObg0AabYREyXLZdS/qqhNG9AjnHcaBx8CbeT/e980bghtWrVbTCSHC7ohSOpdl2XfTXXI9qWlaWBCEs3Q67eE4DtPpFM1m01wsFo9VVf1+/f6tgEwm43E4HDAMA41G43hAKpXaAEzTRKvVOg7A8/xZMpncSGCAdrt9PCCRSFx50Ol0TNu2/98D1kE8Ht9IYCZ2u93DgFwu95JS+t7lciEUCiEWi4EQgtlshl6vZw6Hw2/z+dxBKf1cr9df7V7j6hVKpdJHjuOeEELWkiSRaDQqMoBt2+j3+79N0+RZESGkqyjKsz1AuVx+yPP8l3A47JUkCax9ttbrNZbL5UbKYDC4mM1m7wqFwus9AEtomvZWFMUXwWDQuwNQSjddjEajS8uyThVFef7vMO0NUqVS+eB2u2N+v//OrgNd16llWef5fP7RwUksFotOQRDORVEMezweslqtMB6P14SQ+6qq/joI2Eo5oZT+CAQCzslkckEpfSrL8tfbPhNzywngLgARgJvtkUjkQTabbRiG8alarb4B8AfA5TYsAHMAK+YBtw1WfG8bJwA8Pp/Pq+s6K1wCMAD8BMBk6FsQ/QtFEvUWhGKiegAAAABJRU5ErkJggg==) no-repeat scroll 0 5px;
    padding:4px 0 4px 20px;
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"
        integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo=" crossorigin="anonymous"></script>
<script>
(function($){
	$.fn.tableSorter = function() {
		var $table = this;
		this.find('th').click(function() {
			var idx = $(this).index();
			var direction = $(this).hasClass('sort_asc');
			$table.tableSortBy(idx,direction);
		});
		return this;
	};
	$.fn.tableSortBy = function(idx,direction) {
		var $rows = this.find('tbody tr');
		function elementToVal(a) {
			var $a_elem = $(a).find('td:nth-child('+(idx+1)+')');
			var a_val = $a_elem.attr('data-sort') || $a_elem.text();
			return (a_val === parseInt(a_val) ? parseInt(a_val) : a_val);
		}
		$rows.sort(function(a,b){
			var a_val = elementToVal(a), b_val = elementToVal(b);
			return (a_val > b_val ? 1 : (a_val === b_val ? 0 : -1)) * (direction ? 1 : -1);
		});
		this.find('th').removeClass('sort_asc sort_desc');
		$(this).find('thead th:nth-child('+(idx+1)+')').addClass(direction ? 'sort_desc' : 'sort_asc');
		for(var i =0;i<$rows.length;i++)
			this.append($rows[i]);
		this.setTableSortMarkers();
		return this;
	};
	$.fn.reTableSort = function() {
		var $e = this.find('thead th.sort_asc, thead th.sort_desc');
		if($e.length)
			this.tableSortBy($e.index(), $e.hasClass('sort_desc') );
		
		return this;
	};
	$.fn.setTableSortMarkers = function() {
		this.find('thead th span.indicator').remove();
		this.find('thead th.sort_asc').append('<span class="indicator">&darr;<span>');
		this.find('thead th.sort_desc').append('<span class="indicator">&uarr;<span>');
		return this;
	}
})(jQuery);
$(function(){
	var XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)')||0)[2];
	var MAX_UPLOAD_SIZE = <?php echo $MAX_UPLOAD_SIZE ?>;
	$('.max-file-size').text(formatFileSize(MAX_UPLOAD_SIZE));
	var $tbody = $('#list');
	$(window).bind('hashchange',list).trigger('hashchange');
	$('#table').tableSorter().on('click', '.delete', function() {
    if (confirm("WARNING! \nPermanently delete "+$(this).attr('data-file')+" ?")) {
        $.post("", {'do': 'delete', file: $(this).attr('data-file'), xsrf: XSRF}, function () {
            list();
        }, 'json');
        return false;
    }
    else console.log('Aborted Delete');  
	});

	$('#mkdir').submit(function(e) {
		var hashVal = window.location.hash.substr(1),
			$dir = $(this).find('[name=name]');
		e.preventDefault();
		$dir.val().length && $.post('?',{'do':'mkdir',name:$dir.val(),xsrf:XSRF,file:hashVal},function(){
			list();
		},'json');
		$dir.val('');
		return false;
	});
<?php if($allow_upload == true): ?>
	// file upload stuff
	$('#file_drop_target').bind('dragover',function(){
		$(this).addClass('drag_over');
		return false;
	}).bind('dragend',function(){
		$(this).removeClass('drag_over');
		return false;
	}).bind('drop',function(e){
		e.preventDefault();
		var files = e.originalEvent.dataTransfer.files;
		$.each(files,function(k,file) {
			uploadFile(file);
		});
		$(this).removeClass('drag_over');
	});
	$('input[type=file]').change(function(e) {
		e.preventDefault();
		$.each(this.files,function(k,file) {
			uploadFile(file);
		});
	});


	function uploadFile(file) {
		var folder = window.location.hash.substr(1);
        var progress = $('#upload_progress');

		if(file.size > MAX_UPLOAD_SIZE) {
			var $error_row = renderFileSizeErrorRow(file,folder);
            progress.append($error_row);
			window.setTimeout(function(){$error_row.fadeOut();},5000);
			return false;
		}
		
		var $row = renderFileUploadRow(file,folder);
        progress.append($row);
		var fd = new FormData();
		fd.append('file_data',file);
		fd.append('file',folder);
		fd.append('xsrf',XSRF);
		fd.append('do','upload');
		var xhr = new XMLHttpRequest();
		xhr.open('POST', '?');
		xhr.onload = function() {
			$row.remove();
    		list();
  		};
		xhr.upload.onprogress = function(e){
			if(e.lengthComputable) {
				$row.find('.progress').css('width',(e.loaded/e.total*100 | 0)+'%' );
			}
		};
	    xhr.send(fd);
	}
	function renderFileUploadRow(file,folder) {
		return $('<div/>')
			.append( $('<span class="fileuploadname" />').text( (folder ? folder+'/':'')+file.name))
			.append( $('<div class="progress_track"><div class="progress"></div></div>')  )
			.append( $('<span class="size" />').text(formatFileSize(file.size)) )
	}
	function renderFileSizeErrorRow(file,folder) {
		return $('<div class="error" />')
			.append( $('<span class="fileuploadname" />').text( 'Error: ' + (folder ? folder+'/':'')+file.name))
			.append( $('<span/>').html(' file size - <b>' + formatFileSize(file.size) + '</b>'
				+' exceeds max upload size of <b>' + formatFileSize(MAX_UPLOAD_SIZE) + '</b>')  );
	}
<?php endif; ?>
	function list() {
		var hashVal = window.location.hash.substr(1);
		$.get('?',{'do':'list','file':hashVal},function(data) {
			$tbody.empty();
			$('#breadcrumb').empty().html(renderBreadcrumbs(hashVal));
			if(data.success) {
				$.each(data.results,function(k,v){
					$tbody.append(renderFileRow(v));
				});
				!data.results.length && $tbody.append('<tr><td class="empty" colspan=5>This folder is empty</td></tr>');
				var body = $('body');
				data.is_writable ? body.removeClass('no_write') : body.addClass('no_write');
			} else {
				console.warn(data.error.msg);
			}
			$('#table').reTableSort();
		},'json');
	}
	function renderFileRow(data) {
		var $link = $('<a class="name" />')
			.attr('href', data.is_dir ? '#' + data.path : './'+data.path)
			.text(data.name);
		var allow_direct_link = <?php echo $allow_direct_link?'true':'false'; ?>;
        	if (!data.is_dir && !allow_direct_link)  $link.css('pointer-events','none');
		var $dl_link = data.is_dir ? $('<span/>').addClass('download').text('download') : $('<a/>').attr('href','?do=download&file='+encodeURIComponent(data.path))
            .addClass('download').text('download');
		var $delete_link = $('<a href="#" />').attr('data-file',data.path).addClass('delete').text('delete');
        return $('<tr />')
			.addClass(data.is_dir ? 'is_dir' : '')
			.append( $('<td class="first" />').append($link) )
			.append( $('<td/>').attr('data-sort',data.is_dir ? -1 : data.size)
				.html($('<span class="size" />').text(formatFileSize(data.size))) ) 
			.append( $('<td/>').attr('data-sort',data.mtime).text(formatTimestamp(data.mtime)) )
            .append( $('<td/>').text(data.unix_owner) )
            .append( $('<td/>').text(data.unix_perms) )
			.append( $('<td/>').append($dl_link).append( data.is_deleteable ? $delete_link : '') );
	}
	function renderBreadcrumbs(path) {
	    var breadcrumbBase = <?php echo json_encode($breadcrumb_base) ?>;
        var breadcrumbBaseKeys = Object.keys(breadcrumbBase);
        var breadcrumbBaseRendered = '';
        for (let i = 0; i < breadcrumbBaseKeys.length; i++){
            breadcrumbBaseRendered += '<a href="' + breadcrumbBase[breadcrumbBaseKeys[i]] + '">' + breadcrumbBaseKeys[i] + '</a>';
            if (i + 1 < breadcrumbBaseKeys.length){
                breadcrumbBaseRendered += '<span> ▸ </span>';
            }
        }
		var base = "",
			$html = $('<div/>').append( $(breadcrumbBaseRendered + '</div>') );
		$.each(path.split('/'),function(k,v){
			if(v) {
				$html.append( $('<span/>').text(' ▸ ') )
					.append( $('<a/>').attr('href','#'+base+v).text(decodeURIComponent(v)) );
				base += v + '/';
			}
		});
		return $html;
	}
	function formatTimestamp(unix_timestamp) {
		var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		var d = new Date(unix_timestamp*1000);
		return [m[d.getMonth()],' ',d.getDate(),', ',d.getFullYear()," ",
			(d.getHours() % 12 || 12),":",(d.getMinutes() < 10 ? '0' : '')+d.getMinutes(),
			" ",d.getHours() >= 12 ? 'PM' : 'AM'].join('');
	}
	function formatFileSize(bytes) {
		var s = ['bytes', 'KB','MB','GB','TB','PB','EB'];
		for(var pos = 0;bytes >= 1000; pos++,bytes /= 1024);
		var d = Math.round(bytes*10);
		return pos ? [parseInt(d/10),".",d%10," ",s[pos]].join('') : bytes + ' bytes';
	}
})

</script>
</head><body>
<div id="top">
   <?php if($allow_upload == true): ?>
	<form action="?" method="post" id="mkdir">
		<label for=dirname>Create New Folder</label><input id=dirname type=text name=name value="">
		<input type="submit" value="create">
	</form>

   <?php endif; ?>

   <?php if($allow_upload == true): ?>

	<div id="file_drop_target">
		Drag Files Here To Upload (Max <span class="max-file-size"></span>)
		<b>or</b>
		<input type="file" multiple>
	</div>
   <?php endif; ?>
	<div id="breadcrumb">&nbsp;</div>
</div>

<div id="upload_progress"></div>
<table id="table"><thead><tr>
	<th>Name</th>
	<th>Size</th>
	<th>Modified</th>
    <th>Ownership</th>
	<th>Permissions</th>
	<th>Actions</th>
</tr></thead><tbody id="list">

</tbody></table>
<footer><a href="https://github.com/sonicer105/simple-php-file-manager" target="_blank">Simple PHP File Manager</a> (forked from <a href="https://github.com/thinkdj/simple-php-file-manager" target="_blank">thinkdj's Simple PHP File Manager</a>)</footer>
</body></html>
