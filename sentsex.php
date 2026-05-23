<?php

    //$vn = $_POST['vn'];
	$vn = $_POST['vn'];
	$hn = $_POST['hn'];
    $fullname = $_POST['fullname'];
    $cid = $_POST['cid'];
    $order_date = $_POST['order_date'];
    $lab_items_name_ref = $_POST['lab_items_name_ref'];
	$lab_order_result = $_POST['lab_order_result'];

	// $sToken = "X9A2QtqkzzWkyxFkEjH9QKo2EreXD6t2Cs5SI1aiaK7"; //กลุ่มไลน์จิตเวช
	$sToken = "mFOoilRo2AcUfi04z2Z6z5QKibxKXRYcH6zYsYrz84w"; 
	
	$sMessage = "รายการคนไข้ที่มารับบริการ\n";
	$sMessage .= "----------------------------" . "\n";
    $sMessage .= "เลข VN:".' '. $vn . " \n";
	$sMessage .= "เลขHN:".' '. $hn . " \n";
	$sMessage .= "----------------------------" . "\n";
    $sMessage .= "ชื่อ-สกุล:".' '.$fullname . " \n" ;
    $sMessage .= "เลขบัตรประชาชน:".$cid . " \n";
	$sMessage .= "----------------------------" . "\n";
    $sMessage .= "วันที่สั่ง LAB:".$order_date . " \n";
    $sMessage .= "ชื่อรายการ LAB:"  .'  '.$lab_items_name_ref . " \n" ;
	$sMessage .= "ผลตรวจ:".' '.$lab_order_result . " \n";
	$sMessage .= "----------------------------" . "\n";

	$chOne = curl_init(); 
	curl_setopt( $chOne, CURLOPT_URL, "https://notify-api.line.me/api/notify"); 
	curl_setopt( $chOne, CURLOPT_SSL_VERIFYHOST, 0); 
	curl_setopt( $chOne, CURLOPT_SSL_VERIFYPEER, 0); 
	curl_setopt( $chOne, CURLOPT_POST, 1); 
	curl_setopt( $chOne, CURLOPT_POSTFIELDS, "message=".$sMessage); 
	$headers = array( 'Content-type: application/x-www-form-urlencoded', 'Authorization: Bearer '.$sToken.'', );
	curl_setopt($chOne, CURLOPT_HTTPHEADER, $headers); 
	curl_setopt( $chOne, CURLOPT_RETURNTRANSFER, 1); 
	$result = curl_exec( $chOne ); 

	//Result error 
	if(curl_error($chOne)) 
	{ 
		echo 'error:' . curl_error($chOne); 
	} 
	else { 
		$result_ = json_decode($result, true); 
		echo "status : ".$result_['status']; echo "message : ". $result_['message'];
	} 
	curl_close( $chOne );   
?>