<?php
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/db_connect.php';
header('Content-Type: application/json');

if(empty($_SESSION['user']['user_id'])){
  echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;
}
$user_id=$_SESSION['user']['user_id'];
if(!empty($_FILES['profile_pic']['name'])){
  $dir=__DIR__.'/../uploads/profiles/';
  if(!is_dir($dir))mkdir($dir,0777,true);
  $ext=strtolower(pathinfo($_FILES['profile_pic']['name'],PATHINFO_EXTENSION));
  $filename='user_'.$user_id.'.'.$ext;
  $path=$dir.$filename;
  if(move_uploaded_file($_FILES['profile_pic']['tmp_name'],$path)){
    $dbPath='uploads/profiles/'.$filename;
    $pdo->prepare("UPDATE users SET profile_photo_path=? WHERE user_id=?")->execute([$dbPath,$user_id]);
    $_SESSION['user']['profile_photo_path']=$dbPath;
    echo json_encode(['success'=>true,'path'=>'../'.$dbPath]);
  }else echo json_encode(['success'=>false,'message'=>'Upload failed!']);
}else echo json_encode(['success'=>false,'message'=>'No file selected']);
