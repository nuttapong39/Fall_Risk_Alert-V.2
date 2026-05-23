<?php

    $hn = $_POST['hn'];
	$fullname = $_POST['fullname'];
	$age = $_POST['age'];
    $cid = $_POST['cid'];
	$informaddr = $_POST['informaddr'];
    $hometel = $_POST['hometel'];
    $vstdate = $_POST['vstdate'];
	$doctor = $_POST['doctor'];
	$disease = $_POST['disease'];
	$icd10 = $_POST['icd10'];
	$result = $_POST['result'];



	 $sToken = "mFOoilRo2AcUfi04z2Z6z5QKibxKXRYcH6zYsYrz84w"; // สำหรับทดสอบระบบ
	// $sToken = "WI5O3nrUk5s8170cD9y4XqMkZOSA2Yu2F78YkzOwqbX"; // Token นุชวรา มะจินะ
	$sMessage = "รายการคนไข้โรค ScrubTyPhus \n";
	$sMessage .= "----------------------------" . "\n";
    $sMessage .= "HN: ".' '. $hn . " \n";
	$sMessage .= "ชื่อ-สกุล: ".' '. $fullname . " \n";
	$sMessage .= "อายุ: ".' '. $age . " \n";
    $sMessage .= "เลขบัตรประชาชน: "  .'  '.$cid . " \n" ;
    $sMessage .= "ที่อยู่: ".' '.$informaddr. " \n" ;
    $sMessage .= "เบอร์โทรติดต่อ: ".$hometel. " \n";
	$sMessage .= "วันที่รับบริการ: ".' '.$vstdate . " \n";
	$sMessage .= "แพทย์ผู้ตรวจ: ".' '.$doctor . " \n";
	$sMessage .= "ชื่อโรค: ".' '.$disease . " \n";
	$sMessage .= "ICD10: ".' '.$icd10 . " \n";
	$sMessage .= "ผลตรวจ: ".' '.$result . " \n";
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