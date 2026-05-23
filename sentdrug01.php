<?php

    //$vn = $_POST['vn'];
	$vstdate = $_POST['vstdate'];
	$hn = $_POST['hn'];
    $fullname = $_POST['fullname'];
	$name = $_POST['name'];
	$Statusdep = $_POST['Statusdep'];
	// สร้างข้อความ JSON
	$data = [
		"messages" => [
			[
				"type" => "flex",
				"altText" => "ข้อมูลผู้ป่วยจากโรงพยาบาลเชียงกลาง",
				"contents" => [
					"type" => "bubble",
					"size" => "mega",
					"header" => [
						"type" => "box",
						"layout" => "vertical",
						"contents" => [
							[
								"type" => "image",
								"url" => "https://www.ckhospital.net/home/PDF/moph-flex-header-2.jpg",
								"size" => "full",
								"aspectRatio" => "3120:885",
								"aspectMode" => "cover"
							]
						],
						"paddingAll" => "0px"
					],
					"body" => [
						"type" => "box",
						"layout" => "vertical",
						"contents" => [
							[
								"type" => "box",
								"layout" => "vertical",
								"margin" => "8px",
								"contents" => [
									[
										"type" => "image",
										"url" => "https://www.ckhospital.net/home/PDF/Logo_ck.png",
										"size" => "full",
										"aspectMode" => "cover",
										"align" => "center"
									]
								],
								"cornerRadius" => "100px",
								"maxWidth" => "72px",
								"offsetStart" => "93px"
							],
							[
								"type" => "box",
								"layout" => "vertical",
								"margin" => "sm",
								"contents" => [
									[
										"type" => "text",
										"text" => "-------------------------------------",
										"weight" => "regular",
										"size" => "14px",
										"align" => "center"
									]
								]
							],
							[
								"type" => "box",
								"layout" => "vertical",
								"cornerRadius" => "15px",
								"margin" => "xs",
								"paddingTop" => "lg",
								"paddingBottom" => "lg",
								"paddingStart" => "8px",
								"paddingEnd" => "8px",
								"backgroundColor" => "#DCE7FF",
								"contents" => [
									[
										"type" => "text",
										"text" => "ผู้ป่วยจากโรงพยาบาลเชียงกลาง",
										"weight" => "bold",
										"size" => "lg",
										"align" => "center",
										"color" => "#2D2D2D",
										"adjustMode" => "shrink-to-fit"
									]
								]
							],
							makeTextBox("คนไข้: " . $fullname),
							makeTextBox("HN: " . $hn),
							makeTextBox("วันที่มารับบริการ: " . $vstdate),
							makeTextBox("ชื่อยา: " . $name),
							makeTextBox("สถานะ: " . $Statusdep),
							// makeTextBox("เลขตำแหน่ง:" .$_COOKIE),
							// makeTextBox("วันที่มารับบริการ: " .$vstdate),  
							[
								"type" => "box",
								"layout" => "vertical",
								"margin" => "sm",
								"contents" => [
									[
										"type" => "text",
										"text" => "-------------------------------------",
										"weight" => "regular",
										"size" => "14px",
										"align" => "center"
									]
								]
							],
							
							// [
							//     "type" => "box",
							//     "layout" => "vertical",
							//     "margin" => "sm",
							//     "contents" => [
							//         [
							//             "type" => "text",
							//             "text" => "-------------------------------------",
							//             "weight" => "regular",
							//             "size" => "14px",
							//             "align" => "center"
							//         ]
							//     ]
							// ],
							[
								"type" => "box",
								"layout" => "vertical",
								"margin" => "sm",
								"contents" => [
									[
										"type" => "text",
										"text" => "โรงพยาบาลเชียงกลาง",
										"weight" => "bold",
										"size" => "14px",
										"align" => "center"
									]
								]
							]
						]
					]
				]
			]
		]
	];
	
	// ฟังก์ชันสร้างกล่องข้อความ
	function makeTextBox($text) {
		return [
			"type" => "box",
			"layout" => "vertical",
			"margin" => "8px",
			"contents" => [
				[
					"type" => "text",
					"text" => $text,
					"size" => "16.5px",
					"align" => "center",
					"gravity" => "center",
					"wrap" => true,
					"adjustMode" => "shrink-to-fit"
				]
			]
		];
	}
	
	// เริ่ม CURL ส่งออกไป
	$curl = curl_init();
	
	curl_setopt_array($curl, [
		CURLOPT_URL => 'https://morpromt2f.moph.go.th/api/notify/send?messages=yes',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
		CURLOPT_HTTPHEADER => [
			'client-key: bb233f599e3aa646293cab2da71b30272d2db42c',
			'secret-key: ZBX47IIH5OU3WAQV7R7KAKGQJ6JI',
	
		// --------------------- Token ไลน์ทดสอบแจ้งเตือน --------------------- //
			// 'client-key: 5f9f001dbabc7794ebbe5769a02dfc636782e1f2',
			// 'secret-key: YLNQE2A65PEIZQXA72JMQ7CQEDYY',
			
	
			'Content-Type: application/json'
		],
	]);
	
	$response = curl_exec($curl);
	curl_close($curl);
	
	echo $response;
	
	?>
	