<?php
ini_set('display_errors','Off');
require 'common.php';
try {
	$mysql=new PDO("mysql:host=$host;dbname=$db",$user,$pass);
}catch(PDOException $e) {
	print_header('出错了！');
	echo '<h1>错误：无法连接数据库</h1>';
}
$mysql->query('set names utf8');
session_start();
if(isset($_POST['cookie'])) {
	if(!isset($_SESSION['user_id']))
		alert_error('没选择用户','switch_user.php');
	$mysql->prepare('update users set cookie=? where ID=?')->execute(array($_POST['cookie'],$_SESSION['user_id']));
	$_SESSION['cookie']=$_POST['cookie'];
	header('Location: browse.php');
	die();
}
elseif(!isset($_SESSION['user_id'])) {
	header('Location: switch_user.php');
	die();
}
elseif(isset($_GET['switch_dir'])) {
	$_SESSION['folder'][]=urldecode($_GET['switch_dir']);
	header('Location: browse.php');
	die();
}
elseif(isset($_GET['goup'])) {
	array_pop($_SESSION['folder']);
	header('Location: browse.php');
	die();
}
print_header('添加文件');
if(!isset($_SESSION['folder']) || empty($_SESSION['folder']))
	$_SESSION['folder']=array('/');
?><h1>当前用户：<?=$_SESSION['username']?> <a href="switch_user.php">切换</a></h1>
<h2>当前路径：<?=end($_SESSION['folder'])?></h2><p>注意：本程序无法检测到全部可能导致出问题的情况。请在主页中查看全部补档记录的可用性。</p><table border="1"><tr><th>补档</th><th>工具</th><th>文件名</th><th>fs_id</th><th>状态</th><th>访问地址</th><th>分享地址</th></tr>
<?php if(count($_SESSION['folder'])!=1) {
	echo '<tr><td colspan="7"><a href="browse.php?goup=1">[返回上层文件夹]</a></tr>';
}
$filelist=getBaiduFileList(end($_SESSION['folder']),$_SESSION['bds_token'],$_SESSION['cookie']);
refresh_watchlist();
if(!isset($_SESSION['list'])) {
	$_SESSION['list']=array();
}
$table='';

$fix=$mysql->prepare('update watchlist set name=? where fid=? and user_id=?');

foreach($filelist as &$v) {
	if(isset($_SESSION['list'][$v['fid']])) {
		if($_SESSION['list'][$v['fid']]['filename']!=$v['name']) {
			$fix->execute(array($v['name'],$v['fid'],$_SESSION['user_id']));
			$check_result='<td><font color="orange">数据库中的文件名错误，已经被自动修正。</font></td>';
		} else {
			$check_result='<td><font color="green">自动补档保护中</font></td>';
		}
		$_SESSION['filecheck'][$v['fid']]=false;
		$check_result.='<td><a href="'. $jumper.$_SESSION['list'][$v['fid']]['id'].'" target="_blank">'. $jumper.$_SESSION['list'][$v['fid']]['id'].'</a></td><td><a href="http://pan.baidu.com'.$_SESSION['list'][$v['fid']]['link'].'">http://pan.baidu.com'.$_SESSION['list'][$v['fid']]['link'].'</a></td>';
		unset($_SESSION['list'][$v['fid']],$_SESSION['list_filenames'][$v['fid']]);
	} else {
		if(array_find($v['name'].'/',$_SESSION['list_filenames'])!==false) {
			$check_result='<td colspan="3"><font color="blue">文件夹内的文件被加入自动补档</font></td>';
			$_SESSION['filecheck'][$v['fid']]=false;
		} elseif(array_find($v['name'],$_SESSION['list_filenames'],true)!==false) {
			$check_result='<td colspan="3"><font color="blue">父文件夹被加入自动补档</font></td>';
			$_SESSION['filecheck'][$v['fid']]=false;
		} else {
			$check_result='<td colspan="3">本文件未加入自动补档</td>';
			$_SESSION['filecheck'][$v['fid']]=true;
		}
	}
	if($_SESSION['filecheck'][$v['fid']]) : ?>
	<tr><td><form method="post" action="add.php"><input type="hidden" name="fid" value="<?=$v['fid']?>" /><input type="hidden" name="filename" value="<?=$v['name']?>" /><input type="submit" name="submit" value="添加" /></form></td>
	<?php else : ?>
	<tr><td><input type="button" disabled="disabled" value="已添加" /></td>
<?php endif;
	if($v['isdir']) : ?>
	<td><a href="tools/share.php?<?=$v['fid']?>" target="_blank">自定义分享</a></td><td><a href="browse.php?switch_dir=<?=urlencode($v['name'].'/') ?>"><?=substr($v['name'],strlen(end($_SESSION['folder']))) ?>(文件夹)</a></td>
	<?php else : ?>
	<td><a href="tools/dl.php?<?=rawurlencode($v['name'])?>" target="_blank">下载</a>&nbsp;&nbsp;<a href="tools/share.php?<?=$v['fid']?>" target="_blank">自定义分享</a></td><td><?=substr($v['name'],strlen(end($_SESSION['folder']))) ?></td>
	<?php endif; ?>
	<td><?=$v['fid']?></td><?=$check_result?></tr>
<?php } ?>
</table>
</body></html>
