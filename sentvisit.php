<?php

    $fullname = $_POST['fullname'];
    $vn = $_POST['vn'];
    $hn = $_POST['hn'];
	$vstdate = $_POST['vstdate'];
	$vsttime = $_POST['vsttime'];
    $hpi = $_POST['hpi'];

	// $sToken = "GUV1AjjMVsz0QqxqCz5fJLI54Q2sh5CX1mYOzA3rZkT "; // Token Line หมอชิต
    $sToken = "mFOoilRo2AcUfi04z2Z6z5QKibxKXRYcH6zYsYrz84w"; //Line Group สำหรับทดสอบระบบ
    $sMessage = "รายการผู้มารับบริการวันนี้ \n";
	$sMessage .= "ชื่อ - สกุล:".' '. $fullname . " \n";
	// $sMessage .= "เลขVN:".' '. $vn . " \n";
    $sMessage .= "เลขHN:".' '.$hn  . " \n";
	$sMessage .= "วันที่มารับบริการ:".' '.$vstdate  . " \n";
	// $sMessage .= "เวลาที่มารับบริการ:".' '.$vsttime  . " \n";
    $sMessage .= "HPI:".' '. $hpi . " \n";
	
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