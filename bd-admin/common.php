<?php
require('config.php');

function wlog($message, $level = 0) {
	global $mysql;
	if(getenv("HTTP_X_FORWARDED_FOR"))
		$ip = getenv("HTTP_X_FORWARDED_FOR");
	elseif(getenv("REMOTE_ADDR"))
		$ip = getenv("REMOTE_ADDR");
	$mysql->prepare('insert into log_new value (null,?,?,?)')->execute(array($ip,$level,$message));
}

function get_baidu_base_cookie() {
	global $base_cookie, $ua;
	if ($base_cookie) {
		return $base_cookie;
	}
	$rq = request('http://pan.baidu.com/', $ua, '');
	$base_cookie = $rq['header']['set-cookie'];
	return $base_cookie;
}

function request ($url, $ua=NULL, $cookie=NULL, $postData=NULL) {
	$hRequest = curl_init ($url);
	if (substr($url,0,5)=='https') {
        curl_setopt ($hRequest, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt ($hRequest, CURLOPT_SSL_VERIFYPEER, false);
    }
	if ($postData!==NULL) {
		curl_setopt ($hRequest, CURLOPT_POST, true);
		curl_setopt ($hRequest, CURLOPT_POSTFIELDS, $postData);
	}
	if ($ua!==NULL)
		curl_setopt ($hRequest, CURLOPT_USERAGENT, $ua);
	if ($cookie)
		curl_setopt ($hRequest, CURLOPT_COOKIE, $cookie);
	elseif ($cookie !== '')
		curl_setopt ($hRequest, CURLOPT_COOKIE, get_baidu_base_cookie());
	curl_setopt($hRequest, CURLOPT_HEADER, 1);
	curl_setopt($hRequest, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($hRequest, CURLOPT_MAXREDIRS, 3);

	curl_setopt($hRequest, CURLOPT_RETURNTRANSFER, true);
	$response = curl_exec($hRequest);
	$ret = array ('header' => array());
	$head_size = curl_getinfo($hRequest, CURLINFO_HEADER_SIZE);
	$body = substr($response, $head_size);
	$headerRaw = explode("\r\n", substr($response, 0, $head_size));
	array_shift($headerRaw);
	$ret['body'] = $body;

	foreach($headerRaw as $line) {
		$exp = explode(': ', $line, 2);
		if (count($exp) == 2)
			$ret['header'][strtolower($exp[0])] = $exp[1];
	}

	$ret['code'] = curl_getinfo($hRequest, CURLINFO_HTTP_CODE);
	$ret['real_url'] = curl_getinfo($hRequest, CURLINFO_EFFECTIVE_URL);
	$ret['error']=curl_error($hRequest);
	curl_close ($hRequest);
	return $ret;
}

function findBetween ($str, $begin, $end) {
	if (false === ($pos1 = strpos ($str, $begin))
		||  false === ($pos2 = strpos($str, $end, $pos1 + 1))
	) return false;

	return substr($str, $pos1 + strlen($begin), $pos2 - $pos1 - strlen($begin));
}

function array_find($needle, $haystack,$reverse=false)
{
   foreach ($haystack as $item)
   {
      if (!$reverse && strpos($item, $needle) !== FALSE)
      {
         return $item;
         break;
      }
      elseif ($reverse && strpos($needle, $item) !== FALSE)
      {
         return $item;
         break;
      }
   }
   return false;
}

function alert_error($error,$return) {
	global $head;
	if (!$head) {
		print_header('');
	}
	if (!$return) {
		echo "<script>alert('$error');window.close();</script></body></html>";
	} else {
		echo "<script>alert('$error');window.location.href='$return';</script></body></html>";
	}
	die();
}

$head=false;
function print_header($title) { ?>
<!DOCTYPE HTML>
<html>
<head>
<meta charset="utf-8" />
<title><?php echo $title; ?></title>
</head>
<body>
<?php global $head;
	$head=true;
}

function getBaiduToken($cookie,$username) {
	global $ua;
	$token=request('http://pan.baidu.com/disk/home',$ua,$cookie);
	$bdstoken=findBetween($token['body'], '"bdstoken":"', '",');
	if(strlen($bdstoken)<10) {
		alert_error('cookie失效，或者百度封了IP！','switch_user.php?name='.$username);
	}
	return $bdstoken;
}

function getBaiduFileList($folder,$token,$cookie) {
	global $ua;
	$list=array();
	$page=1;
	$size=-1;
	while($size) {
		$ret=request("http://pan.baidu.com/api/list?channel=chunlei&clienttype=0&web=1&num=1000&page=$page&dir=$folder&order=time&desc=1&showempty=0&bdstoken=$token&channel=chunlei&clienttype=0&web=1&app_id=250528",$ua,$cookie);
		$ret=json_decode($ret['body'],true);
		if(!isset($ret['list']))
			return array();
		$size=count($ret['list']);
		$page++;
		foreach($ret['list'] as $k=>$v) {
			$list[]=array('fid'=>number_format($v['fs_id'],0,'',''),'name'=>$v['path'],'isdir'=>$v['isdir']);
		}
	}
	return $list;
}

function createShare($fid,$code,$token,$cookie,$return=false) {
	global $ua;
	if(strlen($code)!=4) //我看你还抽不
		$post="fid_list=%5B$fid%5D&schannel=0&channel_list=%5B%5D";
	else
		$post="fid_list=%5B$fid%5D&schannel=4&channel_list=%5B%5D&pwd=$code";
	$ret=request("http://pan.baidu.com/share/set?channel=chunlei&clienttype=0&web=1&bdstoken=$token&channel=chunlei&clienttype=0&web=1&app_id=250528",$ua,$cookie,$post);
	$ret=json_decode($ret['body']);
	if($return!==false) {
		if($ret->errno) {
			alert_error('分享失败',$return);
			die();
		}
		echo '<p>分享创建成功。<br />分享地址为：'.$ret->link.'<br />短地址为：'.$ret->shorturl.'<br />提取码为：'.$code.'</p>';
	} elseif($ret->errno || !isset($ret->shorturl) || !$ret->shorturl) {
		wlog('分享失败：'.print_r($ret,true), 2);
		return false;
	}
	return $ret->shorturl;
}

function refresh_watchlist() {
	global $mysql;
	if(isset($_SESSION['list'])) unset($_SESSION['list']);
	if(isset($_SESSION['list_filenames'])) unset($_SESSION['list_filenames']);
	$list=$mysql->query('select watchlist.* from watchlist left join users on watchlist.user_id=users.ID where watchlist.user_id='.$_SESSION['user_id'])->fetchAll();
	foreach($list as $k=>$v) {
		$_SESSION['list'][$v[1]]=array('id'=>$v[0],'filename'=>$v[2],'link'=>$v[3]);
		$_SESSION['list_filenames'][$v[1]]=$v[2];
	}
}

function set_cookie($cookie,$set_cookie) {
	return implode('; ',array_merge(explode('; ',$cookie),explode('; ',$set_cookie)));
}

//百度贴吧客户端登录
function baidu_login($username,$password,$codestring='',$captcha='') {
	global $ua;
	$cookie = get_baidu_base_cookie();
	$post=array('isphone'=>'0','passwd'=>base64_encode($password),'un'=>$username,'vcode'=>$captcha,'vcode_md5'=>$codestring,'from'=>'baidu_appstore','stErrorNums'=>'0','stMethod'=>'1','stMode'=>'1','stSize'=>mt_rand(50,2000),'stTime'=>mt_rand(50,500),'stTimesNum'=>'0','timestamp'=>(time()*1000),'_client_id'=>'wappc_138'.mt_rand(1000000000,9999999999).'_'.mt_rand(100,999),'_client_type'=>'1','_client_version'=>'6.0.1','_phone_imei'=>md5(mt_rand()),'cuid'=>strtoupper(md5(mt_rand())).'|'.substr(md5(mt_rand()),1),'model'=>'M1');
	ksort($post);
	$sign='';
	foreach($post as $k=>$v) {
		$sign.=$k.'='.$v;
	}
	$rq=request('http://c.tieba.baidu.com/c/s/login','BaiduTieba for Android 6.0.1',null,http_build_query($post).'&sign='.strtoupper(md5($sign.'tiebaclient!!!')));
	$result=json_decode($rq['body']);
	$ret['errno']=$result->error_code;
	if($ret['errno']==0){
		$ret['bduss']=substr($result->user->BDUSS,0,strpos($result->user->BDUSS,'|'));
		$ret['cookie']=$cookie.';BDUSS='.$ret['bduss'];
	}
	else {
		if(isset($result->anti->need_vcode)) {
			$ret['code_string']=$result->anti->vcode_md5;
			$ret['captcha']=$result->anti->vcode_pic_url;
		}
		$ret['message']=$result->error_msg;
	}
	return $ret;
}

function getFileMeta($file, $token, $cookie) {
	global $ua;
	$post='target=%5B%22'.urlencode($file).'%22%5D';
	$ret=request("http://pan.baidu.com/api/filemetas?blocks=1&dlink=1&bdstoken=$token&channel=chunlei&clienttype=0&web=1&app_id=250528",$ua,$cookie,$post);
	$ret = json_decode($ret['body'], true);
	if ($ret['errno']) {
		wlog('文件 '.$file.' 获取分片列表失败：'.$ret['errno'], 2);
		return false;
	}
	return $ret;
}

function getDownloadLink($file, $token, $cookie) {
	global $ua;
	$ret=request("http://pcs.baidu.com/rest/2.0/pcs/file?method=locatedownload&bdstoken=$token&app_id=250528&path=".urlencode($file),$ua,$cookie);
	$ret = json_decode($ret['body'], true);
	if (isset($ret['errno'])) {
		wlog('文件 '.$file.' 获取下载地址失败：'.$ret['errno'], 2);
		return false;
	}
	foreach($ret['server'] as &$v) {
		$v = 'http://' . $v . $ret['path'];
	}
	return $ret['server'];
}