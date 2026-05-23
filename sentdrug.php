<?php

    //$vn = $_POST['vn'];
	$hn = $_POST['hn'];
    $pname = $_POST['pname'];
    $fullname = $_POST['fullname$fullname'];
    $lname = $_POST['lname'];
	$NAME = $_POST['NAME'];
	$vstdate = $_POST['vstdate'];
	$hpi = $_POST['hpi'];


	// $sToken = "X9A2QtqkzzWkyxFkEjH9QKo2EreXD6t2Cs5SI1aiaK7"; 
	$sToken = "mFOoilRo2AcUfi04z2Z6z5QKibxKXRYcH6zYsYrz84w";
	$sMessage = "รายการคนไข้ที่มารักษา\n";
	$sMessage .= "----------------------------" . "\n";
	$sMessage .= "เลขHN:".' '. $hn . " \n";
    $sMessage .= "ชื่อ-สกุล:".' '.$pname ;
    $sMessage .= "".$fullname;
    // $sMessage .= ""  .'  '.$lname . " \n" ;
	// $sMessage .= "ชื่อยา:".' '.$NAME . " \n";
    $sMessage .= "วันที่มารับบริการ:".' '. $vstdate . " \n";
	$sMessage .= "hpi:".' '.$hpi . " \n";
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