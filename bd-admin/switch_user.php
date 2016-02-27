<?php require 'common.php';
try {
	$mysql=new PDO("mysql:host=$host;dbname=$db",$user,$pass);
}catch(PDOException $e) {
	print_header('出错了！');
	echo '<h1>错误：无法连接数据库</h1>';
}
$mysql->query('set names utf8');
session_start();
if(isset($_GET['switch_user'])) {
	if(!is_numeric($_GET['switch_user']))
		alert_error('用户ID错误','switch_user.php');
	$user=$mysql->query('select * from users where ID='.$_GET['switch_user'])->fetch();
	if(empty($user))
		alert_error('找不到用户','switch_user.php');
	unset($_SESSION['filecheck'],$_SESSION['folder'],$_SESSION['list'],$_SESSION['list_filenames']);
	$_SESSION['user_id']=$user['ID'];
	$_SESSION['username']=$user['username'];
	$_SESSION['cookie']=$user['cookie'];
	$_SESSION['md5']=$user['newmd5']!=='';
	$_SESSION['bds_token']=getBaiduToken($user['cookie'],$user['username']);
	unset($_SESSION['folder']);
	wlog('切换用户：['.$user['ID'].']'.$user['username']);
	header('Location: browse.php');
	die();
}
elseif(!isset($_GET['add_user']) && (isset($_POST['password']) || isset($_GET['remove_user']))) {
	if(isset($_GET['remove_user'])) {
		wlog('请求删除用户['.$_GET['remove_user'].']', 1);
	}
	if(isset($_POST['password'])) {
		if(isset($_POST['code_string']))
			$result=baidu_login($_POST['name'],$_POST['password'],$_POST['code_string'],$_POST['captcha']);
		else
			$result=baidu_login($_POST['name'],$_POST['password']);
		if(!$result['errno']) {
			$mysql->query('delete from users where id='.$_POST['ID']);
			$mysql->query('delete from watchlist where user_id='.$_POST['ID']);
			wlog('删除用户成功：['.$_POST['ID'].']'.$_POST['name'], 1);
			alert_error('用户【'.$_POST['name'].'】删除成功！','switch_user.php');
			die();
		}
		if($result['errno']==2) {
			echo '<h1>密码错误</h1>';
			wlog('删除用户失败，密码错误：['.$_POST['ID'].']'.$_POST['name'], 1);
		}
		elseif($result['errno']==5) echo '<h1>请输入验证码</h1>';
		else {
			echo '<h1>错误编号：'.$result['errno'].'</h1>';
			wlog('删除用户失败，错误代码'.$result['errno'].'：['.$_POST['ID'].']'.$_POST['name'], 1);
		}
		$_GET['remove_user']=$_POST['ID'];
	}
	if(!is_numeric($_GET['remove_user']))
		alert_error('用户ID错误','switch_user.php');
	$user=$mysql->query('select * from users where ID='.$_GET['remove_user'])->fetch();
	if(empty($user))
		alert_error('找不到用户','switch_user.php');
	print_header('确认删除');?>
	<h1>确定要删除用户 <?=$user['username']?> 吗？<br />警告：删除用户将同时删除此用户的全部补档记录。</h1>
	<p>您在进行风险操作，请输入 <?=$user['username']?> 的【百度密码】进行确认：</p>
	<form method="post" action="switch_user.php">
	<input type="hidden" name="ID" value="<?=$_GET['remove_user']?>" />
	<input type="hidden" name="name" value="<?=$user['username']?>" />
	<input type="password" name="password" /><br />
	<?php if(isset($result) && isset($result['code_string'])) { ?>
	验证码：<input type="text" name="captcha" /><img src="<?php echo $result['captcha']; ?>" /><br />
	<input type="hidden" name="code_string" value="<?php echo $result['code_string']; ?>" />
	<?php } ?>
	<input type="submit" value="确认删除" name="confirmdelete" />
	</form>
	<a href="switch_user.php">取消</a><body></html>
	<?php
	die();
}

elseif(isset($_GET['add_user'])) {
	print_header('添加用户');
	if(isset($_POST['create_user'])) {
		if(!isset($_POST['name']) || $_POST['name']=='')
			echo '<h1>错误：请输入用户名</h1>';
		elseif(!isset($_POST['password']) || $_POST['password']=='')
			echo '<h1>错误：请输入密码</h1>';
		else {
			if(isset($_POST['code_string']))
				$result=baidu_login($_POST['name'],$_POST['password'],$_POST['code_string'],$_POST['captcha']);
			else
				$result=baidu_login($_POST['name'],$_POST['password']);
			if(!$result['errno']) {
				$mysql->prepare('insert into users values (null,?,?,?,"") on duplicate key update cookie=?, bduss=?')->execute(array($_POST['name'],$result['cookie'],$result['bduss'],$result['cookie'],$result['bduss']));
				wlog('添加用户：'.$_POST['name']);
				alert_error('用户【'.$_POST['name'].'】添加成功！','switch_user.php');
			} if($result['errno']==2) echo '<h1>密码错误</h1>';
			elseif($result['errno']==5) echo '<h1>请输入验证码</h1>';
			else echo '<h1>错误编号：'.$result['errno'].'</h1>';
		}
	}
?><h1>添加用户</h1>
<h2>注意：您的密码将被明文传输到本服务器。然后再从本服务器明文传输到百度服务器（因为用了贴吧客户端API，服务器到百度也没有RSA加密）。<br />建议建立补档专用的百度ID而非使用常用ID，且不要使用常用密码</h2>
<form method="post" action="switch_user.php?add_user=1">
用户名：<input type="text" name="name" value="<?php echo isset($_POST['name'])?$_POST['name']:(isset($_GET['name'])?$_GET['name']:''); ?>"/><br />
密码：<input type="password" name="password" /><br />
<?php if(isset($result['code_string'])) { ?>
验证码：<input type="text" name="captcha" /><img src="<?php echo $result['captcha']; ?>" /><br />
<input type="hidden" name="code_string" value="<?php echo $result['code_string']; ?>" />
<?php } ?>
<input type="submit" name="create_user" value="登录" />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="switch_user.php">返回</a>
</form></body></html>
<?php
	die();
}
$users=$mysql->query('select * from users')->fetchAll();
print_header('选择用户');
echo '<h2>选择百度用户：</h2>';
foreach($users as $k=>$v) {
	echo '<a href="switch_user.php?switch_user='.$v['ID'].'">'.$v['username'].'</a>（<a href="switch_user.php?remove_user='.$v['ID'].'">删除</a>）<br />';
}
echo '<br /><a href="switch_user.php?add_user=1">添加用户/修复失效cookie</a>';
echo '</body></html>';
