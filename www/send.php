<?php 

$tomail="it@potencial-group.ru,ucentr.pro@gmail.com"; // сюда вписать свой емайл, вместо  
$Subject = 'Новое сообщение с сайта '.$_SERVER['HTTP_HOST'];


$from = 'info@'.$_SERVER['HTTP_HOST'];
$msg='';
    if((@$_SERVER['REQUEST_METHOD'] == 'POST') && ( count($_POST)>0)) {

if (!empty($_POST["CourseName"])) $msg.= 'Форма: '.htmlspecialchars($_POST["CourseName"])."\r\n<BR>";
if (!empty(trim($_POST["Name"]))) $msg.= 'Имя: '.htmlspecialchars($_POST["Name"])."\r\n<BR>";
if (!empty(trim($_POST["Email"]))) $msg.= 'Почта: '.htmlspecialchars($_POST["Email"])."\r\n<BR>";
if (!empty(trim($_POST["tildaspec-referer"]))) $msg.= 'С сайта: '.htmlspecialchars($_POST["tildaspec-referer"])."\r\n<BR>";
if (!empty(trim($_POST["Phone"]))) $msg.= 'Телефон: '.htmlspecialchars($_POST["Phone"])."\r\n<BR>";



$msg .= '=================='."\n<BR>";

$header  = "Content-type: text/html; charset=utf-8\r\n";
$header .= "From: {$from}" . "\r\n";  
$header .= 'X-Mailer: PHP v'.phpversion()."\r\n";
   
$Subject = "=?UTF-8?B?".base64_encode($Subject)."?=";	  


$msg = wordwrap($msg, 70, "\r\n");


	if(strlen($msg)>9){	
    if (!mail("$tomail", "$Subject", "$msg", "$header","-f$from")) {echo '{"message":"ERROR mail()!";}';die;}
	}
    }
   
echo '{"message":"OK","results":["236736:2605225"]}';
