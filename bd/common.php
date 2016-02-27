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


function getBaiduToken($cookie,$username) {
	global $ua;
	$token=request('http://pan.baidu.com/disk/home',$ua,$cookie);
	$bdstoken=findBetween($token['body'], '"bdstoken":"', '",');
	if(strlen($bdstoken)<10) {
		return false;
	}
	return $bdstoken;
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

function check_share($id, $link, $name, $cookie) {
	global $ua, $mysql;
	if(!$link || $link  == '/s/fakelink') {
		$url='';
		$ret['conn_valid']=true;
		$ret['user_valid']=true;
		$ret['valid']=false;
	} else {
		$url='http://pan.baidu.com'.$link;
		$check=request($url,$ua,$cookie);
		if(strpos($check['body'],'你所访问的页面不存在了。')) {
			$ret['conn_valid']=false;
		}else if(strpos($check['body'],'涉及侵权、色情、反动、低俗')===false && strpos($check['body'],'分享的文件已经被')===false && $link) {
			$ret['conn_valid']=true;
			$ret['user_valid']=true;
			$ret['valid']=true;
			$context=json_decode(findBetween($check['body'],'var _context = ',';'),true);
			$current_path=$context['file_list']['list'][0]['path'];
			if($current_path!=$name && $context) { //自动修复错误的路径
				$mysql->prepare('update watchlist set name=? where id=?')->execute(array($current_path,$id));
				$name=$current_path;
			}
		}elseif(strpos($check['body'],'加密分享了文件')!==false) {
			$ret['conn_valid']=true;
			$ret['user_valid']=false;
		} else {
			$ret['conn_valid']=true;
			$ret['user_valid']=true;
			$ret['valid']=false;
		}
	}
	$ret['url']=$url;
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
	global $ua, $mysql;
	$ret=request("http://pcs.baidu.com/rest/2.0/pcs/file?method=locatedownload&bdstoken=$token&app_id=250528&path=".urlencode($file),$ua,$cookie);
	$ret = json_decode($ret['body'], true);
	if (isset($ret['errno'])) {
		wlog('文件 '.$file.' 获取下载地址失败：'.$ret['errno'], 2);
		return false;
	}
	if (strpos($ret['path'], 'wenxintishi') !== false) {
		$mysql->exec('update watchlist set failed=2 where id='.$_SERVER['QUERY_STRING']);
		wlog('记录ID '.$_SERVER['QUERY_STRING'].'被温馨提示');
		return false;
	}
	foreach($ret['server'] as &$v) {
		$v = 'http://' . $v . $ret['path'];
	}
	return $ret['server'];
}